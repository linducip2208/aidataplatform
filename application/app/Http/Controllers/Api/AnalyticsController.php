<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiEngineException;
use App\Http\Controllers\Controller;
use App\Services\AiEngineClient;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        return array_filter([
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'branch' => $request->query('branch'),
            'category' => $request->query('category'),
            'granularity' => (string) $request->query('granularity', 'daily'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
