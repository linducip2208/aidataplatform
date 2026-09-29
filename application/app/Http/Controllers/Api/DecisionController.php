<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiEngineException;
use App\Http\Controllers\Controller;
use App\Services\DecisionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DecisionController extends Controller
{
    private const SCENARIO_TYPES = ['price_change_pct', 'inventory_change_pct', 'churn_rise_pp'];

    public function index(Request $request, DecisionService $service): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        // Read-only proxy of `GET /api/v1/decision/cases`. Never writes.
        return ApiResponse::data($service->cases((int) ($validated['limit'] ?? 50)));
    }

    public function show(Request $request, DecisionService $service, string $id): JsonResponse
    {
        if (! $this->isCaseId($id)) {
            return $this->invalidCaseId();
        }

        try {
            $case = $service->case((int) $id);
        } catch (AiEngineException $exception) {
            if ($exception->upstreamStatus() === 404) {
                return ApiResponse::error('Decision case not found.', 404, 'not_found');
            }

            throw $exception;
        }

        // Read-only proxy of `GET /api/v1/decision/cases/{id}`. Never writes.
        return ApiResponse::data($case);
    }

    public function recommend(Request $request, DecisionService $service): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['nullable', 'array'],
            'subject.dataset_ref' => ['nullable', 'string', 'max:256'],
            'subject.branch' => ['nullable', 'string', 'max:128'],
            'subject.period' => ['nullable', 'string', 'max:32'],
            'subject.granularity' => ['nullable', 'string', Rule::in(['daily', 'weekly', 'monthly'])],
            'subject.horizon' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        // Compute + persist on the engine: answers 201 with the stored case.
        return ApiResponse::data($service->recommend($validated['subject'] ?? []), 201);
    }

    public function runScenario(Request $request, DecisionService $service): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(self::SCENARIO_TYPES)],
            'params' => ['nullable', 'array'],
            'subject' => ['nullable', 'array'],
            'subject.dataset_ref' => ['nullable', 'string', 'max:256'],
            'subject.branch' => ['nullable', 'string', 'max:128'],
            'subject.period' => ['nullable', 'string', 'max:32'],
        ]);

        // Compute-only on the engine. An unsupported shape (`supported: false`
        // with `reasons` and no `deltas`) is passed through untouched — the
        // explicit-unsupported policy forbids filling numbers in here.
        return ApiResponse::data($service->runScenario(
            $validated['type'],
            $validated['params'] ?? [],
            $validated['subject'] ?? [],
        ));
    }

    public function audit(Request $request, DecisionService $service, string $id): JsonResponse
    {
        if (! $this->isCaseId($id)) {
            return $this->invalidCaseId();
        }

        $validated = $request->validate([
            'actor' => ['required', 'string', 'max:128'],
            'decision' => ['required', 'string', 'max:64'],
            'rationale' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $row = $service->audit(
                (int) $id,
                $validated['actor'],
                $validated['decision'],
                $validated['rationale'] ?? '',
            );
        } catch (AiEngineException $exception) {
            if ($exception->upstreamStatus() === 404) {
                return ApiResponse::error('Decision case not found.', 404, 'not_found');
            }

            throw $exception;
        }

        return ApiResponse::data($row, 201);
    }

    public function rules(DecisionService $service): JsonResponse
    {
        // Read-only proxy of `GET /api/v1/decision/rules`. Never writes.
        return ApiResponse::data($service->rules());
    }

    private function isCaseId(string $id): bool
    {
        return ctype_digit($id) && (int) $id > 0;
    }

    private function invalidCaseId(): JsonResponse
    {
        return ApiResponse::error('Case id must be a positive integer.', 422, 'validation_failed', [
            'id' => ['Case id must be a positive integer.'],
        ]);
    }
}
