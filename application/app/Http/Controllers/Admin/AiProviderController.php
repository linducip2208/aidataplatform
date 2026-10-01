<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use App\Models\AuditLog;
use App\Services\AiProviderService;
use App\Services\ModelRouter;
use App\Services\OpenCodeGoAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class AiProviderController extends Controller
{
    public function __construct(private readonly AiProviderService $providers) {}

    public function index(): View
    {
        return view('admin.providers.index', [
            'providers' => AiProvider::query()->withCount('models')->orderBy('priority')->orderBy('id')->get(),
            'active' => $this->providers->active(),
            'inContainer' => $this->providers->runningInContainer(),
        ]);
    }

    public function create(): View
    {
        return view('admin.providers.form', [
            'provider' => new AiProvider(['provider_type' => 'openai-compatible', 'priority' => 100, 'is_active' => true]),
            'method' => 'POST',
            'action' => route('admin.providers.store'),
            'opencodeGoBaseUrl' => (string) config('ai_providers.opencode_go.default_base_url'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePayload($request);

        $provider = AiProvider::query()->create([
            ...$validated,
            'created_by' => $request->user()->getKey(),
        ]);

        AuditLog::record('ai_provider.created', 'ai_provider', $provider->getKey(), [
            'name' => $provider->name,
        ]);

        return redirect()->route('admin.providers.index')
            ->with('status', "Provider '{$provider->name}' didaftarkan. Tempel API key untuk menguji atau menerbitkan.");
    }

    public function edit(AiProvider $provider): View
    {
        return view('admin.providers.form', [
            'provider' => $provider,
            'method' => 'PUT',
            'action' => route('admin.providers.update', $provider),
            'opencodeGoBaseUrl' => (string) config('ai_providers.opencode_go.default_base_url'),
        ]);
    }

    public function update(Request $request, AiProvider $provider): RedirectResponse
    {
        $provider->update($this->validatePayload($request));

        AuditLog::record('ai_provider.updated', 'ai_provider', $provider->getKey(), [
            'name' => $provider->name,
        ]);

        return redirect()->route('admin.providers.index')
            ->with('status', "Provider '{$provider->name}' diperbarui.");
    }

    public function destroy(AiProvider $provider): RedirectResponse
    {
        $name = $provider->name;
        $provider->delete();

        AuditLog::record('ai_provider.deleted', 'ai_provider', null, [
            'name' => $name,
        ]);

        return back()->with('status', "Provider '{$name}' dihapus. Engine tetap memakai konfigurasi terakhir hingga diterbitkan ulang.");
    }

    public function toggle(AiProvider $provider): RedirectResponse
    {
        $provider->forceFill(['is_active' => ! $provider->is_active])->save();

        AuditLog::record('ai_provider.toggled', 'ai_provider', $provider->getKey(), [
            'is_active' => $provider->is_active,
        ]);

        return back()->with('status', $provider->is_active ? 'Provider diaktifkan.' : 'Provider dinonaktifkan.');
    }

    /**
     * Test the connection with a caller-supplied key. The key is used for
     * this one probe and never stored. Responses-protocol providers are
     * probed via GET /models; everything else keeps the chat-completions
     * probe so existing providers behave exactly as before.
     */
    public function test(Request $request, AiProvider $provider): RedirectResponse
    {
        $validated = $request->validate([
            'api_key' => ['nullable', 'string', 'max:512'],
        ]);

        $apiKey = (string) ($validated['api_key'] ?? '');

        if ($provider->usesResponsesApi()) {
            $adapter = OpenCodeGoAdapter::forProvider($provider, $apiKey);
            $result = $adapter->testConnection();
            $result = ['ok' => $result['ok'], 'note' => $result['note']];
        } else {
            $result = $this->providers->testConnection($provider->toArray(), $apiKey);
        }

        $provider->forceFill([
            'last_tested_at' => now(),
            'last_test_status' => $result['ok'] ? 'ok' : 'failed',
            'last_test_note' => substr($result['note'], 0, 512),
        ])->save();

        AuditLog::record('ai_provider.tested', 'ai_provider', $provider->getKey(), [
            'ok' => $result['ok'],
        ]);

        return back()->with($result['ok'] ? 'status' : 'error', $result['note']);
    }

    /**
     * Publish the active configuration: native writes the engine env file,
     * Compose returns the block for the operator to apply.
     */
    public function publish(Request $request, AiProvider $provider): RedirectResponse
    {
        $validated = $request->validate([
            'api_key' => ['nullable', 'string', 'max:512'],
        ]);

        $apiKey = (string) ($validated['api_key'] ?? '');

        if ($apiKey === '' && $provider->provider_type !== 'ollama') {
            return back()->withInput()->with('error', 'API key wajib diisi kecuali untuk Ollama lokal.');
        }

        try {
            $result = $this->providers->publish($provider, $apiKey, $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        if ($result['written']) {
            return back()->with('status', "Diterbitkan ke {$result['path']}. Restart: {$result['restart']}.");
        }

        return back()->with('publish_block', $result['block'])
            ->with('publish_restart', $result['restart'])
            ->with('status', 'Berjalan di container: tempel blok di bawah ke root .env lalu jalankan perintah restart.');
    }

    /**
     * AJAX probe for the create/edit form: test a connection (or a model)
     * from raw form values, without saving anything. The key travels in
     * this request only and is never stored, logged, or returned.
     */
    public function probe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider_type' => ['required', 'string', 'in:'.implode(',', array_keys(AiProvider::TYPES))],
            'base_url' => ['required', 'string', 'max:512', 'url:http,https'],
            'model' => ['nullable', 'string', 'max:256'],
            'protocol' => ['nullable', 'string', 'in:responses,chat-completions'],
            'timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:600'],
            'max_retries' => ['nullable', 'integer', 'min:0', 'max:10'],
            'api_key' => ['nullable', 'string', 'max:512'],
            'action' => ['nullable', 'string', 'in:connection,model'],
        ]);

        $provider = new AiProvider([
            'provider_type' => $validated['provider_type'],
            'protocol' => $validated['protocol'] ?? null,
            'base_url' => $validated['base_url'],
            'model' => $validated['model'] ?? '',
            'timeout_seconds' => $validated['timeout_seconds'] ?? null,
            'max_retries' => $validated['max_retries'] ?? null,
        ]);

        $apiKey = (string) ($validated['api_key'] ?? '');

        if (($validated['action'] ?? 'connection') === 'model') {
            if (trim((string) ($validated['model'] ?? '')) === '') {
                return response()->json(['ok' => false, 'note' => 'Isi field model dulu sebelum uji model.'], 422);
            }

            if (! $provider->usesResponsesApi()) {
                return response()->json(['ok' => false, 'note' => 'Uji model memakai Responses API dan hanya untuk tipe OpenCode Go.'], 422);
            }

            $result = (new ModelRouter)->adapterFor($provider, $apiKey)->testModel($validated['model']);

            AuditLog::record('ai_provider.probed', 'ai_provider', null, [
                'action' => 'model',
                'provider_type' => $provider->provider_type,
                'ok' => $result['ok'],
            ], $request->user());

            return response()->json($result);
        }

        if ($provider->usesResponsesApi()) {
            $result = (new ModelRouter)->adapterFor($provider, $apiKey)->testConnection();

            AuditLog::record('ai_provider.probed', 'ai_provider', null, [
                'action' => 'connection',
                'provider_type' => $provider->provider_type,
                'ok' => $result['ok'],
            ], $request->user());

            return response()->json($result);
        }

        $result = $this->providers->testConnection($provider->toArray(), $apiKey);

        return response()->json($result);
    }

    /**
     * AJAX model discovery. With `provider_id` the catalog is persisted to
     * ai_provider_models; with raw attributes it is a preview only and
     * nothing is written. Muse Spark appears automatically whenever the
     * API advertises it.
     */
    public function discover(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider_id' => ['nullable', 'integer', 'exists:ai_providers,id'],
            'provider_type' => ['nullable', 'string', 'in:'.implode(',', array_keys(AiProvider::TYPES))],
            'base_url' => ['nullable', 'string', 'max:512', 'url:http,https'],
            'protocol' => ['nullable', 'string', 'in:responses,chat-completions'],
            'timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:600'],
            'max_retries' => ['nullable', 'integer', 'min:0', 'max:10'],
            'api_key' => ['nullable', 'string', 'max:512'],
        ]);

        $provider = isset($validated['provider_id'])
            ? AiProvider::query()->findOrFail($validated['provider_id'])
            : new AiProvider([
                'provider_type' => $validated['provider_type'] ?? 'opencode-go',
                'protocol' => $validated['protocol'] ?? null,
                'base_url' => $validated['base_url'] ?? '',
                'timeout_seconds' => $validated['timeout_seconds'] ?? null,
                'max_retries' => $validated['max_retries'] ?? null,
            ]);

        if (! $provider->usesResponsesApi()) {
            return response()->json(['ok' => false, 'note' => 'Discovery model memakai endpoint /models dan hanya untuk tipe OpenCode Go.'], 422);
        }

        $apiKey = (string) ($validated['api_key'] ?? '');
        $adapter = OpenCodeGoAdapter::forProvider($provider, $apiKey);

        $probe = $adapter->testConnection();

        if (! $probe['ok']) {
            if (! $request->wantsJson()) {
                return back()->with('error', $probe['note']);
            }

            return response()->json(['ok' => false, 'note' => $probe['note'], 'status' => $probe['status'], 'latency_ms' => $probe['latency_ms'], 'models' => []]);
        }

        $discovered = $adapter->discoverModels();
        $persisted = 0;

        if ($provider->exists) {
            foreach ($discovered['models'] as $model) {
                $provider->models()->updateOrCreate(
                    ['external_id' => $model['external_id']],
                    [
                        'name' => $model['name'],
                        'capabilities' => $model['capabilities'] === [] ? null : $model['capabilities'],
                        'metadata' => $model['metadata'] === [] ? null : $model['metadata'],
                        'is_active' => true,
                        'last_seen_at' => now(),
                    ],
                );

                $persisted++;
            }

            AuditLog::record('ai_provider.models_discovered', 'ai_provider', $provider->getKey(), [
                'name' => $provider->name,
                'count' => $persisted,
            ], $request->user());
        }

        // Classic form posts (index row) get a redirect; the form-page AJAX
        // panel consumes JSON.
        if (! $request->wantsJson()) {
            if (! $probe['ok']) {
                return back()->with('error', $probe['note']);
            }

            return back()->with('status', "Ditemukan {$discovered['raw_count']} model, {$persisted} tersimpan.");
        }

        return response()->json([
            'ok' => true,
            'note' => $persisted > 0
                ? "Ditemukan {$discovered['raw_count']} model, {$persisted} tersimpan."
                : "Ditemukan {$discovered['raw_count']} model (pratinjau, belum tersimpan).",
            'persisted' => $persisted,
            'latency_ms' => $probe['latency_ms'],
            'models' => $discovered['models'],
        ]);
    }

    /**
     * AJAX model inference test against a saved provider: one real
     * POST /responses call. Updates the provider's test columns.
     */
    public function testModel(Request $request, AiProvider $provider): JsonResponse
    {
        $validated = $request->validate([
            'api_key' => ['nullable', 'string', 'max:512'],
            'model' => ['nullable', 'string', 'max:256'],
        ]);

        if (! $provider->usesResponsesApi()) {
            return response()->json(['ok' => false, 'note' => 'Uji model memakai Responses API dan hanya untuk tipe OpenCode Go.'], 422);
        }

        $model = trim((string) ($validated['model'] ?? $provider->model));

        $result = (new ModelRouter)->adapterFor($provider, (string) ($validated['api_key'] ?? ''))->testModel($model);

        $provider->forceFill([
            'last_tested_at' => now(),
            'last_test_status' => $result['ok'] ? 'ok' : 'failed',
            'last_test_note' => substr($result['note'], 0, 512),
        ])->save();

        AuditLog::record('ai_provider.model_tested', 'ai_provider', $provider->getKey(), [
            'model' => $model,
            'ok' => $result['ok'],
        ], $request->user());

        return response()->json($result);
    }

    /** @return array<string, mixed> */
    private function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'provider_type' => ['required', 'string', 'in:'.implode(',', array_keys(AiProvider::TYPES))],
            'base_url' => ['required', 'string', 'max:512', 'url:http,https'],
            'model' => ['required', 'string', 'max:256'],
            'embedding_model' => ['nullable', 'string', 'max:256'],
            'capabilities' => ['nullable', 'string', 'max:2000'],
            'input_price_per_million' => ['nullable', 'numeric', 'min:0'],
            'output_price_per_million' => ['nullable', 'numeric', 'min:0'],
            'protocol' => ['nullable', 'string', 'in:responses,chat-completions'],
            'timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:600'],
            'max_retries' => ['nullable', 'integer', 'min:0', 'max:10'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
        ], [], [
            'name' => 'nama',
            'provider_type' => 'tipe provider',
            'base_url' => 'base URL',
            'model' => 'model',
        ]);

        $capabilities = collect(explode(',', (string) ($validated['capabilities'] ?? '')))
            ->map(static fn (string $item): string => trim($item))
            ->filter()
            ->values()
            ->all();

        return [
            'name' => $validated['name'],
            'provider_type' => $validated['provider_type'],
            'base_url' => $validated['base_url'],
            'model' => $validated['model'],
            'embedding_model' => $validated['embedding_model'] ?? null,
            'capabilities' => $capabilities === [] ? null : $capabilities,
            'input_price_per_million' => $validated['input_price_per_million'] ?? null,
            'output_price_per_million' => $validated['output_price_per_million'] ?? null,
            'protocol' => $validated['protocol'] ?? null,
            'timeout_seconds' => $validated['timeout_seconds'] ?? null,
            'max_retries' => $validated['max_retries'] ?? null,
            'priority' => (int) ($validated['priority'] ?? 100),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ];
    }
}
