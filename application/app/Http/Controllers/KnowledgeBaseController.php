<?php

namespace App\Http\Controllers;

use App\Exceptions\AiEngineException;
use App\Services\AiEngineClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

class KnowledgeBaseController extends Controller
{
    public function index(): View
    {
        try {
            $documents = $this->engineGet('/rag/documents', ['limit' => 100], 'rag.documents');
            $engineAvailable = true;
        } catch (AiEngineException $exception) {
            $documents = [];
            $engineAvailable = false;
        }

        return view('knowledge.index', [
            'documents' => is_array($documents) ? $documents : [],
            'engineAvailable' => $engineAvailable,
        ]);
    }

    public function store(Request $request, AiEngineClient $engine): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'content' => ['required', 'string', 'max:500000'],
            'visibility' => ['nullable', 'string', 'in:public,internal,confidential,private'],
        ], [], [
            'title' => 'judul',
            'content' => 'isi dokumen',
            'visibility' => 'visibilitas',
        ]);

        $query = array_filter([
            'visibility' => $validated['visibility'] ?? null,
            'owner' => (string) $request->user()->getKey(),
        ], static fn (mixed $value): bool => $value !== null);

        try {
            $response = Http::asJson()->acceptJson()
                ->withHeaders($this->headers())
                ->timeout((int) config('ai_engine.timeout', 60))
                ->post(
                    $this->url('/rag/ingest'.($query === [] ? '' : '?'.http_build_query($query))),
                    [
                        'title' => $validated['title'],
                        'content' => $validated['content'],
                        'source' => 'web',
                    ]
                );
        } catch (ConnectionException $exception) {
            return back()->withInput()->with('error', 'Mesin AI tidak dapat dihubungi.');
        }

        if ($response->failed()) {
            $message = data_get($response->json(), 'error.message', 'Mesin AI menolak dokumen.');

            return back()->withInput()->with('error', $message);
        }

        return back()->with('status', "Dokumen '{$validated['title']}' diindeks.");
    }

    /** @return array<string, mixed>|array<int, mixed> */
    private function engineGet(string $path, array $query, string $operation): array
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders($this->headers())
                ->timeout((int) config('ai_engine.timeout', 60))
                ->get($this->url($path), $query);
        } catch (ConnectionException $exception) {
            throw new AiEngineException('Mesin AI tidak dapat dihubungi.', 503, $operation);
        }

        if ($response->failed()) {
            throw new AiEngineException('Mesin AI gagal memuat dokumen.', $response->status(), $operation);
        }

        $body = $response->json();

        if (! is_array($body) || ($body['success'] ?? null) === false) {
            throw new AiEngineException('Mesin AI gagal memuat dokumen.', 422, $operation);
        }

        $data = $body['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            (string) config('ai_engine.service_key_header', 'X-Service-Key') => (string) config('ai_engine.service_key', ''),
            'X-Client' => 'laravel-orchestrator',
        ];
    }

    private function url(string $path): string
    {
        return rtrim((string) config('ai_engine.base_url'), '/').'/api/v1/'.ltrim($path, '/');
    }
}
