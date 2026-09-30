<?php

namespace App\Services;

use App\Models\AiProvider;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * BYOK provider registry: metadata in the database, secrets straight to the
 * engine env block, never stored, logged, or returned.
 *
 * Two deployment realities, handled honestly:
 * - Native/aaPanel: the engine reads `ai-engine/.env`, which this host can
 *   write, so publish writes the managed block there (backup + 0600).
 * - Docker Compose: containers receive env by interpolation at creation and
 *   the root `.env` is not mounted into them, so no write from here could
 *   ever take effect. Publish instead returns the exact block for the
 *   operator to paste plus the recreate command. This is stated in the UI,
 *   not hidden.
 */
class AiProviderService
{
    public const MANAGED_BEGIN = '# >>> aidata-managed-providers (written by Admin > AI Providers; edit below this block instead)';

    public const MANAGED_END = '# <<< aidata-managed-providers';

    public function __construct(private readonly ?string $engineEnvPath = null) {}

    public function active(): ?AiProvider
    {
        return AiProvider::query()
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->first();
    }

    /**
     * Probe a provider with the caller's key: one tiny chat completion.
     * The key travels in this request only and is scrubbed from every error.
     *
     * @param  array<string, mixed>  $attributes  name/provider_type/base_url/model
     * @return array{ok: bool, note: string}
     */
    public function testConnection(array $attributes, string $apiKey): array
    {
        $baseUrl = rtrim(trim((string) ($attributes['base_url'] ?? '')), '/');

        if (! preg_match('#^https?://#i', $baseUrl)) {
            return ['ok' => false, 'note' => 'Base URL must start with http:// or https://.'];
        }

        if (trim((string) ($attributes['model'] ?? '')) === '') {
            return ['ok' => false, 'note' => 'Model must not be empty.'];
        }

        $headers = ['Content-Type' => 'application/json'];

        if (trim($apiKey) !== '') {
            $headers['Authorization'] = 'Bearer '.$apiKey;
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders($headers)
                ->timeout(20)
                ->post($baseUrl.'/chat/completions', [
                    'model' => $attributes['model'],
                    'messages' => [['role' => 'user', 'content' => 'Reply with: ok']],
                    'max_tokens' => 8,
                ]);
        } catch (Throwable $exception) {
            return ['ok' => false, 'note' => 'Unreachable: '.substr($exception->getMessage(), 0, 160)];
        }

        if ($response->successful()) {
            return ['ok' => true, 'note' => 'HTTP '.$response->status().'.'];
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return ['ok' => false, 'note' => 'HTTP '.$response->status().': key rejected.'];
        }

        return ['ok' => false, 'note' => 'HTTP '.$response->status().'.'];
    }

    /**
     * Publish a provider's env block. Native writes the file; under Compose
     * returns the block for the operator (see class docblock).
     *
     * @return array{written: bool, path?: string, block?: string, restart?: string}
     */
    public function publish(AiProvider $provider, string $apiKey, ?User $actor = null): array
    {
        if ($this->runningInContainer()) {
            $block = $this->renderBlock($provider, $apiKey);

            AuditLog::record('ai_provider.publish_block_issued', 'ai_provider', $provider->getKey(), [
                'name' => $provider->name,
            ], $actor);

            return [
                'written' => false,
                'block' => $block,
                'restart' => 'docker compose up -d fastapi celery-worker celery-beat',
            ];
        }

        $path = $this->engineEnvPath();

        if (! is_writable($path) && ! (! file_exists($path) && is_writable(dirname($path)))) {
            throw new RuntimeException("Engine env file is not writable: {$path}.");
        }

        $this->writeManagedBlock($path, $this->renderBlock($provider, $apiKey));

        AuditLog::record('ai_provider.published', 'ai_provider', $provider->getKey(), [
            'name' => $provider->name,
            'path' => $path,
        ], $actor);

        return [
            'written' => true,
            'path' => $path,
            'restart' => 'supervisorctl restart aidata-fastapi aidata-celery-worker aidata-celery-beat',
        ];
    }

    /** @return array<string, string> */
    public function currentEnv(): array
    {
        $path = $this->engineEnvPath();

        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $values = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (! preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/', $line, $match)) {
                continue;
            }

            $values[$match[1]] = trim($match[2], " \t\"'");
        }

        return $values;
    }

    public function runningInContainer(): bool
    {
        return file_exists('/.dockerenv');
    }

    public function engineEnvPath(): string
    {
        return $this->engineEnvPath ?? base_path('../ai-engine/.env');
    }

    /** @param  array<string, string>  $env */
    private function renderBlock(AiProvider $provider, string $apiKey): string
    {
        $lines = [self::MANAGED_BEGIN];

        foreach ($provider->managedEnv($apiKey) as $name => $value) {
            // Values are single-line by validation; strip newlines defensively
            // so one key can never inject a second env entry.
            $lines[] = $name.'='.str_replace(["\r", "\n"], '', (string) $value);
        }

        $lines[] = self::MANAGED_END;

        return implode("\n", $lines)."\n";
    }

    private function writeManagedBlock(string $path, string $block): void
    {
        $existing = file_exists($path) ? (string) file_get_contents($path) : '';

        if ($existing !== '' && file_exists($path)) {
            copy($path, $path.'.bak.'.date('YmdHis'));
        }

        $pattern = '/'.preg_quote(self::MANAGED_BEGIN, '/').'.*?'.preg_quote(self::MANAGED_END, '/')."\n?/s";

        if (preg_match($pattern, $existing)) {
            $updated = (string) preg_replace($pattern, $block, $existing);
        } else {
            $updated = rtrim($existing)."\n\n".$block;
        }

        file_put_contents($path, $updated, LOCK_EX);
        chmod($path, 0600);
    }
}
