<?php

namespace App\Http\Controllers;

use App\Models\Dataset;
use App\Services\DatasetIngestionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The three-step ingestion flow from `docs/api.md`: profile the file, confirm
 * the column mapping, then run quality and commit the rows to the warehouse.
 */
class DatasetWorkflowController extends Controller
{
    public function __construct(private readonly DatasetIngestionService $ingestion) {}

    public function preview(Request $request, Dataset $dataset): RedirectResponse
    {
        $preview = $this->ingestion->preview($dataset);

        $rows = (int) ($preview['row_count'] ?? 0);
        $columns = (int) ($preview['column_count'] ?? 0);

        return back()->with('status', "Preview selesai: {$rows} baris, {$columns} kolom.");
    }

    public function mapping(Request $request, Dataset $dataset): RedirectResponse
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

        if ($mappings === []) {
            return back()->with('error', 'Minimal satu kolom harus dipetakan.');
        }

        $this->ingestion->suggestMapping($dataset, $mappings, $validated['save_as_template'] ?? null);

        return back()->with('status', count($mappings).' kolom dipetakan. Lanjutkan ke pemeriksaan kualitas.');
    }

    public function quality(Request $request, Dataset $dataset): RedirectResponse
    {
        $result = $this->ingestion->runQuality($dataset);

        $score = number_format($result['score'] * 100, 1);
        $threshold = number_format($result['threshold'] * 100, 1);

        $message = $result['verdict']->value === 'pass'
            ? "Kualitas {$score}% (minimum {$threshold}%) — data lolos."
            : "Kualitas {$score}% di bawah minimum {$threshold}% — data dikarantina.";

        return back()->with($result['verdict']->value === 'pass' ? 'status' : 'error', $message);
    }

    public function commit(Request $request, Dataset $dataset): RedirectResponse
    {
        $validated = $request->validate([
            'run_async' => ['nullable', 'boolean'],
        ]);

        $this->ingestion->commit($dataset, $request->boolean('run_async', true));

        return redirect()
            ->route('imports.show', $dataset)
            ->with('status', 'Import diproses worker. Pantau progres di halaman Imports.');
    }
}
