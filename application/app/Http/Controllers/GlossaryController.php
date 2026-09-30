<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

class GlossaryController extends Controller
{
    public function index(): View
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    (string) config('ai_engine.service_key_header', 'X-Service-Key') => (string) config('ai_engine.service_key', ''),
                    'X-Client' => 'laravel-orchestrator',
                ])
                ->timeout((int) config('ai_engine.timeout', 60))
                ->get(
                    rtrim((string) config('ai_engine.base_url'), '/').'/api/v1/semantic/metrics'
                );
        } catch (ConnectionException $exception) {
            return view('glossary.index', [
                'version' => null,
                'metrics' => [],
                'engineAvailable' => false,
            ]);
        }

        if ($response->failed()) {
            return view('glossary.index', [
                'version' => null,
                'metrics' => [],
                'engineAvailable' => false,
            ]);
        }

        $body = $response->json();
        $data = is_array($body) ? ($body['data'] ?? []) : [];

        return view('glossary.index', [
            'version' => is_array($data) ? ($data['version'] ?? null) : null,
            'metrics' => is_array($data) && isset($data['metrics']) && is_array($data['metrics']) ? $data['metrics'] : [],
            'engineAvailable' => true,
        ]);
    }
}
