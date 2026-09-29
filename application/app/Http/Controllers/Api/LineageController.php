<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DataLineage;
use App\Models\Dataset;
use App\Services\CatalogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lineage graph reads (`GET /api/lineage/*`) plus edge recording
 * (`POST /api/lineage`).
 *
 * RBAC is middleware-only (wired by master in `routes/api.php`): reads allow
 * `admin,analyst,viewer`, the write requires `admin,analyst`. No policies.
 */
class LineageController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function graph(Dataset $dataset, Request $request): JsonResponse
    {
        $depth = max(1, min((int) $request->query('depth', 3), 10));

        return ApiResponse::data(DataLineage::graphFor('dataset', $dataset->uuid, $depth));
    }

    public function upstream(string $nodeType, string $nodeId, Request $request): JsonResponse
    {
        $depth = max(1, min((int) $request->query('depth', 5), 10));

        return ApiResponse::data(DataLineage::upstream($nodeType, $nodeId, $depth));
    }

    public function downstream(string $nodeType, string $nodeId, Request $request): JsonResponse
    {
        $depth = max(1, min((int) $request->query('depth', 5), 10));

        return ApiResponse::data(DataLineage::downstream($nodeType, $nodeId, $depth));
    }

    /**
     * Column mapping of one dataset: which uploaded header became which
     * canonical field, i.e. the answer to "if this header changes, which
     * warehouse columns break".
     */
    public function columns(Dataset $dataset): JsonResponse
    {
        return ApiResponse::data([
            'dataset_id' => $dataset->uuid,
            'columns' => DataLineage::columnEdgesFor($dataset),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_type' => ['required', 'string', 'max:64'],
            'source_id' => ['required', 'string', 'max:191'],
            'target_type' => ['required', 'string', 'max:64'],
            'target_id' => ['required', 'string', 'max:191'],
            'transform' => ['nullable', 'string', 'max:2000'],
            'run_reference' => ['nullable', 'string', 'max:191'],
        ]);

        $edge = $this->catalog->recordLineage($validated);

        return ApiResponse::data([
            'id' => $edge->getKey(),
            'source_type' => $edge->source_type,
            'source_id' => $edge->source_id,
            'target_type' => $edge->target_type,
            'target_id' => $edge->target_id,
            'transform' => $edge->transform,
            'run_reference' => $edge->run_reference,
        ], 201);
    }
}
