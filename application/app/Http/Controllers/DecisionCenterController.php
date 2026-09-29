<?php

namespace App\Http\Controllers;

use App\Exceptions\AiEngineException;
use App\Models\AuditLog;
use App\Services\DecisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DecisionCenterController extends Controller
{
    public function __construct(private readonly DecisionService $decisions) {}

    public function index(Request $request): View
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        try {
            $cases = $this->decisions->cases((int) ($validated['limit'] ?? 50));
            $rules = $this->decisions->rules();
            $engineAvailable = true;
        } catch (AiEngineException $exception) {
            $cases = [];
            $rules = [];
            $engineAvailable = false;
        }

        return view('decisions.index', [
            'cases' => is_array($cases) ? $cases : [],
            'rules' => is_array($rules) ? $rules : [],
            'scenarioResult' => session('scenario_result'),
            'engineAvailable' => $engineAvailable,
        ]);
    }

    public function show(int $id): View
    {
        try {
            $case = $this->decisions->case($id);
        } catch (AiEngineException $exception) {
            if ($exception->upstreamStatus() === 404) {
                abort(404, 'Kasus keputusan tidak ditemukan.');
            }

            throw $exception;
        }

        return view('decisions.show', [
            'case' => is_array($case) ? $case : [],
        ]);
    }

    public function recommend(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'dataset_ref' => ['nullable', 'string', 'max:256'],
            'branch' => ['nullable', 'string', 'max:128'],
            'period' => ['nullable', 'string', 'max:32'],
            'granularity' => ['nullable', 'string', Rule::in(['daily', 'weekly', 'monthly'])],
            'horizon' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $subject = array_filter([
            'dataset_ref' => $validated['dataset_ref'] ?? null,
            'branch' => $validated['branch'] ?? null,
            'period' => $validated['period'] ?? null,
            'granularity' => $validated['granularity'] ?? null,
            'horizon' => $validated['horizon'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);

        try {
            $result = $this->decisions->recommend($subject);
        } catch (AiEngineException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        $caseId = (int) ($result['case_id'] ?? 0);

        AuditLog::record('decision.recommended', 'decision_case', $caseId ?: null, [
            'recommendations' => count((array) ($result['recommendations'] ?? [])),
        ]);

        if ($caseId <= 0) {
            return back()->with('error', 'Mesin tidak mengembalikan ID kasus.');
        }

        return redirect()
            ->route('decisions.show', ['id' => $caseId])
            ->with('status', "Kasus keputusan #{$caseId} dibuat.");
    }

    public function runScenario(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(['price_change_pct', 'inventory_change_pct', 'churn_rise_pp'])],
            'value' => ['required', 'numeric'],
            'branch' => ['nullable', 'string', 'max:128'],
        ]);

        $paramKey = match ($validated['type']) {
            'churn_rise_pp' => 'churn_rise_pp',
            'inventory_change_pct' => 'inventory_change_pct',
            default => 'price_change_pct',
        };

        try {
            $result = $this->decisions->runScenario(
                $validated['type'],
                [$paramKey => (float) $validated['value']],
                array_filter(['branch' => $validated['branch'] ?? null]),
            );
        } catch (AiEngineException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('scenario_result', $result);
    }

    public function audit(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', 'max:64'],
            'rationale' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->decisions->audit(
                $id,
                (string) $request->user()->name,
                $validated['decision'],
                (string) ($validated['rationale'] ?? ''),
            );
        } catch (AiEngineException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        AuditLog::record('decision.audited', 'decision_case', $id, [
            'decision' => $validated['decision'],
        ]);

        return back()->with('status', "Keputusan untuk kasus #{$id} dicatat.");
    }
}
