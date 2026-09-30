<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use App\Models\AuditLog;
use App\Services\AiProviderService;
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
            'providers' => AiProvider::query()->orderBy('priority')->orderBy('id')->get(),
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
     * this one probe and never stored.
     */
    public function test(Request $request, AiProvider $provider): RedirectResponse
    {
        $validated = $request->validate([
            'api_key' => ['nullable', 'string', 'max:512'],
        ]);

        $result = $this->providers->testConnection($provider->toArray(), (string) ($validated['api_key'] ?? ''));

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
            'priority' => (int) ($validated['priority'] ?? 100),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ];
    }
}
