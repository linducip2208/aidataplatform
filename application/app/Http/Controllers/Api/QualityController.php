<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\QualityService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Enterprise quality governance API. Envelope: `{"data": ...}`.
 *
 * Read/write split is structural: GET routes only read (local rules,
 * engine history/run) and never persist; POST routes compute + persist.
 */
class QualityController extends Controller
{
    public function __construct(private readonly QualityService $quality) {}

    public function rules(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'dataset_type' => ['nullable', 'string', 'max:64'],
            'rule_type' => ['nullable', 'string', 'max:32'],
            'active' => ['nullable', 'boolean'],
        ]);

        return ApiResponse::data($this->quality->listRules($filters)->toArray());
    }

    public function storeRule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'dataset_type' => ['nullable', 'string', 'max:64'],
            'column' => ['nullable', 'string', 'max:256'],
            'rule_type' => ['required', 'string', 'max:32', Rule::in(QualityService::RULE_TYPES)],
            'params' => ['nullable', 'array'],
            'severity' => ['nullable', 'string', Rule::in(QualityService::SEVERITIES)],
            'active' => ['nullable', 'boolean'],
        ]);

        return ApiResponse::data($this->quality->saveRule($validated)->toArray(), 201);
    }

    public function evaluate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dataset_id' => ['nullable', 'uuid', 'exists:datasets,uuid'],
            'dataset_ref' => ['nullable', 'string', 'max:256'],
            'job_id' => ['nullable', 'integer', 'min:1'],
            'profile' => ['nullable', 'string', 'max:128'],
            'rules' => ['nullable', 'array'],
            'rows' => ['nullable', 'array'],
        ]);

        return ApiResponse::data($this->quality->evaluateViaEngine($validated), 201);
    }

    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dataset_ref' => ['nullable', 'string', 'max:256'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return ApiResponse::data($this->quality->history(
            $validated['dataset_ref'] ?? null,
            (int) ($validated['limit'] ?? 50),
        ));
    }

    public function showRun(int $id): JsonResponse
    {
        return ApiResponse::data($this->quality->run($id));
    }
}
