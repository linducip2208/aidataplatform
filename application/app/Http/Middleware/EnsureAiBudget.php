<?php

namespace App\Http\Middleware;

use App\Services\AiCostService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Monthly AI spend ceiling for the expensive endpoints.
 *
 * Disabled when `ai_engine.ai_monthly_budget_usd` is 0/negative. Otherwise
 * the month-to-date estimated cost (5-minute cached engine summary) is
 * compared against the budget; at or over budget the request is refused
 * with 429 `budget_exceeded`. An unreachable engine fails OPEN — a
 * monitoring outage must not cascade into a full AI outage — and is logged.
 */
class EnsureAiBudget
{
    public function __construct(private readonly AiCostService $costs) {}

    public function handle(Request $request, Closure $next): Response
    {
        $budget = (float) config('ai_engine.ai_monthly_budget_usd', 0);

        if ($budget <= 0) {
            return $next($request);
        }

        try {
            $summary = Cache::remember(
                'ai.budget.summary',
                now()->addMinutes(5),
                fn (): array => $this->costs->summary(30),
            );
            $spent = (float) ($summary['totals']['estimated_cost_total'] ?? 0);
        } catch (Throwable $exception) {
            Log::warning('ai.budget_unavailable', [
                'error' => substr($exception->getMessage(), 0, 160),
            ]);

            return $next($request);
        }

        if ($spent < $budget) {
            return $next($request);
        }

        Log::warning('ai.budget_exceeded', [
            'spent' => $spent,
            'budget' => $budget,
            'path' => substr($request->path(), 0, 120),
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Anggaran AI bulanan tercapai. Coba lagi bulan depan atau naikkan AI_MONTHLY_BUDGET_USD.',
                'code' => 'budget_exceeded',
                'spent' => $spent,
                'budget' => $budget,
            ], 429);
        }

        abort(429, 'Anggaran AI bulanan tercapai.');
    }
}
