<?php

namespace App\Services;

use App\Enums\DatasetStatus;
use App\Models\AuditLog;
use App\Models\ColumnMetadata;
use App\Models\DataContract;
use App\Models\DataLineage;
use App\Models\Dataset;
use App\Models\DatasetVersion;
use App\Models\User;

/**
 * Data catalog: dataset versioning, column registry, lineage recording,
 * schema-drift detection, contracts/freshness and the health roll-up.
 *
 * Lineage recording is hooked into the existing ingestion flow WITHOUT
 * touching existing files: after a successful commit, master calls
 * `DataLineage::recordImportLineage($dataset, (int) $dataset->import_job_id)`
 * (see that method's docblock for the exact one-liner).
 */
class CatalogService
{
    /**
     * Snapshot the dataset's current schema as a new version row (1, 2, ...).
     *
     * @param  list<array<string, mixed>>|null  $schema
     */
    public function registerVersion(Dataset $dataset, ?array $schema = null, ?int $rowCount = null, ?string $notes = null, ?User $createdBy = null): DatasetVersion
    {
        $snapshot = array_values($schema ?? (array) $dataset->columns ?? []);

        $version = DatasetVersion::create([
            'dataset_id' => $dataset->getKey(),
            'version' => DatasetVersion::nextVersionNumber($dataset),
            'schema_snapshot' => $snapshot,
            'schema_hash' => self::hashSchema($snapshot),
            'row_count' => $rowCount ?? (int) $dataset->row_count,
            'created_by' => $createdBy?->getKey() ?? $dataset->user_id,
            'notes' => $notes,
        ]);

        $this->syncColumnMetadata($dataset, $snapshot);

        AuditLog::record('catalog.version_registered', 'dataset', $dataset->getKey(), [
            'version' => $version->version,
            'schema_hash' => $version->schema_hash,
            'row_count' => $version->row_count,
        ]);

        return $version;
    }

    /**
     * Upsert the column registry from a preview-style column list. Statistics
     * are refreshed; steward annotations (business_description, sensitivity,
     * is_pii) already stored are preserved.
     *
     * @param  list<array<string, mixed>>|null  $columns
     */
    public function syncColumnMetadata(Dataset $dataset, ?array $columns = null): void
    {
        $columns ??= (array) $dataset->columns ?? [];

        foreach ($columns as $column) {
            if (! is_array($column) || ! isset($column['name']) || trim((string) $column['name']) === '') {
                continue;
            }

            $name = trim((string) $column['name']);
            $rowCount = max((int) $dataset->row_count, 0);
            $missing = (int) ($column['missing'] ?? 0);

            ColumnMetadata::query()->updateOrCreate(
                ['dataset_id' => $dataset->getKey(), 'name' => $name],
                [
                    'dtype' => (string) ($column['dtype'] ?? 'string'),
                    'nullable' => (bool) ($column['nullable'] ?? true),
                    'distinct_count' => isset($column['unique']) ? (int) $column['unique'] : null,
                    'null_pct' => array_key_exists('missing_pct', $column)
                        ? (float) $column['missing_pct']
                        : ($rowCount > 0 ? round($missing / $rowCount, 4) : null),
                    'min_value' => isset($column['min']) ? (string) $column['min'] : null,
                    'max_value' => isset($column['max']) ? (string) $column['max'] : null,
                ],
            );
        }
    }

    /**
     * Steward annotation for one registry column.
     *
     * @param  array{business_description?: ?string, sensitivity?: ?string, is_pii?: ?bool}  $annotation
     */
    public function annotateColumn(Dataset $dataset, string $name, array $annotation): ColumnMetadata
    {
        $column = ColumnMetadata::query()
            ->where('dataset_id', $dataset->getKey())
            ->where('name', $name)
            ->firstOrFail();

        $column->fill(array_filter([
            'business_description' => $annotation['business_description'] ?? null,
            'sensitivity' => $annotation['sensitivity'] ?? null,
            'is_pii' => $annotation['is_pii'] ?? null,
        ], static fn ($value): bool => $value !== null))->save();

        AuditLog::record('catalog.column_annotated', 'dataset', $dataset->getKey(), [
            'column' => $name,
            'annotation' => $column->only(['business_description', 'sensitivity', 'is_pii']),
        ]);

        return $column->refresh();
    }

