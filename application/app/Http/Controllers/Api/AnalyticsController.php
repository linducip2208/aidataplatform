<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiEngineException;
use App\Http\Controllers\Controller;
use App\Services\AiEngineClient;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    // ------------------------------------------------------------------
    // Enterprise BI (additive; methods above are untouched in behaviour).
    //
    // These proxies intentionally go through `Http` directly instead of
    // `AiEngineClient`: the client surface is pinned by
    // `EngineClientContractTest::test_every_public_method_of_the_client_is_pinned_here`,
    // so adding public methods there would break the existing suite. The
    // envelope handling below mirrors `AiEngineClient::unwrap()` — the engine
    // answers `{success, data}` and failures surface as `AiEngineException`
    // rendered by `bootstrap/app.php` with `code: ai_engine_error`.
    // ------------------------------------------------------------------

    public function kpiDefinitions(): JsonResponse
    {
        return ApiResponse::data($this->engineGet('/analytics/kpi/definitions', [], 'analytics.kpi.definitions'));
    }

    public function storeKpiDefinition(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'description' => ['nullable', 'string', 'max:1024'],
            'formula' => ['nullable', 'string', 'max:1024'],
            'unit' => ['nullable', 'string', 'max:32'],
            'target' => ['nullable', 'numeric'],
            'warn_threshold' => ['nullable', 'numeric'],
            'crit_threshold' => ['nullable', 'numeric'],
            'higher_is_better' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return ApiResponse::data(
            $this->enginePost('/analytics/kpi/definitions', $validated, 'analytics.kpi.definitions.store'),
            201
        );
    }

    public function kpiHistory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kpi_name' => ['nullable', 'string', 'max:128'],
            'period' => ['nullable', 'string', 'max:32'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $query = array_filter([
            'kpi_name' => $validated['kpi_name'] ?? null,
            'period' => $validated['period'] ?? null,
            'limit' => $validated['limit'] ?? 100,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return ApiResponse::data($this->engineGet('/analytics/kpi/history', $query, 'analytics.kpi.history'));
    }

    public function compare(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current' => ['nullable', 'array'],
            'previous' => ['nullable', 'array'],
            'current.date_from' => ['nullable', 'date_format:Y-m-d'],
            'current.date_to' => ['nullable', 'date_format:Y-m-d'],
            'previous.date_from' => ['nullable', 'date_format:Y-m-d'],
            'previous.date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return ApiResponse::data($this->enginePost('/analytics/compare', [
            'current' => $validated['current'] ?? [],
            'previous' => $validated['previous'] ?? [],
        ], 'analytics.compare'));
    }

    public function drilldown(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dimension' => ['required', 'string', Rule::in(['branch', 'product', 'customer'])],
            'metric' => ['nullable', 'string', 'max:32'],
            'filter' => ['nullable', 'array'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        return ApiResponse::data($this->enginePost('/analytics/drilldown', [
            'dimension' => $validated['dimension'],
            'metric' => $validated['metric'] ?? 'revenue',
            'filter' => $validated['filter'] ?? [],
            'limit' => $validated['limit'] ?? 50,
        ], 'analytics.drilldown'));
    }

    public function dashboard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dashboard' => ['required', 'string', Rule::in([
                'executive', 'sales', 'finance', 'customer',
                'inventory', 'operations', 'marketing', 'management',
            ])],
            'filter' => ['nullable', 'array'],
        ]);

        return ApiResponse::data($this->enginePost('/analytics/dashboards/resolve', [
            'dashboard' => $validated['dashboard'],
            'filter' => $validated['filter'] ?? [],
        ], 'analytics.dashboards.resolve'));
    }

    /**
     * Proxy `POST /analytics/export` and stream the engine file back as a
     * download. Validation rejects anything but csv|xlsx before the engine is
     * hit; pdf is an explicit boundary (see `docs/bi.md`) and never reaches
     * the engine here.
     */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $validated = $request->validate([
            'format' => ['required', 'string', Rule::in(['csv', 'xlsx'])],
            'dataset' => ['nullable', 'string', 'max:32'],
            'filter' => ['nullable', 'array'],
            'rows' => ['nullable', 'array'],
            'columns' => ['nullable', 'array'],
            'filename' => ['nullable', 'string', 'max:64'],
        ]);

        $payload = [
            'format' => $validated['format'],
            'dataset' => $validated['dataset'] ?? '',
            'filter' => $validated['filter'] ?? [],
            'rows' => $validated['rows'] ?? null,
            'columns' => $validated['columns'] ?? null,
            'filename' => $validated['filename'] ?? 'export',
        ];

        $response = $this->engineRawPost('/analytics/export', $payload, 'analytics.export');
        $contentType = (string) $response->header('Content-Type', '');
        $disposition = (string) $response->header('Content-Disposition', '');
        $body = (string) $response->getBody();

        if ($disposition === '') {
            $ext = $validated['format'] === 'xlsx' ? 'xlsx' : 'csv';
            $disposition = 'attachment; filename="export.'.$ext.'"';
        }

        if ($contentType === '') {
            $contentType = $validated['format'] === 'xlsx'
                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                : 'text/csv; charset=utf-8';
        }

        return new StreamedResponse(function () use ($body): void {
            echo $body;
        }, Response::HTTP_OK, [
            'Content-Type' => $contentType,
            'Content-Disposition' => $disposition,
        ]);
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

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|array<int, mixed>
     */
    private function engineGet(string $path, array $query, string $operation): array
    {
        $response = Http::acceptJson()
            ->withHeaders($this->engineHeaders())
            ->timeout((int) config('ai_engine.timeout', 60))
            ->get($this->engineUrl($path), $query);

        return $this->unwrap($response, $operation);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|array<int, mixed>
     */
    private function enginePost(string $path, array $payload, string $operation): array
    {
        $response = Http::asJson()->acceptJson()
            ->withHeaders($this->engineHeaders())
            ->timeout((int) config('ai_engine.timeout', 60))
            ->post($this->engineUrl($path), $payload);

        return $this->unwrap($response, $operation);
    }

    /**
     * Raw POST for the file endpoint: the engine answers with bytes, not the
     * `{success, data}` envelope, so there is nothing to unwrap here — error
     * JSON still throws via `unwrap()`.
     *
     * @param  array<string, mixed>  $payload
     */
    private function engineRawPost(string $path, array $payload, string $operation): \Illuminate\Http\Client\Response
    {
        $response = Http::asJson()->acceptJson()
            ->withHeaders($this->engineHeaders())
            ->timeout((int) config('ai_engine.timeout', 60))
            ->post($this->engineUrl($path), $payload);

        $contentType = (string) $response->header('Content-Type', '');

        if ($response->failed() || str_contains($contentType, 'application/json')) {
            $this->unwrap($response, $operation);
        }

        return $response;
    }

    /** @return array<string, string> */
    private function engineHeaders(): array
    {
        return [
            (string) config('ai_engine.service_key_header', 'X-Service-Key') => (string) config('ai_engine.service_key', ''),
            'X-Client' => 'laravel-orchestrator',
        ];
    }

    private function engineUrl(string $path): string
    {
        return rtrim((string) config('ai_engine.base_url'), '/').'/api/v1/'.ltrim($path, '/');
    }

    /**
     * Mirror of `AiEngineClient::unwrap()` for the direct-Http proxies above.
     *
     * @return array<string, mixed>|array<int, mixed>
     */
    private function unwrap(\Illuminate\Http\Client\Response $response, string $operation): array
    {
        if ($response->failed()) {
            throw new AiEngineException(
                $this->engineErrorMessage($response),
                $response->status(),
                $operation,
                $response->json()
            );
        }

        $body = $response->json();

        if (! is_array($body)) {
            return [];
        }

        if (array_key_exists('success', $body)) {
            if ($body['success'] === false) {
                throw new AiEngineException($this->engineErrorMessage($response), 422, $operation, $body);
            }

            return (array) ($body['data'] ?? []);
        }

        return $body;
    }

    private function engineErrorMessage(\Illuminate\Http\Client\Response $response): string
    {
        $body = $response->json();

        if (is_array($body)) {
            $message = data_get($body, 'error.message')
                ?? data_get($body, 'detail')
                ?? data_get($body, 'message');

            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return match (true) {
            $response->status() === 422 => 'AI engine rejected the request payload.',
            $response->status() >= 500 => 'AI engine returned a server error ('.$response->status().').',
            default => 'AI engine request failed with status '.$response->status().'.',
        };
    }
}
