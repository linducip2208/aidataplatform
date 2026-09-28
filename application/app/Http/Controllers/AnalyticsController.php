<?php

namespace App\Http\Controllers;

use App\Exceptions\AiEngineException;
use App\Services\AiEngineClient;
use Illuminate\Http\Request;
use Illuminate\View\View;

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
        ]);
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
