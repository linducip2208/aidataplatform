<?php

namespace App\Http\Controllers;

use App\Exceptions\AiEngineException;
use App\Services\AiEngineClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Throwable;

class AnalyticsController extends Controller
{
    private const KPI_DEFAULTS = [
        'revenue' => 0,
        'orders' => 0,
        'units' => 0,
        'aov' => 0,
        'growth_pct' => 0,
        'margin_pct' => 0,
    ];

    private const FINANCE_DEFAULTS = [
        'total_revenue' => 0,
        'total_cogs' => 0,
        'total_expenses' => 0,
        'gross_profit' => 0,
        'net_profit' => 0,
        'margin_pct' => 0,
    ];

    public function __invoke(Request $request, AiEngineClient $engine): View
    {
        return $this->render($request, $engine);
    }

    public function index(Request $request, AiEngineClient $engine): View
    {
        return $this->render($request, $engine);
    }

    private function render(Request $request, AiEngineClient $engine): View
    {
        $filter = $this->filter($request);
        $succeeded = 0;

        $kpi = $this->attempt($succeeded, self::KPI_DEFAULTS, fn (): array => $engine->kpi($filter));
        $trend = $this->attempt($succeeded, [], fn (): array => $engine->trend($filter));
        $rfm = $this->attempt($succeeded, [], fn (): array => $engine->rfm($filter));
        $abc = $this->attempt($succeeded, [], fn (): array => $engine->abc($filter));
        $cohort = $this->attempt($succeeded, [], fn (): array => $engine->cohort($filter));
        $branches = $this->attempt($succeeded, [], fn (): array => $engine->branches());
        $finance = $this->attempt($succeeded, self::FINANCE_DEFAULTS, fn (): array => $engine->finance());

        // Enterprise BI additions. All go through the direct-Http helpers so
        // the `AiEngineClient` surface (pinned by contract tests) stays
        // untouched; every one degrades to an empty default when the engine
        // is down, exactly like the calls above.
        $definitions = $this->attempt($succeeded, [], fn (): array => $this->engineGet(
            '/analytics/kpi/definitions', [], 'analytics.kpi.definitions'));
        $comparison = $this->attempt($succeeded, [], fn (): array => $this->enginePost(
            '/analytics/compare', ['current' => $filter, 'previous' => []], 'analytics.compare'));
        $drilldown = $this->attempt($succeeded, [], fn (): array => $this->enginePost(
            '/analytics/drilldown',
            ['dimension' => 'branch', 'metric' => 'revenue', 'filter' => $filter, 'limit' => 10],
            'analytics.drilldown'));
        $dashboard = $this->attempt($succeeded, [], fn (): array => $this->enginePost(
            '/analytics/dashboards/resolve',
            ['dashboard' => 'executive', 'filter' => $filter],
            'analytics.dashboards.resolve'));

        $evaluated = $this->evaluateKpis($kpi, $definitions);

        return view('analytics.index', [
            'kpi' => $kpi,
            'trend' => $trend,
            'rfm' => $rfm,
            'abc' => $abc,
            'cohort' => $cohort,
            'branches' => $branches,
            'finance' => $finance,
            'filters' => $filter,
            'engineAvailable' => $succeeded > 0,
            'kpiDefinitions' => $definitions,
            'kpiEvaluated' => $evaluated,
            'comparison' => $comparison,
            'drilldown' => $drilldown,
            'dashboard' => $dashboard,
        ]);
    }

    /**
     * Pair computed KPI values with their registry definitions so the view can
     * render target/threshold badges. Pure presentation math over values the
     * engine already returned — no invented numbers.
     *
     * @param  array<string, mixed>  $kpi
     * @param  array<int, mixed>  $definitions
     * @return array<int, array<string, mixed>>
     */
    private function evaluateKpis(array $kpi, array $definitions): array
    {
        $defs = [];

        foreach ($definitions as $def) {
            if (is_array($def) && isset($def['name'])) {
                $defs[(string) $def['name']] = $def;
            }
        }

        $out = [];

        foreach ($kpi as $name => $value) {
            $def = $defs[(string) $name] ?? [];
            $warn = isset($def['warn_threshold']) && is_numeric($def['warn_threshold'])
                ? (float) $def['warn_threshold'] : null;
            $crit = isset($def['crit_threshold']) && is_numeric($def['crit_threshold'])
                ? (float) $def['crit_threshold'] : null;
            $higher = (bool) ($def['higher_is_better'] ?? true);
            $num = is_numeric($value) ? (float) $value : 0.0;

            $status = 'ok';

            if ($higher) {
                if ($crit !== null && $num < $crit) {
                    $status = 'crit';
                } elseif ($warn !== null && $num < $warn) {
                    $status = 'warn';
                }
            } elseif ($crit !== null && $num > $crit) {
                $status = 'crit';
            } elseif ($warn !== null && $num > $warn) {
                $status = 'warn';
            }

            $out[] = [
                'name' => (string) $name,
                'value' => $num,
                'target' => isset($def['target']) && is_numeric($def['target']) ? (float) $def['target'] : null,
                'unit' => (string) ($def['unit'] ?? ''),
                'status' => $status,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|array<int, mixed>
     */
    private function engineGet(string $path, array $query, string $operation): array
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders($this->engineHeaders())
                ->timeout((int) config('ai_engine.timeout', 60))
                ->get($this->engineUrl($path), $query);
        } catch (Throwable $exception) {
            throw new AiEngineException($exception->getMessage(), 503, $operation);
        }

        if ($response->failed()) {
            throw new AiEngineException('AI engine request failed with status '.$response->status().'.', $response->status(), $operation);
        }

        $body = $response->json();

        if (! is_array($body)) {
            return [];
        }

        if (array_key_exists('success', $body)) {
            if ($body['success'] === false) {
                throw new AiEngineException('AI engine request failed.', 422, $operation, $body);
            }

            return (array) ($body['data'] ?? []);
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|array<int, mixed>
     */
    private function enginePost(string $path, array $payload, string $operation): array
    {
        try {
            $response = Http::asJson()->acceptJson()
                ->withHeaders($this->engineHeaders())
                ->timeout((int) config('ai_engine.timeout', 60))
                ->post($this->engineUrl($path), $payload);
        } catch (Throwable $exception) {
            throw new AiEngineException($exception->getMessage(), 503, $operation);
        }

        if ($response->failed()) {
            throw new AiEngineException('AI engine request failed with status '.$response->status().'.', $response->status(), $operation);
        }

        $body = $response->json();

        if (! is_array($body)) {
            return [];
        }

        if (array_key_exists('success', $body)) {
            if ($body['success'] === false) {
                throw new AiEngineException('AI engine request failed.', 422, $operation, $body);
            }

            return (array) ($body['data'] ?? []);
        }

        return $body;
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

    private function attempt(int &$succeeded, mixed $default, callable $call): mixed
    {
        try {
            $result = $call();

            $succeeded++;

            return $result;
        } catch (AiEngineException) {
            return $default;
        }
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
