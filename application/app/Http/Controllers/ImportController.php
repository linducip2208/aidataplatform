<?php

namespace App\Http\Controllers;

use App\Models\Dataset;
use App\Services\DatasetIngestionService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ImportController extends Controller
{
    public function __construct(private readonly DatasetIngestionService $ingestion) {}

    public function index(Request $request): View
    {
        $datasets = Dataset::query()
            ->whereNotNull('import_job_id')
            ->orderByDesc('updated_at')
            ->paginate(ApiResponse::perPage())
            ->withQueryString();

        return view('imports.index', ['datasets' => $datasets]);
    }

    public function show(Request $request, Dataset $dataset): View
    {
        $job = [];

        if ($request->boolean('refresh') && $dataset->import_job_id) {
            $job = $this->ingestion->syncStatus($dataset);
        }

        return view('imports.show', [
            'dataset' => $dataset,
            'job' => $job !== [] ? $job : (array) data_get((array) $dataset->metadata, 'import_job', []),
        ]);
    }
}
