<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ColumnMetadata;
use App\Models\DataContract;
use App\Models\Dataset;
use App\Models\DatasetVersion;
use App\Services\CatalogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Read endpoints for the data catalog (`GET /api/catalog/*`,
 * `GET /api/schema-registry/*`) plus the persisting catalog writes
 * (`POST .../versions`, `POST .../columns/{column}/annotate`,
 * `POST .../contracts`, `POST .../drift`).
 *
 * RBAC is middleware-only (wired by master in `routes/api.php`): reads allow
 * `admin,analyst,viewer`, writes require `admin,analyst`. No policies.
 */
class CatalogController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function show(Dataset $dataset): JsonResponse
    {
        return ApiResponse::data([
            'id' => $dataset->uuid,
            'name' => $dataset->name,
            'dataset_type' => $dataset->dataset_type,
            'status' => $dataset->status()->value,
            'row_count' => (int) $dataset->row_count,
            'column_count' => (int) $dataset->column_count,
            'quality_score' => $dataset->quality_score,
            'quality_verdict' => $dataset->quality_verdict,
            'schema_hash' => CatalogService::hashSchema((array) $dataset->columns ?? []),
            'versions_count' => DatasetVersion::query()->where('dataset_id', $dataset->getKey())->count(),
            'columns_count' => ColumnMetadata::query()->where('dataset_id', $dataset->getKey())->count(),
            'has_contract' => DataContract::query()->where('dataset_id', $dataset->getKey())->active()->exists(),
            'committed_at' => $dataset->committed_at?->toIso8601String(),
            'updated_at' => $dataset->updated_at?->toIso8601String(),
        ]);
    }

    public function columns(Dataset $dataset): JsonResponse
    {
        $columns = ColumnMetadata::query()
            ->where('dataset_id', $dataset->getKey())
            ->orderBy('name')
            ->get();

        return ApiResponse::data($columns->map(fn (ColumnMetadata $column): array => $this->presentColumn($column))->all());
    }

    public function versions(Dataset $dataset): JsonResponse
    {
        $versions = DatasetVersion::query()
            ->where('dataset_id', $dataset->getKey())
            ->orderByDesc('version')
            ->get();

        return ApiResponse::data($versions->map(fn (DatasetVersion $version): array => [
            'version' => $version->version,
            'schema_hash' => $version->schema_hash,
            'schema_snapshot' => $version->schema_snapshot ?? [],
            'row_count' => (int) $version->row_count,
            'notes' => $version->notes,
            'created_by' => $version->created_by,
            'created_at' => $version->created_at?->toIso8601String(),
        ])->all());
    }

    public function contract(Dataset $dataset): JsonResponse
    {
        $contract = DataContract::query()->where('dataset_id', $dataset->getKey())->active()->first();

        if (! $contract) {
            return ApiResponse::error('No active contract for this dataset.', 404, 'contract_not_found');
        }

        return ApiResponse::data(array_merge(
            $this->presentContract($contract),
            ['evaluation' => $this->catalog->checkContract($dataset)],
        ));
    }

    public function health(Dataset $dataset): JsonResponse
    {
        return ApiResponse::data(array_merge(['dataset_id' => $dataset->uuid], $this->catalog->datasetHealth($dataset)));
    }

    public function registry(Dataset $dataset): JsonResponse
    {
        $columns = ColumnMetadata::query()
            ->where('dataset_id', $dataset->getKey())
            ->orderBy('name')
            ->get();

        $schema = $columns->isNotEmpty()
            ? $columns->map(fn (ColumnMetadata $column): array => ['name' => $column->name, 'dtype' => $column->dtype])->all()
            : array_values((array) $dataset->columns ?? []);

        return ApiResponse::data([
            'dataset_id' => $dataset->uuid,
            'schema_hash' => CatalogService::hashSchema($schema),
            'columns' => $columns->map(fn (ColumnMetadata $column): array => $this->presentColumn($column))->all(),
        ]);
    }

    public function storeVersion(Request $request, Dataset $dataset): JsonResponse
    {
        Gate::authorize('update', $dataset);

        $validated = $request->validate([
            'schema' => ['nullable', 'array'],
            'schema.*' => ['array'],
            'row_count' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $version = $this->catalog->registerVersion(
            $dataset,
            $validated['schema'] ?? null,
            $validated['row_count'] ?? null,
            $validated['notes'] ?? null,
            $request->user(),
        );

        return ApiResponse::data([
            'version' => $version->version,
            'schema_hash' => $version->schema_hash,
            'schema_snapshot' => $version->schema_snapshot ?? [],
            'row_count' => (int) $version->row_count,
            'notes' => $version->notes,
        ], 201);
    }

    public function annotate(Request $request, Dataset $dataset, string $column): JsonResponse
    {
        Gate::authorize('update', $dataset);

        $validated = $request->validate([
            'business_description' => ['nullable', 'string', 'max:2000'],
            'sensitivity' => ['nullable', 'string', 'in:'.implode(',', ColumnMetadata::sensitivities())],
            'is_pii' => ['nullable', 'boolean'],
        ]);

        if (! ColumnMetadata::query()->where('dataset_id', $dataset->getKey())->where('name', $column)->exists()) {
            return ApiResponse::error('Unknown column "'.$column.'" for this dataset.', 404, 'column_not_found');
        }

        $updated = $this->catalog->annotateColumn($dataset, $column, [
            'business_description' => $validated['business_description'] ?? null,
            'sensitivity' => $validated['sensitivity'] ?? null,
            'is_pii' => $validated['is_pii'] ?? null,
        ]);

        return ApiResponse::data($this->presentColumn($updated));
    }

    public function upsertContract(Request $request, Dataset $dataset): JsonResponse
    {
        Gate::authorize('update', $dataset);

        $validated = $request->validate([
            'owner' => ['required', 'string', 'max:191'],
            'schema_hash' => ['nullable', 'string', 'max:64'],
            'freshness_sla_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'quality_threshold' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $contract = $this->catalog->upsertContract($dataset, $validated);

        return ApiResponse::data($this->presentContract($contract), 201);
    }

    /**
     * Compute-only drift check: compares the posted column list against the
     * registry. A POST (not GET) because the schema payload rides in the
     * request body; nothing is persisted.
     */
    public function drift(Request $request, Dataset $dataset): JsonResponse
    {
        $validated = $request->validate([
            'columns' => ['required', 'array'],
        ]);

        return ApiResponse::data(array_merge(
            ['dataset_id' => $dataset->uuid],
            $this->catalog->detectSchemaDrift($dataset, $validated['columns']),
        ));
    }

    /** @return array<string, mixed> */
    private function presentColumn(ColumnMetadata $column): array
    {
        return [
            'name' => $column->name,
            'dtype' => $column->dtype,
            'nullable' => (bool) $column->nullable,
            'is_pii' => (bool) $column->is_pii,
            'sensitivity' => $column->sensitivity,
            'business_description' => $column->business_description,
            'distinct_count' => $column->distinct_count !== null ? (int) $column->distinct_count : null,
            'null_pct' => $column->null_pct !== null ? (float) $column->null_pct : null,
            'min' => $column->min_value,
            'max' => $column->max_value,
        ];
    }

    /** @return array<string, mixed> */
    private function presentContract(DataContract $contract): array
    {
        return [
            'dataset_id' => $contract->dataset?->uuid,
            'owner' => $contract->owner,
            'schema_hash' => $contract->schema_hash,
            'freshness_sla_hours' => (int) $contract->freshness_sla_hours,
            'quality_threshold' => (float) $contract->quality_threshold,
            'is_active' => (bool) $contract->is_active,
            'updated_at' => $contract->updated_at?->toIso8601String(),
        ];
    }
}
