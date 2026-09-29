<?php

namespace App\Http\Controllers;

use App\Enums\DatasetStatus;
use App\Models\AuditLog;
use App\Models\Dataset;
use App\Services\DatasetIngestionService;
use App\Support\ApiResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class DatasetController extends Controller
{
    public function __construct(private readonly DatasetIngestionService $ingestion) {}

    public function index(Request $request): View
    {
        $datasets = Dataset::query()
            ->ofType($request->query('dataset_type'))
            ->withStatus($request->query('status'))
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = '%'.$request->string('q')->trim().'%';
                $query->where(function ($inner) use ($term): void {
                    $inner->whereLike('name', $term, caseSensitive: false)
                        ->orWhereLike('source_filename', $term, caseSensitive: false);
                });
            })
            ->orderByDesc('created_at')
            ->paginate(ApiResponse::perPage())
            ->withQueryString();

        return view('datasets.index', [
            'datasets' => $datasets,
            'datasetTypes' => config('ai_engine.dataset_types'),
            'statuses' => DatasetStatus::cases(),
            'filters' => $request->only(['q', 'dataset_type', 'status']),
        ]);
    }

    public function create(): View
    {
        return view('datasets.create', [
            'datasetTypes' => config('ai_engine.dataset_types'),
            'maxUploadMb' => config('ai_engine.max_upload_mb'),
            'allowedExtensions' => config('ai_engine.allowed_extensions'),
        ]);
    }

    public function store(Request $request): RedirectResponse
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
        ], [], [
            'file' => 'berkas',
            'name' => 'nama dataset',
            'dataset_type' => 'tipe dataset',
        ]);

        $file = $request->file('file');
        $name = $validated['name'] ?? null;
        $name = $name ?: pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME);

        $dataset = $this->ingestion->createFromUpload(
            $file,
            $name,
            $validated['dataset_type'],
            $request->user(),
        );

        return redirect()
            ->route('datasets.show', $dataset)
            ->with('status', "Dataset \"{$dataset->name}\" diterima. Jalankan preview untuk memprofil datanya.");
    }

    public function show(Request $request, Dataset $dataset): View
    {
        return view('datasets.show', [
            'dataset' => $dataset,
            'sampleRows' => (array) data_get((array) $dataset->metadata, 'preview.sample_rows', []),
            'quality' => (array) data_get((array) $dataset->metadata, 'quality', []),
            'threshold' => config('ai_engine.quality_threshold'),
        ]);
    }

    public function destroy(Request $request, Dataset $dataset): RedirectResponse
    {
        $name = $dataset->name;
        $size = $dataset->size_bytes;
        $filename = $dataset->source_filename;

        if ($dataset->path) {
            Storage::disk($dataset->disk)->delete($dataset->path);
        }

        $dataset->delete();

        // Deleting a dataset also removes the uploaded file from disk, with no
        // undo. Without this the audit trail records nothing for the one
        // destructive action an analyst can take.
        AuditLog::record('dataset.deleted', 'dataset', $dataset->getKey(), [
            'name' => $name,
            'source_filename' => $filename,
            'size_bytes' => $size,
        ]);

        return redirect()
            ->route('datasets.index')
            ->with('status', "Dataset \"{$name}\" dihapus.");
    }
}