    /**
     * Record a lineage edge and audit it.
     *
     * @param  array{source_type: string, source_id: string, target_type: string, target_id: string, transform?: ?string, run_reference?: ?string}  $attributes
     */
    public function recordLineage(array $attributes): DataLineage
    {
        $edge = DataLineage::recordTransformation(
            (string) $attributes['source_type'],
            (string) $attributes['source_id'],
            (string) $attributes['target_type'],
            (string) $attributes['target_id'],
            $attributes['transform'] ?? null,
            $attributes['run_reference'] ?? null,
        );

        AuditLog::record('catalog.lineage_recorded', 'lineage', $edge->getKey(), [
            'source' => $edge->source_type.':'.$edge->source_id,
            'target' => $edge->target_type.':'.$edge->target_id,
            'transform' => $edge->transform,
        ]);

        return $edge;
    }

    /**
     * Create or replace the dataset's contract (one active contract per dataset).
     *
     * @param  array{owner: string, schema_hash?: ?string, freshness_sla_hours?: int, quality_threshold?: float, is_active?: bool}  $attributes
     */
    public function upsertContract(Dataset $dataset, array $attributes): DataContract
    {
        $contract = DataContract::query()->updateOrCreate(
            ['dataset_id' => $dataset->getKey()],
            [
                'owner' => (string) $attributes['owner'],
                'schema_hash' => $attributes['schema_hash'] ?? self::hashSchema((array) $dataset->columns ?? []),
                'freshness_sla_hours' => (int) ($attributes['freshness_sla_hours'] ?? 72),
                'quality_threshold' => (float) ($attributes['quality_threshold'] ?? config('ai_engine.quality_threshold', 0.75)),
                'is_active' => (bool) ($attributes['is_active'] ?? true),
            ],
        );

        AuditLog::record('catalog.contract_upserted', 'dataset', $dataset->getKey(), [
            'owner' => $contract->owner,
            'freshness_sla_hours' => $contract->freshness_sla_hours,
            'quality_threshold' => $contract->quality_threshold,
            'is_active' => $contract->is_active,
        ]);

        return $contract->refresh();
    }

    /**
     * Evaluate the active contract against the dataset: schema, freshness and
     * quality gates.
     *
     * @return array{has_contract: bool, meets_schema: ?bool, meets_freshness: ?bool, meets_quality: ?bool, passed: ?bool, details: array<string, mixed>}
     */
    public function checkContract(Dataset $dataset): array
    {
        $contract = DataContract::query()->where('dataset_id', $dataset->getKey())->active()->first();

        if (! $contract) {
            return [
                'has_contract' => false,
                'meets_schema' => null,
                'meets_freshness' => null,
                'meets_quality' => null,
                'passed' => null,
                'details' => ['message' => 'No active contract for this dataset.'],
            ];
        }

        $freshness = $this->freshnessStatus($dataset, $contract);
        $currentHash = self::hashSchema((array) $dataset->columns ?? []);
        $meetsSchema = $contract->schema_hash === null || hash_equals($contract->schema_hash, $currentHash);
        $meetsQuality = $dataset->quality_score === null || (float) $dataset->quality_score >= (float) $contract->quality_threshold;

        $passed = $meetsSchema && $freshness['fresh'] && $meetsQuality;

        return [
            'has_contract' => true,
            'meets_schema' => $meetsSchema,
            'meets_freshness' => $freshness['fresh'],
            'meets_quality' => $meetsQuality,
            'passed' => $passed,
            'details' => [
                'owner' => $contract->owner,
                'expected_schema_hash' => $contract->schema_hash,
                'current_schema_hash' => $currentHash,
                'freshness' => $freshness,
                'quality_score' => $dataset->quality_score,
                'quality_threshold' => $contract->quality_threshold,
            ],
        ];
    }

    /**
     * @return array{fresh: bool, age_hours: ?float, sla_hours: int, reference_at: ?string}
     */
    public function freshnessStatus(Dataset $dataset, ?DataContract $contract = null): array
    {
        $contract ??= DataContract::query()->where('dataset_id', $dataset->getKey())->active()->first();
        $sla = (int) ($contract?->freshness_sla_hours ?? 72);

        $reference = $dataset->committed_at ?? $dataset->updated_at ?? $dataset->created_at;

        if (! $reference) {
            return ['fresh' => false, 'age_hours' => null, 'sla_hours' => $sla, 'reference_at' => null];
        }

        $ageHours = round($reference->diffInSeconds(now()) / 3600, 2);

        return [
            'fresh' => $ageHours <= $sla,
            'age_hours' => $ageHours,
            'sla_hours' => $sla,
            'reference_at' => $reference->toIso8601String(),
        ];
    }

