<?php

namespace App\Http\Controllers;

use App\Exceptions\AiEngineException;
use App\Models\AuditLog;
use App\Services\AlertService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AlertController extends Controller
{
    public function __construct(private readonly AlertService $alerts) {}

    public function index(Request $request): View
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:open,acknowledged,resolved'],
            'rule_id' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $alerts = $this->alerts->alerts(
                $validated['status'] ?? null,
                isset($validated['rule_id']) ? (int) $validated['rule_id'] : null,
            );
            $rules = $this->alerts->rules();
            $catalog = $this->alerts->catalog();
            $engineAvailable = true;
        } catch (AiEngineException $exception) {
            $alerts = [];
            $rules = [];
            $catalog = [];
            $engineAvailable = false;
        }

        return view('alerts.index', [
            'alerts' => $alerts,
            'rules' => $rules,
            'metrics' => (array) ($catalog['metrics'] ?? []),
            'statuses' => AlertService::STATUSES,
            'filters' => $request->only(['status', 'rule_id']),
            'engineAvailable' => $engineAvailable,
        ]);
    }

    public function acknowledge(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->alerts->acknowledge($id, (string) ($validated['note'] ?? ''));
        } catch (AiEngineException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        AuditLog::record('alert.acknowledged', 'alert', $id, [
            'note_length' => mb_strlen((string) ($validated['note'] ?? '')),
        ]);

        return back()->with('status', "Peringatan #{$id} ditandai sudah dibaca.");
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'metric' => ['required', 'string', 'max:64'],
            'operator' => ['required', 'string', 'max:16'],
            'threshold' => ['required', 'numeric'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        try {
            $rule = $this->alerts->createRule([
                'name' => $validated['name'],
                'metric' => $validated['metric'],
                'operator' => $validated['operator'],
                'threshold' => (float) $validated['threshold'],
                'is_active' => (bool) ($validated['is_active'] ?? true),
            ]);
        } catch (AiEngineException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        AuditLog::record('alert.rule_created', 'alert_rule', (int) ($rule['id'] ?? 0), [
            'name' => $validated['name'],
            'metric' => $validated['metric'],
        ]);

        return back()->with('status', "Aturan '{$validated['name']}' dibuat.");
    }

    public function toggleRule(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        try {
            $this->alerts->setRuleActive($id, (bool) $validated['is_active']);
        } catch (AiEngineException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        AuditLog::record('alert.rule_toggled', 'alert_rule', $id, [
            'is_active' => (bool) $validated['is_active'],
        ]);

        return back()->with('status', ((bool) $validated['is_active'] ? 'Aturan diaktifkan.' : 'Aturan dinonaktifkan.'));
    }
}
