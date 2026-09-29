<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiEngineException;
use App\Http\Controllers\Controller;
use App\Services\AiEngineClient;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnalyticsController extends Controller
{
    public function health(AiEngineClient $engine): JsonResponse
    {
        try {
            $engineHealth = $engine->health();
        } catch (AiEngineException) {
            $engineHealth = ['status' => 'unreachable'];
        }

        return ApiResponse::data([
            'app' => config('app.name'),
            'engine' => $engineHealth,
        ]);
    }

    public function kpi(Request $request, AiEngineClient $engine): JsonResponse
    {
        return ApiResponse::data($engine->kpi($this->filter($request)));
    }

    public function trend(Request $request, AiEngineClient $engine): JsonResponse
    {
        return ApiResponse::data($engine->trend($this->filter($request)));
    }

    public function rfm(Request $request, AiEngineClient $engine): JsonResponse
    {
        return ApiResponse::data($engine->rfm($this->filter($request)));
    }

    public function abc(Request $request, AiEngineClient $engine): JsonResponse
    {
        return ApiResponse::data($engine->abc($this->filter($request)));
    }

    public function cohort(Request $request, AiEngineClient $engine): JsonResponse
    {
        return ApiResponse::data($engine->cohort($this->filter($request)));
    }

    public function branches(Request $request, AiEngineClient $engine): JsonResponse
    {
        return ApiResponse::data($engine->branches());
    }

    public function finance(Request $request, AiEngineClient $engine): JsonResponse
    {
        return ApiResponse::data($engine->finance());
    }

    /**
     * The documented filter set, validated before it is forwarded.
     *
     * `granularity` is documented as a closed set and every one of these is
     * passed straight to the engine, where an unchecked value becomes a
     * grouping key in the warehouse query. Validating here also means the
     * documented promise — a 422 with `errors` keyed by field — holds for the
     * analytics routes as it does everywhere else.
     *
     * @return array<string, mixed>
     */
    private function filter(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'branch' => ['nullable', 'string', 'max:50'],
            'category' => ['nullable', 'string', 'max:50'],
            'granularity' => ['nullable', 'string', Rule::in(['daily', 'weekly', 'monthly'])],
        ], [], [
            'date_from' => 'date from',
            'date_to' => 'date to',
            'granularity' => 'granularity',
        ]);

        return array_filter([
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'branch' => $validated['branch'] ?? null,
            'category' => $validated['category'] ?? null,
            'granularity' => (string) ($validated['granularity'] ?? 'daily'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