    /**
     * Compare a new column list against the registry (falling back to the
     * dataset's stored columns when the registry is empty).
     *
     * @param  list<array<string, mixed>|string>  $newColumns
     * @return array{added: list<string>, removed: list<string>, type_changed: list<array{name: string, from: ?string, to: ?string}>, has_drift: bool}
     */
    public function detectSchemaDrift(Dataset $dataset, array $newColumns): array
    {
        $baseline = $this->registrySchema($dataset);
        $incoming = self::normaliseSchema($newColumns);

        $added = array_values(array_diff(array_keys($incoming), array_keys($baseline)));
        $removed = array_values(array_diff(array_keys($baseline), array_keys($incoming)));

        $typeChanged = [];

        foreach (array_intersect(array_keys($incoming), array_keys($baseline)) as $name) {
            if ($incoming[$name] !== $baseline[$name]) {
                $typeChanged[] = ['name' => $name, 'from' => $baseline[$name], 'to' => $incoming[$name]];
            }
        }

        sort($added);
        sort($removed);

        return [
            'added' => $added,
            'removed' => $removed,
            'type_changed' => $typeChanged,
            'has_drift' => $added !== [] || $removed !== [] || $typeChanged !== [],
        ];
    }

    /**
     * Roll-up verdict: freshness + quality + drift.
     *
     * @return array{freshness: array<string, mixed>, quality: array<string, mixed>, drift: array<string, mixed>, contract: array<string, mixed>, verdict: string, issues: list<string>}
     */
    public function datasetHealth(Dataset $dataset): array
    {
        $contract = $this->checkContract($dataset);
        $freshness = $this->freshnessStatus($dataset);
        $drift = $this->detectSchemaDrift($dataset, (array) $dataset->columns ?? []);

        $threshold = (float) ($contract['details']['quality_threshold'] ?? config('ai_engine.quality_threshold', 0.75));
        $score = $dataset->quality_score !== null ? (float) $dataset->quality_score : null;

        $quality = [
            'score' => $score,
            'threshold' => $threshold,
            'verdict' => $dataset->quality_verdict,
            'meets_threshold' => $score === null ? null : $score >= $threshold,
        ];

        $issues = [];

        if (! $freshness['fresh']) {
            $issues[] = 'Stale: data is '.$freshness['age_hours'].'h old against a '.$freshness['sla_hours'].'h SLA.';
        }

        if ($score !== null && $score < $threshold) {
            $issues[] = 'Quality score '.$score.' is below threshold '.$threshold.'.';
        }

        if (in_array($dataset->status(), [DatasetStatus::Quarantined, DatasetStatus::Failed], true)) {
            $issues[] = 'Dataset status is '.$dataset->status()->value.'.';
        }

        if ($drift['has_drift']) {
            $issues[] = 'Schema drift: registry differs from stored columns.';
        }

        $verdict = match (true) {
            in_array($dataset->status(), [DatasetStatus::Quarantined, DatasetStatus::Failed], true) => 'critical',
            ! $freshness['fresh'] && $score !== null && $score < $threshold => 'critical',
            $issues !== [] => 'degraded',
            default => 'healthy',
        };

        return [
            'freshness' => $freshness,
            'quality' => $quality,
            'drift' => $drift,
            'contract' => $contract,
            'verdict' => $verdict,
            'issues' => $issues,
        ];
    }

    /**
     * Baseline schema for drift comparison: registry first, stored dataset
     * columns as fallback.
     *
     * @return array<string, ?string>
     */
    protected function registrySchema(Dataset $dataset): array
    {
        $registered = ColumnMetadata::query()
            ->where('dataset_id', $dataset->getKey())
            ->pluck('dtype', 'name')
            ->all();

        if ($registered !== []) {
            return $registered;
        }

        return self::normaliseSchema((array) $dataset->columns ?? []);
    }

    /**
     * @param  list<array<string, mixed>|string>  $columns
     * @return array<string, ?string>
     */
    public static function normaliseSchema(array $columns): array
    {
        $schema = [];

        foreach ($columns as $column) {
            if (is_string($column)) {
                $schema[$column] = null;
            } elseif (is_array($column) && isset($column['name'])) {
                $schema[(string) $column['name']] = isset($column['dtype']) ? (string) $column['dtype'] : null;
            }
        }

        ksort($schema);

        return $schema;
    }

    /**
     * @param  list<array<string, mixed>|string>  $columns
     */
    public static function hashSchema(array $columns): string
    {
        return hash('sha256', json_encode(self::normaliseSchema($columns)) ?: '[]');
    }
}
