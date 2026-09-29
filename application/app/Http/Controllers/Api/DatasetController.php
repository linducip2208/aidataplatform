<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Dataset;
use App\Services\DatasetIngestionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Token API mirror of the Blade dataset flow. Response envelope follows
 * `docs/api.md`: `{data: ..., meta: {total, page, per_page}}`.
 */
class DatasetController extends Controller
{
    public function __construct(private readonly DatasetIngestionService $ingestion) {}

    public function index(Request $request): JsonResponse
    {
        $datasets = Dataset::query()
            ->ofType($request->query('dataset_type'))
            ->withStatus($request->query('status'))
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = '%'.$request->string('q')->trim().'%';
                $query->whereLike('name', $term, caseSensitive: false);
            })
            ->when($request->filled('sort'), function ($query) use ($request): void {
                $sort = (string) $request->query('sort');
                $column = str_starts_with($sort, '-') ? substr($sort, 1) : $sort;

                if (in_array($column, ['created_at', 'name', 'status', 'quality_score', 'size_bytes'], true)) {
                    $query->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc');
                }
            })
            ->orderByDesc('created_at')
            ->paginate(ApiResponse::perPage())
            ->withQueryString();

        return ApiResponse::paginate(
            $datasets->through(fn (Dataset $dataset): array => $this->present($dataset)),
            $request->only(['q', 'dataset_type', 'status', 'sort']),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.config('ai_engine.max_upload_mb'),
                'extensions:'.implode(',', config('ai_engine.allowed_extensions')),
            ],
            'name' => ['nullable', 'string', 'max:150'],
            'dataset_type' => ['required', 'string', 'in:'.implode(',', config('ai_engine.dataset_types'))],
        ], [], ['file' => 'file']);

        $file = $request->file('file');
        $name = $validated['name'] ?? null;
        $name = $name ?: pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME);

        $dataset = $this->ingestion->createFromUpload($file, $name, $validated['dataset_type'], $request->user());

        return ApiResponse::data($this->present($dataset), 201);
    }

    public function show(Dataset $dataset): JsonResponse
    {
        return ApiResponse::data($this->present($dataset, detailed: true));
    }

    public function quality(Dataset $dataset): JsonResponse
    {
        $result = $this->ingestion->runQuality($dataset);

        return ApiResponse::data([
            'dataset_id' => $dataset->uuid,
            'score' => $result['score'],
            'threshold' => $result['threshold'],
            'verdict' => $result['verdict']->value,
            'checks' => data_get($result['report'], 'breakdown', []),
            'issues' => data_get($result['report'], 'issues', []),
            'profiled_at' => $dataset->quality_checked_at?->toIso8601String(),
        ]);
    }

    public function mapping(Request $request, Dataset $dataset): JsonResponse
    {
        $validated = $request->validate([
            'mappings' => ['required', 'array'],
            'mappings.*' => ['nullable', 'string', 'max:100'],
            'save_as_template' => ['nullable', 'string', 'max:120'],
        ]);

        $mappings = array_filter(
            array_map(static fn ($value): string => trim((string) $value), (array) $validated['mappings']),
            static fn (string $value): bool => $value !== '',
        );

        // The doc promises a 422 whose `errors` are keyed by field, so the
        // failure is reported against `mappings` rather than as a body with an
        // empty `errors` object the client cannot act on.
        if ($mappings === []) {
            throw ValidationException::withMessages([
                'mappings' => ['Minimal satu kolom harus dipetakan.'],
            ]);
        }

        $this->ingestion->suggestMapping($dataset, $mappings, $validated['save_as_template'] ?? null);

        return ApiResponse::data($this->present($dataset->refresh()));
    }

    public function commit(Request $request, Dataset $dataset): JsonResponse
    {
        $result = $this->ingestion->commit($dataset, $request->boolean('run_async', true));

        return ApiResponse::data([
            'import_job_id' => $dataset->import_job_id,
            'status' => (string) ($result['status'] ?? 'queued'),
            'result' => $result,
        ], 202);
    }

    public function destroy(Request $request, Dataset $dataset): JsonResponse
    {
        $name = $dataset->name;
        $size = $dataset->size_bytes;
        $filename = $dataset->source_filename;

        // The stored upload goes with the row. Without this the API left the
        // source file on disk forever, with no row pointing at it and no way
        // to reclaim it.
        if ($dataset->path) {
            Storage::disk($dataset->disk)->delete($dataset->path);
        }

        $dataset->delete();

        AuditLog::record('dataset.deleted', 'dataset', $dataset->getKey(), [
            'name' => $name,
            'source_filename' => $filename,
            'size_bytes' => $size,
        ]);

        return ApiResponse::message('Dataset deleted.');
    }

    /** @return array<string, mixed> */
    private function present(Dataset $dataset, bool $detailed = false): array
    {
        $payload = [
            'id' => $dataset->uuid,
            'name' => $dataset->name,
            'dataset_type' => $dataset->dataset_type,
            'status' => $dataset->status()->value,
            'source_filename' => $dataset->source_filename,
            'size_bytes' => (int) $dataset->size_bytes,
            'row_count' => (int) $dataset->row_count,
            'column_count' => (int) $dataset->column_count,
            'import_job_id' => $dataset->import_job_id,
            'quality_score' => $dataset->quality_score,
            'quality_verdict' => $dataset->quality_verdict,
            'created_at' => $dataset->created_at?->toIso8601String(),
            'committed_at' => $dataset->committed_at?->toIso8601String(),
        ];

        if ($detailed) {
            $payload['columns'] = $dataset->columns ?? [];
            $payload['mappings'] = $dataset->mappings ?? [];
            // `metadata` is documented as a key on this response, so it stays.
            // What it must not do is hand out the upload validator's own report
            // verbatim: that blob carries the sha256 of the uploaded business
            // file, the byte count the engine read, and its own stored filename.
            // The dataset row already holds the checksum, and this presenter
            // deliberately does not publish it, so shipping it nested inside an
            // engine-internal structure is the leak.
            $payload['metadata'] = Arr::except((array) ($dataset->metadata ?? []), ['validation']);
        }

        return $payload;
    }
}
