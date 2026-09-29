<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiEngineException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AlertService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AlertController extends Controller
{
    public function __construct(private readonly AlertService $alerts) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(AlertService::STATUSES)],
            'rule_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return ApiResponse::data($this->alerts->alerts(
            $validated['status'] ?? null,
            isset($validated['rule_id']) ? (int) $validated['rule_id'] : null,
            (int) ($validated['limit'] ?? 50),
        ));
    }

    public function rules(): JsonResponse
    {
        return ApiResponse::data($this->alerts->rules());
    }

    public function events(int $id): JsonResponse
    {
        return ApiResponse::data($this->alerts->events($id));
    }

    public function acknowledge(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $row = $this->alerts->acknowledge($id, (string) ($validated['note'] ?? ''));
        } catch (AiEngineException $exception) {
            if ($exception->upstreamStatus() === 404) {
                return ApiResponse::error('Alert not found.', 404, 'not_found');
            }

            throw $exception;
        }

        AuditLog::record('alert.acknowledged', 'alert', $id, [
            'note_length' => mb_strlen((string) ($validated['note'] ?? '')),
        ]);

        return ApiResponse::data($row);
    }

    public function storeRule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'metric' => ['required', 'string', 'max:64'],
            'operator' => ['required', 'string', 'max:16'],
            'threshold' => ['required', 'numeric'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $rule = $this->alerts->createRule([
            'name' => $validated['name'],
            'metric' => $validated['metric'],
            'operator' => $validated['operator'],
            'threshold' => (float) $validated['threshold'],
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        AuditLog::record('alert.rule_created', 'alert_rule', (int) ($rule['id'] ?? 0), [
            'name' => $validated['name'],
        ]);

        return ApiResponse::data($rule, 201);
    }
}
