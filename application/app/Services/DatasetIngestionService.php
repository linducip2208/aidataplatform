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

        $dataset = Dataset::create([
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

        $dataset->update(['status' => DatasetStatus::Previewing]);

        $preview = $this->engine->preview((int) $dataset->import_job_id);

        $columns = [];
        foreach ((array) ($preview['columns'] ?? []) as $column) {
            $columns[] = is_array($column) ? $column : ['name' => (string) $column];
        }

        $dataset->update([
            'columns' => $columns,
            'row_count' => (int) ($preview['row_count'] ?? 0),
            'column_count' => (int) ($preview['column_count'] ?? count($columns)),
            'metadata' => array_merge((array) $dataset->metadata, ['preview' => $preview]),
            'status' => DatasetStatus::Uploaded,
        ]);

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

        $this->engine->applyMapping(
            (int) $dataset->import_job_id,
            $mappings,
            $dataset->dataset_type,
            $saveAsTemplate,
        );

        $dataset->update([
            'mappings' => $mappings,
            'status' => DatasetStatus::Mapped,
        ]);

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

        $dataset->update([
            'quality_score' => $score,
            'quality_verdict' => $verdict->value,
            'quality_checked_at' => now(),
            'metadata' => array_merge((array) $dataset->metadata, ['quality' => $report]),
            // A re-check must never walk a committed dataset back to `uploaded`:
            // the nightly `sync:quality` sweep runs this on committed rows, and
            // un-committing the whole mirror on every pass would be silent
            // data loss in the UI.
            'status' => match (true) {
                $verdict === QualityVerdict::Quarantine => DatasetStatus::Quarantined,
                $dataset->status()->isTerminal() => $dataset->status(),
                default => DatasetStatus::Uploaded,
            },
        ]);

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

        $dataset->update(['status' => DatasetStatus::Importing]);

        $result = $this->engine->commitImport(
            (int) $dataset->import_job_id,
            (array) ($dataset->mappings ?? []),
            $dataset->dataset_type,
            $runAsync,
        );

        $status = strtolower((string) ($result['status'] ?? 'queued'));

        $dataset->update([
            'status' => in_array($status, ['succeeded', 'success', 'completed', 'done'], true)
                ? DatasetStatus::Committed
                : DatasetStatus::Importing,
            'committed_at' => $dataset->committed_at ?? now(),
            'row_count' => (int) ($result['row_count'] ?? $result['total_rows'] ?? $dataset->row_count),
        ]);

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

        $dataset->update([
            // A job that has not started yet must not drag a `previewing` or
            // `mapped` dataset back to `importing`; the pre-commit states are
            // what the upload wizard is driven by.
            'status' => match (true) {
                $status === '' => $current,
                in_array($status, ['succeeded', 'success', 'completed', 'done'], true) => DatasetStatus::Committed,
                in_array($status, ['failed', 'error'], true) => DatasetStatus::Failed,
                $current === DatasetStatus::Quarantined => DatasetStatus::Quarantined,
                in_array($status, ['queued', 'uploaded', 'pending'], true)
                    && in_array($current, [DatasetStatus::Previewing, DatasetStatus::Mapped, DatasetStatus::Uploaded], true) => $current,
                default => DatasetStatus::Importing,
            },
            'row_count' => (int) ($job['total_rows'] ?? $dataset->row_count),
            'metadata' => array_merge((array) $dataset->metadata, ['import_job' => $job]),
        ]);

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
