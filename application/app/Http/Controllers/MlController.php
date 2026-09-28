<?php

namespace App\Http\Controllers;

use App\Exceptions\AiEngineException;
use App\Models\AuditLog;
use App\Services\AiEngineClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MlController extends Controller
{
    private const MODEL_TYPES = ['forecast', 'churn', 'segmentation', 'anomaly', 'recommend'];

    private const VERSION_STATUSES = ['PRODUCTION', 'STAGED', 'ARCHIVED'];

    public function index(Request $request, AiEngineClient $engine): View
    {
        $models = [];
        $selected = null;
        $error = null;

        try {
            $models = $engine->models();

            if ($request->filled('model')) {
                $selected = $engine->model((int) $request->query('model'));
            }
        } catch (AiEngineException $exception) {
            $error = $exception->getMessage();
        }

        return view('ml.index', [
            'models' => $models,
            'selected' => $selected,
            'modelTypes' => self::MODEL_TYPES,
            'canApprove' => $request->user()->canApproveModels(),
            'error' => $error,
        ]);
    }

    public function train(Request $request, AiEngineClient $engine): RedirectResponse
    {
        $validated = $request->validate([
            'model_type' => ['required', 'string', 'in:'.implode(',', self::MODEL_TYPES)],
            'name' => ['required', 'string', 'max:100'],
            'params' => ['nullable', 'string', 'max:4000'],
        ], [], [
            'model_type' => 'tipe model',
            'name' => 'nama model',
            'params' => 'parameter',
        ]);

        $result = $engine->train($validated['model_type'], $validated['name'], $this->params($validated['params'] ?? null));

        AuditLog::record('model.trained', 'model', (int) ($result['model_id'] ?? 0), [
            'model_type' => $validated['model_type'],
            'name' => $validated['name'],
            'version' => (string) ($result['version'] ?? ''),
        ]);

        return redirect()
            ->route('ml.index')
            ->with('status', "Model {$result['model_id']} versi {$result['version']} selesai dilatih.");
    }

    public function promote(Request $request, AiEngineClient $engine, int $modelId): RedirectResponse
    {
        $validated = $request->validate([
            'version_id' => ['required', 'integer', 'min:1'],
            'to_status' => ['nullable', 'string', 'in:'.implode(',', self::VERSION_STATUSES)],
        ], [], [
            'version_id' => 'versi',
            'to_status' => 'status tujuan',
        ]);

        $versionId = (int) $validated['version_id'];
        $toStatus = $validated['to_status'] ?? 'PRODUCTION';

        $engine->promoteModel($modelId, $versionId, $toStatus);

        AuditLog::record('model.promoted', 'model', $modelId, [
            'version_id' => $versionId,
            'to_status' => $toStatus,
        ]);

        return back()->with('status', "Model {$modelId} versi {$versionId} dipindahkan ke {$toStatus}.");
    }

    /** @return array<string, mixed> */
    private function params(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'params' => 'Parameter harus berupa JSON yang valid.',
            ]);
        }

        return $decoded;
    }
}
