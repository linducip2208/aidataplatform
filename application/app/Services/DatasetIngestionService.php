<?php

namespace App\Services;

use App\Enums\DatasetStatus;
use App\Enums\QualityVerdict;
use App\Exceptions\AiEngineException;
use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Dataset lifecycle orchestration: Laravel keeps the file and the metadata, the
 * AI engine owns parsing/quality/ETL. Every engine call goes through
 * {@see AiEngineClient} and every transition is mirrored on the local
 * `datasets` row so the UI stays useful even while the engine is busy.
 */
class DatasetIngestionService
{
    public function __construct(private readonly AiEngineClient $engine) {}

    /**
     * Store the upload locally, hand the file to the engine, and persist the
     * resulting dataset + import job reference.
     */
    public function createFromUpload(UploadedFile $file, string $name, string $datasetType, ?User $user = null): Dataset
    {
        $disk = (string) config('filesystems.default');
        $storedPath = $file->store('datasets/'.now()->format('Y/m'), $disk);
        $absolutePath = Storage::disk($disk)->path($storedPath);
        $checksum = is_file($absolutePath) ? hash_file('sha256', $absolutePath) : null;

        $engineResult = $this->engine->uploadFile(
            $this->engineFile($file, $storedPath, $disk),
            $datasetType,
        );

        $importJobId = (int) ($engineResult['import_job_id'] ?? 0);
        $validation = (array) ($engineResult['validation'] ?? []);

        // `forceFill`, not `create`: the model's `$fillable` deliberately holds
        // only request-settable fields, so the server-owned ones written here
        // have to be declared as such at the call site. The constructor path
        // goes through `fill()`, which respects `$fillable`, so it is
        // `forceFill` and not a plain `new Dataset([...])` as well.
        $dataset = new Dataset;
        $dataset->forceFill([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'dataset_type' => $datasetType,
            'source_filename' => $file->getClientOriginalName(),
            'disk' => $disk,
            'path' => $storedPath,
            'size_bytes' => (int) ($validation['meta']['size_bytes'] ?? $file->getSize()),
            'mime' => (string) ($validation['meta']['mime'] ?? $file->getClientMimeType()),
            'checksum_sha256' => (string) ($validation['meta']['checksum_sha256'] ?? $checksum),
            'status' => ($validation['ok'] ?? false) ? DatasetStatus::Uploaded : DatasetStatus::Failed,
            'import_job_id' => $importJobId ?: null,
            'metadata' => ['validation' => $validation],
            'user_id' => $user?->getKey(),
        ]);
        $dataset->save();

        AuditLog::record('dataset.uploaded', 'dataset', $dataset->getKey(), [
            'filename' => $dataset->source_filename,
            'size_bytes' => $dataset->size_bytes,
            'import_job_id' => $importJobId,
        ]);

        return $dataset;
    }

    /** @return array<string, mixed> */
    public function preview(Dataset $dataset): array
    {
        $this->assertHasJob($dataset);

        $dataset->forceFill(['status' => DatasetStatus::Previewing])->save();

        $preview = $this->engine->preview((int) $dataset->import_job_id);

        $columns = [];
        foreach ((array) ($preview['columns'] ?? []) as $column) {
            $columns[] = is_array($column) ? $column : ['name' => (string) $column];
        }

        $dataset->forceFill([
            'columns' => $columns,
            'row_count' => (int) ($preview['row_count'] ?? 0),
            'column_count' => (int) ($preview['column_count'] ?? count($columns)),
            'metadata' => array_merge((array) $dataset->metadata, ['preview' => $preview]),
            'status' => DatasetStatus::Uploaded,
        ])->save();

        return $preview;
    }

    /**
     * @param  array<string, string>|null  $mappings
     * @return array<int|string, array<string, mixed>|string>
     */
    public function suggestMapping(Dataset $dataset, ?array $mappings = null, ?string $saveAsTemplate = null): array
    {
        $this->assertHasJob($dataset);

        if ($mappings === null || $mappings === []) {
            return $this->engine->suggestMapping($dataset->columnNames(), $dataset->dataset_type);
        }

        // The engine's mapper silently skips a source column it cannot find, so
        // forwarding a phantom column would look applied in the UI while doing
        // nothing. Drop them here so what is stored is what was applied. Only
        // possible once the file has been profiled: with no column list the
        // mapping is passed through untouched rather than rejected.
        $known = $dataset->columnNames();

        if ($known !== []) {
            $mappings = array_filter(
                $mappings,
                static fn (string $source): bool => in_array($source, $known, true),
                ARRAY_FILTER_USE_KEY,
            );

            if ($mappings === []) {
                throw new AiEngineException(
                    'None of the mapped columns exist on this dataset. Run the preview again to refresh its columns.',
                    422,
                    'imports.mapping',
                );
            }
        }

        $this->engine->applyMapping(
            (int) $dataset->import_job_id,
            $mappings,
            $dataset->dataset_type,
            $saveAsTemplate,
        );

        $dataset->forceFill([
            'mappings' => $mappings,
            'status' => DatasetStatus::Mapped,
        ])->save();

        AuditLog::record('dataset.mapping_applied', 'dataset', $dataset->getKey(), [
            'mappings' => $mappings,
            'template' => $saveAsTemplate,
        ]);

        return $mappings;
    }

