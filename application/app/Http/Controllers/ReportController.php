<?php

namespace App\Http\Controllers;

use App\Exceptions\AiEngineException;
use App\Models\GeneratedReport;
use App\Services\AiEngineClient;
use App\Services\ReportGenerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Throwable;

class ReportController extends Controller
{
    private const PERIODS = ['daily', 'weekly', 'monthly'];

    public function __invoke(Request $request, AiEngineClient $engine): View
    {
        $request->validate([
            'period' => ['nullable', 'string', 'in:'.implode(',', self::PERIODS)],
        ], [], ['period' => 'periode']);

        $period = (string) $request->query('period', 'weekly');
        $report = [];
        $engineAvailable = true;

        try {
            $report = $engine->report($period);
        } catch (AiEngineException) {
            $engineAvailable = false;
        }

        // Automated reports: KPI snapshots persisted by `bi.snapshot_kpis`.
        // Direct-Http on purpose (see Api\AnalyticsController): the client
        // surface is pinned by contract tests. Engine-down degrades to an
        // empty list, never a 500.
        $snapshots = [];
        $snapshotsAvailable = false;

        try {
            $snapshots = $this->engineSnapshots();
            $snapshotsAvailable = true;
        } catch (AiEngineException) {
            $snapshots = [];
        }

        $history = GeneratedReport::query()
            ->with('creator')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return view('reports.index', [
            'report' => $report,
            'periods' => self::PERIODS,
            'period' => $period,
            'engineAvailable' => $engineAvailable,
            'snapshots' => $snapshots,
            'snapshotsAvailable' => $snapshotsAvailable,
            'history' => $history,
        ]);
    }

    public function store(Request $request, ReportGenerationService $service): RedirectResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', 'string', 'in:'.implode(',', self::PERIODS)],
        ], [], ['period' => 'periode']);

        try {
            $report = $service->generate(
                (string) ($validated['period'] ?? 'weekly'),
                $request->user(),
            );
        } catch (AiEngineException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('status', "Laporan #{$report->getKey()} disimpan ke riwayat.");
    }

    public function index(Request $request, AiEngineClient $engine): View
    {
        return $this->__invoke($request, $engine);
    }

    /** @return array<int, array<string, mixed>> */
    private function engineSnapshots(): array
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    (string) config('ai_engine.service_key_header', 'X-Service-Key') => (string) config('ai_engine.service_key', ''),
                    'X-Client' => 'laravel-orchestrator',
                ])
                ->timeout((int) config('ai_engine.timeout', 60))
                ->get(
                    rtrim((string) config('ai_engine.base_url'), '/').'/api/v1/analytics/kpi/history',
                    ['limit' => 50]
                );
        } catch (Throwable $exception) {
            throw new AiEngineException($exception->getMessage(), 503, 'analytics.kpi.history');
        }

        if ($response->failed()) {
            throw new AiEngineException('AI engine request failed with status '.$response->status().'.', $response->status(), 'analytics.kpi.history');
        }

        $body = $response->json();

        if (! is_array($body)) {
            return [];
        }

        if (array_key_exists('success', $body)) {
            if ($body['success'] === false) {
                throw new AiEngineException('AI engine request failed.', 422, 'analytics.kpi.history', $body);
            }

            $data = (array) ($body['data'] ?? []);

            return array_is_list($data) ? $data : [];
        }

        return [];
    }
}