    /** @return array<string, mixed> */
    public function runQuality(Dataset $dataset): array
    {
        $this->assertHasJob($dataset);

        $report = $this->engine->runQuality((int) $dataset->import_job_id);
        $score = (float) ($report['score'] ?? 0);
        $threshold = (float) config('ai_engine.quality_threshold');
        $verdict = ($report['passed'] ?? $score >= $threshold) ? QualityVerdict::Pass : QualityVerdict::Quarantine;

        $dataset->forceFill([
            'quality_score' => $score,
            'quality_verdict' => $verdict->value,
            'quality_checked_at' => now(),
            'metadata' => array_merge((array) $dataset->metadata, ['quality' => $report]),
            // A re-check must not walk a committed dataset anywhere: its rows are
            // already in the warehouse, and both re-check entry points
            // (`SyncQualityCommand`, `RefreshQualityScoreJob`) then skip it
            // because they only look at non-terminal rows — so a quarantine here
            // would strand a committed row permanently. The verdict is still
            // recorded, so `quality_score`/`quality_verdict` show the problem
            // without misrepresenting the warehouse state.
            'status' => match (true) {
                $dataset->status()->isTerminal() => $dataset->status(),
                $verdict === QualityVerdict::Quarantine => DatasetStatus::Quarantined,
                default => DatasetStatus::Uploaded,
            },
        ])->save();

        AuditLog::record('dataset.quality_checked', 'dataset', $dataset->getKey(), [
            'score' => $score,
            'verdict' => $verdict->value,
            'threshold' => $threshold,
        ]);

        return ['report' => $report, 'score' => $score, 'verdict' => $verdict, 'threshold' => $threshold];
    }

    /** @return array<string, mixed> */
    public function commit(Dataset $dataset, bool $runAsync = true): array
    {
        $this->assertHasJob($dataset);

        $dataset->forceFill(['status' => DatasetStatus::Importing])->save();

        $result = $this->engine->commitImport(
            (int) $dataset->import_job_id,
            (array) ($dataset->mappings ?? []),
            $dataset->dataset_type,
            $runAsync,
        );

        $status = strtolower((string) ($result['status'] ?? 'queued'));

        $dataset->forceFill([
            'status' => in_array($status, ['succeeded', 'success', 'completed', 'done'], true)
                ? DatasetStatus::Committed
                : DatasetStatus::Importing,
            'committed_at' => $dataset->committed_at ?? now(),
            'row_count' => (int) ($result['row_count'] ?? $result['total_rows'] ?? $dataset->row_count),
        ])->save();

        AuditLog::record('dataset.committed', 'dataset', $dataset->getKey(), [
            'status' => $status,
            'async' => $runAsync,
        ]);

        return $result;
    }

    /**
     * Pull the current job state from the engine and mirror it locally.
     *
     * @return array<string, mixed>
     */
    public function syncStatus(Dataset $dataset): array
    {
        if (! $dataset->import_job_id) {
            return [];
        }

        $job = $this->engine->importJob((int) $dataset->import_job_id);
        $status = strtolower((string) ($job['status'] ?? ''));
        $current = $dataset->status();

        $next = match (true) {
            in_array($status, ['succeeded', 'success', 'completed', 'done'], true) => DatasetStatus::Committed,
            in_array($status, ['failed', 'error', 'cancelled', 'canceled', 'aborted'], true) => DatasetStatus::Failed,
            $current === DatasetStatus::Quarantined => DatasetStatus::Quarantined,
            in_array($status, ['queued', 'uploaded', 'pending', ''], true) => $current,
            // An engine status this build does not recognise must not drive a
            // transition at all. `done_with_errors` is a real one the engine
            // writes for a partial load, and a `committed` row reaching the
            // default below would be walked back to `importing` — a committed
            // dataset losing its commit because of an upstream status string
            // nobody here had heard of.
            $current->isTerminal() => $current,
            in_array($current, [DatasetStatus::Previewing, DatasetStatus::Mapped, DatasetStatus::Uploaded], true) => $current,
            default => DatasetStatus::Importing,
        };

        $dataset->forceFill([
            'status' => $next,
            'row_count' => (int) ($job['total_rows'] ?? $dataset->row_count),
            'metadata' => array_merge((array) $dataset->metadata, ['import_job' => $job]),
        ])->save();

        // The nightly sweep moves rows between states that the rest of this
        // service records; without this, an import that finished at 02:15 left
        // no trace of how the mirror got there.
        if ($next !== $current) {
            AuditLog::record('dataset.status_synced', 'dataset', $dataset->getKey(), [
                'from' => $current->value,
                'to' => $next->value,
                'engine_status' => $status,
                'import_job_id' => $dataset->import_job_id,
            ]);
        }

        return $job;
    }

    private function assertHasJob(Dataset $dataset): void
    {
        if (! $dataset->import_job_id) {
            throw new AiEngineException(
                'Dataset has no import job yet. Re-upload the file to start the import.',
                422,
                'dataset.import_job',
            );
        }
    }

    /**
     * Re-wrap the stored file as an UploadedFile so the engine can stream it
     * through a multipart request without keeping the bytes in memory.
     */
    private function engineFile(UploadedFile $file, string $storedPath, string $disk): UploadedFile
    {
        $absolute = Storage::disk($disk)->path($storedPath);

        if (! is_file($absolute)) {
            return $file;
        }

        return new UploadedFile(
            $absolute,
            (string) $file->getClientOriginalName(),
            (string) $file->getClientMimeType(),
            null,
            true,
        );
    }
}
