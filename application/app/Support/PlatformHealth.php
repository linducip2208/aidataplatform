<?php

namespace App\Support;

use App\Exceptions\AiEngineException;
use App\Services\AiEngineClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use Throwable;

/**
 * Read-only deployment sanity checks for the Laravel + AI engine pair.
 *
 * The three failure classes covered here — configuration drift between the two
 * services, a half-applied engine schema, and an unwritable volume — surface
 * on no page and are expensive to diagnose in production. Every check is a
 * pure read: nothing here migrates, seeds or writes. The result shape carries
 * no console concerns so the same report can back an HTTP endpoint later.
 */
class PlatformHealth
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const DOWN = 'down';

    /**
     * Tables the engine creates through Alembic. Laravel only keeps soft
     * references (`datasets.import_job_id`, `chat_threads.ai_conversation_id`)
     * with no foreign keys, so anything missing here means the engine schema
     * was never applied to this database.
     *
     * @var list<string>
     */
    public const ENGINE_TABLES = [
        'raw_uploads',
        'import_jobs',
        'staging_tables',
        'mapping_templates',
        'dim_customer',
        'dim_product',
        'dim_branch',
        'dim_supplier',
        'dim_warehouse',
        'dim_date',
        'dim_department',
        'fact_sales',
        'fact_inventory',
        'fact_purchases',
        'fact_expenses',
        'ml_models',
        'model_versions',
        'training_runs',
        'prediction_runs',
        'ai_conversations',
        'ai_messages',
        'rag_documents',
        'rag_chunks',
        'alert_rules',
        'alerts',
        'alert_events',
        'data_quality_reports',
    ];

    /** @var list<string> */
    public const REQUIRED_EXTENSIONS = ['vector', 'pg_trgm'];

    /** @var list<string> */
    public const LARAVEL_TABLES = [
        'users',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'personal_access_tokens',
        'datasets',
        'chat_threads',
        'chat_messages',
        'audit_logs',
    ];

    private bool $reachable = false;

    private bool $reachableResolved = false;

    public function __construct(private readonly AiEngineClient $engine) {}

    /**
     * Every component check, in display order.
     *
     * @return array<string, array{status: string, detail: string, remedy: string|null, group: string}>
     */
    public function report(): array
    {
        return [
            'app_key' => $this->appKey(),
            'app_env' => $this->appEnv(),
            'app_debug' => $this->appDebug(),
            'engine_url' => $this->engineUrl(),
            'service_key' => $this->serviceKey(),
            'max_upload_mb' => $this->maxUploadMb(),
            'quality_threshold' => $this->qualityThreshold(),
            'engine_health' => $this->engineHealth(),
            'engine_readiness' => $this->engineReadiness(),
            'engine_auth' => $this->engineAuth(),
            'database' => $this->database(),
            'database_extensions' => $this->databaseExtensions(),
            'engine_tables' => $this->engineTables(),
            'laravel_tables' => $this->laravelTables(),
            'records' => $this->records(),
            'storage' => $this->storageWritable(),
            'datasets_disk' => $this->datasetsDisk(),
        ];
    }

    /**
     * The subset that must hold before any command is allowed to call the
     * engine: local configuration, the engine's own health/readiness answer,
     * and one authenticated round trip that proves the key is accepted.
     *
     * @return array<string, array{status: string, detail: string, remedy: string|null, group: string}>
     */
    public function preflight(): array
    {
        return [
            'engine_url' => $this->engineUrl(),
            'service_key' => $this->serviceKey(),
            'engine_health' => $this->engineHealth(),
            'engine_readiness' => $this->engineReadiness(),
            'engine_auth' => $this->engineAuth(),
        ];
    }

    /**
     * @param  array<string, array{status: string, detail: string, remedy: string|null, group: string}>  $report
     */
    public function overallStatus(array $report): string
    {
        $statuses = array_column($report, 'status');

        if (in_array(self::DOWN, $statuses, true)) {
            return self::DOWN;
        }

        if (in_array(self::WARN, $statuses, true)) {
            return self::WARN;
        }

        return self::OK;
    }

    /**
     * @param  array<string, array{status: string, detail: string, remedy: string|null, group: string}>  $report
     * @return array{ok: int, warn: int, down: int}
     */
    public function counts(array $report): array
    {
        $counts = [self::OK => 0, self::WARN => 0, self::DOWN => 0];

        foreach (array_column($report, 'status') as $status) {
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }

        return ['ok' => $counts[self::OK], 'warn' => $counts[self::WARN], 'down' => $counts[self::DOWN]];
    }

    /**
     * @param  array<string, array{status: string, detail: string, remedy: string|null, group: string}>  $report
     * @return array{status: string, summary: array{ok: int, warn: int, down: int}, checked_at: string, components: array<string, array<string, mixed>>}
     */
    public function toArray(array $report): array
    {
        return [
            'status' => $this->overallStatus($report),
            'summary' => $this->counts($report),
            'checked_at' => now()->toIso8601String(),
            'components' => $report,
        ];
    }

    // ------------------------------------------------------------------
    // configuration
    // ------------------------------------------------------------------

    /** @return array{status: string, detail: string, remedy: string|null, group: string} */
    private function appKey(): array
    {
        $key = (string) config('app.key');

        if ($key === '') {
            return $this->down('APP_KEY is not set', 'run `php artisan key:generate`', 'configuration');
        }

        if (! str_starts_with($key, 'base64:')) {
            return $this->warn('APP_KEY is set but is not base64 encoded', 'run `php artisan key:generate --force`', 'configuration');
        }

        return $this->ok('set, '.Str::length($key).' chars', 'configuration');
    }

    /** @return array{status: string, detail: string, remedy: string|null, group: string} */
    private function appEnv(): array
    {
        $env = trim((string) config('app.env'));

        if ($env === '') {
            return $this->down('APP_ENV is not set', 'set APP_ENV in application/.env (local, staging or production)', 'configuration');
        }

        if ($env === 'production') {
            return $this->ok('production', 'configuration');
        }

        return $this->warn('APP_ENV='.$env, 'set APP_ENV=production once the deployment is live', 'configuration');
    }

    /** @return array{status: string, detail: string, remedy: string|null, group: string} */
    private function appDebug(): array
    {
        $debug = (bool) config('app.debug');

        if ($this->isProduction()) {
            return $debug
                ? $this->down('APP_DEBUG is true while APP_ENV=production', 'set APP_DEBUG=false and run `php artisan config:clear`', 'configuration')
                : $this->ok('false (correct for production)', 'configuration');
        }

        return $debug
            ? $this->ok('true, expected outside production', 'configuration')
            : $this->warn('false while APP_ENV is not production', 'set APP_DEBUG=true locally so failures are readable', 'configuration');
    }

    /** @return array{status: string, detail: string, remedy: string|null, group: string} */
    private function engineUrl(): array
    {
        $url = trim((string) config('ai_engine.base_url'));

        if ($url === '') {
            return $this->down('AI_ENGINE_URL is not set', 'set AI_ENGINE_URL (compose: http://fastapi:8000, local: http://127.0.0.1:8001)', 'configuration');
        }

        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? '') === '' || ($parts['host'] ?? '') === '') {
            return $this->down('AI_ENGINE_URL "'.$url.'" is not a parseable absolute URL', 'use an absolute URL such as http://fastapi:8000', 'configuration');
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return $this->down('AI_ENGINE_URL uses the "'.$scheme.'" scheme', 'AI_ENGINE_URL must be an http:// or https:// URL', 'configuration');
        }

        $host = (string) $parts['host'];

        if ($scheme === 'http' && $this->isProduction() && ! $this->isInternalHost($host)) {
            return $this->warn('plain http to a non-internal host ('.$host.') in production', 'terminate TLS in front of the engine or point AI_ENGINE_URL at https://', 'configuration');
        }

        return $this->ok($url, 'configuration');
    }

    /** @return array{status: string, detail: string, remedy: string|null, group: string} */
    private function serviceKey(): array
    {
        $key = (string) config('ai_engine.service_key');
        $header = (string) config('ai_engine.service_key_header');

        if (trim($key) === '') {
            return $this->down('SERVICE_API_KEY is empty', 'set SERVICE_API_KEY identically in application/.env and ai-engine/.env, then restart both services', 'configuration');
        }

        if ($header === '' || preg_match('/^[A-Za-z0-9-]+$/', $header) !== 1) {
            return $this->down('SERVICE_API_KEY_HEADER "'.$header.'" is not a usable HTTP header name', 'set SERVICE_API_KEY_HEADER to the same header name on both services (default X-Service-Key)', 'configuration');
        }

        return $this->ok('set, '.Str::length($key).' chars, sent as '.$header, 'configuration');
    }

    /** @return array{status: string, detail: string, remedy: string|null, group: string} */
    private function maxUploadMb(): array
    {
        $megabytes = (int) config('ai_engine.max_upload_mb');

        if ($megabytes < 1) {
            return $this->down('MAX_UPLOAD_MB is '.$megabytes.', so every upload is rejected', 'set MAX_UPLOAD_MB to a positive number of megabytes', 'configuration');
        }

        $postMax = $this->iniMegabytes('post_max_size');
        $uploadMax = $this->iniMegabytes('upload_max_filesize');
        $ceiling = min(array_filter([$postMax, $uploadMax], static fn (?float $value): bool => $value !== null));

        if ($ceiling !== null && $ceiling > 0 && (float) $megabytes > $ceiling) {
            return $this->down(
                'MAX_UPLOAD_MB='.$megabytes.' exceeds PHP post_max_size ('.$this->iniLabel('post_max_size').') and upload_max_filesize ('.$this->iniLabel('upload_max_filesize').')',
                'raise post_max_size and upload_max_filesize to at least '.$megabytes.'M in php.ini and restart PHP-FPM',
                'configuration',
            );
        }

        return $this->ok($megabytes.' MB, PHP accepts up to '.$ceiling.'M', 'configuration');
    }

    /** @return array{status: string, detail: string, remedy: string|null, group: string} */
    private function qualityThreshold(): array
    {
        $threshold = (float) config('ai_engine.quality_threshold');

        if ($threshold < 0.0 || $threshold > 1.0) {
            return $this->down('QUALITY_THRESHOLD is '.$threshold.', which is outside 0..1', 'set QUALITY_THRESHOLD as a fraction, e.g. 0.75', 'configuration');
        }

        return $this->ok($threshold.', datasets scoring lower are quarantined', 'configuration');
    }

    // ------------------------------------------------------------------
    // engine
    // ------------------------------------------------------------------

    /** @return array{status: string, detail: string, remedy: string|null, group: string} */
    private function engineHealth(): array
    {
        if (! $this->engine->isConfigured()) {
            return $this->down('the engine client has no base URL or service key', 'set AI_ENGINE_URL and SERVICE_API_KEY', 'ai engine');
        }

        try {
            $payload = $this->engine->health();
        } catch (AiEngineException $exception) {
            return $this->fromEngineException($exception, 'health');
        }

        if ($this->looksLikeError($payload)) {
            return $this->engineRejected($payload, 'health');
        }

        $status = strtolower((string) ($payload['status'] ?? $payload['state'] ?? ''));
        $version = (string) ($payload['version'] ?? '');

        if (in_array($status, ['ok', 'healthy', 'up', 'alive'], true)) {
            return $this->ok('status='.$status.($version !== '' ? ', version '.$version : ''), 'ai engine');
        }

        return $this->warn('status='.($status !== '' ? $status : 'unknown'), 'the engine answered but reports it is not healthy', 'ai engine');
    }

    /** @return array{status: string, detail: string, remedy: string|null, group: string} */
    private function engineReadiness(): array
    {
        if (! $this->engine->isConfigured()) {
            return $this->warn('skipped: the engine client is not configured', 'set AI_ENGINE_URL and SERVICE_API_KEY', 'ai engine');
        }

        try {
            $payload = $this->engine->readiness();
        } catch (AiEngineException $exception) {
            return $this->fromEngineException($exception, 'readiness');
        }

        if ($this->looksLikeError($payload)) {
            return $this->engineRejected($payload, 'readiness');
        }

        $failing = $this->failingReadinessChecks((array) ($payload['checks'] ?? []));
        $ready = $payload['ready'] ?? null;

        if (is_bool($ready)) {
            $label = $ready ? 'ready' : 'not ready';
            $isReady = $ready;
        } else {
            $label = strtolower((string) ($payload['status'] ?? $payload['state'] ?? 'ready'));
            $isReady = $failing === [] || in_array($label, ['ready', 'ok', 'up', 'true'], true);
        }

        $summary = $label.($failing !== [] ? ' (failing: '.implode(', ', $failing).')' : '');

        if ($isReady) {
            return $this->ok($summary, 'ai engine');
        }

        return $this->warn($summary, 'the engine is up but not ready; check its own logs for the failing dependency', 'ai engine');
    }

    /**
     * The engine's own /health and /readiness routes carry no auth dependency,
     * so a key mismatch is invisible there. Probing a cheap authenticated
     * endpoint through the same client is the only way to prove the two
     * services actually agree on the shared secret before the first import.
     *
     * @return array{status: string, detail: string, remedy: string|null, group: string}
     */
    private function engineAuth(): array
    {
        if (! $this->engine->isConfigured()) {
            return $this->down('the engine client has no base URL or service key', 'set AI_ENGINE_URL and SERVICE_API_KEY', 'ai engine');
        }

        try {
            $models = $this->engine->models();
        } catch (AiEngineException $exception) {
            return $this->fromEngineException($exception, 'models');
        }

        return $this->ok('the engine accepted SERVICE_API_KEY ('.count($models).' registered model(s))', 'ai engine');
    }

    /**
     * `AiEngineClient::health()` and `readiness()` bypass the envelope check, so
     * an HTTP error arrives as a plain body instead of an exception. Treat any
     * error envelope as a rejection so a 401/403 still surfaces here.
     *
     * @param  array<string, mixed>  $payload
     */
    private function looksLikeError(array $payload): bool
    {
        if (($payload['success'] ?? null) === false) {
            return true;
        }

        if (isset($payload['detail'])) {
            return true;
        }

        return isset($payload['error']) && ! isset($payload['status']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: string, detail: string, remedy: string|null, group: string}
     */
    private function engineRejected(array $payload, string $operation): array
    {
        $message = $this->redact((string) (
            data_get($payload, 'detail')
            ?? data_get($payload, 'error.message')
            ?? data_get($payload, 'error')
            ?? 'the engine returned an error envelope'
        ));

        if (preg_match('/credential|service key|unauthorized|forbidden|token|api key/i', $message) === 1) {
            return $this->down(
                'SERVICE_API_KEY does not match between Laravel and the AI engine on '.$operation.': '.$message,
                'make SERVICE_API_KEY byte-identical in application/.env and ai-engine/.env, restart both services, and confirm SERVICE_API_KEY_HEADER agrees',
                'ai engine',
            );
        }

        return $this->down('the engine rejected the '.$operation.' call: '.$message, 'inspect the fastapi logs and the ai-engine/.env file', 'ai engine');
    }

    /**
     * A 401/403 means the two services disagree about the shared secret, which
     * is the single most common deployment failure and is otherwise hidden
     * behind a generic "request failed" message.
     *
     * @return array{status: string, detail: string, remedy: string|null, group: string}
     */
    private function fromEngineException(AiEngineException $exception, string $operation): array
    {
        $upstream = $exception->upstreamStatus();
        $message = $this->redact($exception->getMessage());

        if ($upstream === 401 || $upstream === 403) {
            return $this->down(
                'SERVICE_API_KEY does not match between Laravel and the AI engine (HTTP '.$upstream.' on '.$operation.'): '.$message,
                'make SERVICE_API_KEY byte-identical in application/.env and ai-engine/.env, restart both services, and confirm SERVICE_API_KEY_HEADER agrees',
                'ai engine',
            );
        }

        if ($upstream === 404) {
            return $this->down(
                'the engine has no /'.$operation.' endpoint at AI_ENGINE_URL (HTTP 404)',
                'check AI_ENGINE_URL points at the FastAPI service and not at a proxy or a different version',
                'ai engine',
            );
        }

        if ($upstream === 503) {
            return $this->down(
                'the engine is unreachable: '.$message,
                'start the fastapi service and confirm Laravel can resolve AI_ENGINE_URL from its own network',
                'ai engine',
            );
        }

        return $this->down('the engine call "'.$operation.'" failed (HTTP '.$upstream.'): '.$message, 'inspect the fastapi logs and the ai-engine/.env file', 'ai engine');
    }

    /**
     * The engine reports readiness as `{"ready": bool, "checks": {"db": "up",
     * "redis": "down: <error>"}}`, so anything that is not an affirmative value
     * counts as failing.
     *
     * @param  array<mixed>  $checks
     * @return list<string>
     */
    private function failingReadinessChecks(array $checks): array
    {
        $passing = ['ok', 'up', 'ready', 'pass', 'healthy', 'true', '1'];
        $failing = [];

        foreach ($checks as $name => $result) {
            $raw = is_array($result) ? (string) ($result['status'] ?? '') : (string) $result;

            if (in_array(strtolower(trim($raw)), $passing, true)) {
                continue;
            }

            // Redacted, like every other path that surfaces engine output: a
            // readiness failure carries the driver's message, which embeds the
            // DSN and its password. Printing it into `platform:doctor` and into
            // `--json` writes the key into a terminal, a CI log and a ticket.
            $detail = trim((string) $name).($raw !== '' ? ': '.trim($raw) : '');
            $failing[] = Str::limit($this->redact($detail), 60);
        }

        return $failing;
    }

    // ------------------------------------------------------------------
    // database
    // ------------------------------------------------------------------

    /** @return array{status: string, detail: string, remedy: string|null, group: string} */
    private function database(): array
    {
        $connection = DB::connection();

        $this->reachableResolved = true;
        $this->reachable = false;

        try {
            $connection->select('select 1');
            $this->reachable = true;
        } catch (Throwable $exception) {
            return $this->down(
                'the '.$connection->getDriverName().' connection is not usable: '.$this->redact($exception->getMessage()),
                'check the DB_* values in application/.env and that the database service is running',
                'database',
            );
        }

        $version = (string) $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
        $name = (string) $connection->getDatabaseName();

        return $this->ok(
            $connection->getDriverName().' reachable, database '.($name !== '' ? $name : '(unnamed)').($version !== '' ? ', server '.$version : ''),
            'database',
        );
    }

    /** @return array{status: string, detail: string, remedy: string|null, group: string} */
    private function databaseExtensions(): array
    {
        $skip = $this->skipUnlessPostgres();

        if ($skip !== null) {
            return $skip;
        }

        try {
            $rows = DB::select('select extname from pg_extension');
        } catch (Throwable $exception) {
            return $this->down('cannot list PostgreSQL extensions: '.$this->redact($exception->getMessage()), 'check the database role has access to pg_extension', 'database');
        }

        $installed = array_map(
            static fn (object $row): string => strtolower((string) $row->extname),
            $rows,
        );

        $missing = array_values(array_diff(self::REQUIRED_EXTENSIONS, $installed));

        if ($missing !== []) {
            return $this->down(
                'missing extension(s): '.implode(', ', $missing),
                'run `CREATE EXTENSION IF NOT EXISTS vector; CREATE EXTENSION IF NOT EXISTS pg_trgm;` as a superuser against this database',
                'database',
            );
        }

        return $this->ok('vector and pg_trgm are installed', 'database');
    }

    /** @return array{status: string, detail: string, remedy: string|null, group: string} */
    private function engineTables(): array
    {
        $skip = $this->skipUnlessPostgres();

        if ($skip !== null) {
            return $skip;
        }

        try {
            $present = $this->tablesInPostgres();
        } catch (Throwable $exception) {
            return $this->down('cannot list PostgreSQL tables: '.$this->redact($exception->getMessage()), 'check the database role can read information_schema', 'database');
        }

        $missing = array_values(array_diff(self::ENGINE_TABLES, $present));

        if ($missing !== []) {
            return $this->down(
                count($missing).' of '.count(self::ENGINE_TABLES).' engine tables are missing: '.implode(', ', $missing),
                'run `alembic upgrade head` in ai-engine/ against this database',
                'database',
            );
        }

        return $this->ok(count(self::ENGINE_TABLES).' of '.count(self::ENGINE_TABLES).' engine tables are present', 'database');
    }

    /** @return array{status: string, detail: string, remedy: string|null, group: string} */
    private function laravelTables(): array
    {
        if (! $this->isReachable()) {
            return $this->warn('skipped: the database connection failed, see the "database" check', 'fix the connection first', 'database');
        }

        $missing = [];

        try {
            foreach (self::LARAVEL_TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    $missing[] = $table;
                }
            }
        } catch (Throwable $exception) {
            return $this->down('cannot list Laravel tables: '.$this->redact($exception->getMessage()), 'check the database role can read the schema', 'database');
        }

        if ($missing !== []) {
            return $this->down(
                'missing Laravel table(s): '.implode(', ', $missing),
                'run `php artisan migrate --force`',
                'database',
            );
        }

        return $this->ok(count(self::LARAVEL_TABLES).' of '.count(self::LARAVEL_TABLES).' Laravel tables are present', 'database');
    }

    /** @return array{status: string, detail: string, remedy: string|null, group: string} */
    private function records(): array
    {
        if (! $this->isReachable()) {
            return $this->warn('skipped: the database connection failed, see the "database" check', 'fix the connection first', 'database');
        }

        try {
            $users = $this->hasTable('users') ? DB::table('users')->count() : null;
            $datasets = $this->hasTable('datasets') ? DB::table('datasets')->count() : null;
        } catch (Throwable $exception) {
            return $this->warn('cannot count rows: '.$this->redact($exception->getMessage()), 'check the database role has SELECT on the public schema', 'database');
        }

        if ($users === null && $datasets === null) {
            return $this->warn('neither the users nor the datasets table exists', 'run `php artisan migrate --force`', 'database');
        }

        return $this->ok(
            ($users !== null ? $users.' users' : 'no users table')
            .', '.($datasets !== null ? $datasets.' datasets' : 'no datasets table'),
            'database',
        );
    }

    // ------------------------------------------------------------------
    // filesystem
    // ------------------------------------------------------------------

    /** @return array{status: string, detail: string, remedy: string|null, group: string} */
    private function storageWritable(): array
    {
        $paths = [
            storage_path(),
            storage_path('framework'),
            storage_path('logs'),
            storage_path('app/private'),
        ];

        $unusable = [];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                $unusable[] = $this->relative($path).' (missing)';
            } elseif (! is_writable($path)) {
                $unusable[] = $this->relative($path).' (not writable)';
            }
        }

        if ($unusable !== []) {
            return $this->down(
                'unwritable: '.implode(', ', $unusable),
                'chown -R the web user on storage/ and bootstrap/cache, e.g. `chown -R www-data:www-data storage bootstrap/cache`',
                'filesystem',
            );
        }

        return $this->ok('storage/ and its sub-directories are writable by '.($this->currentUser() ?: 'the current user'), 'filesystem');
    }

    /** @return array{status: string, detail: string, remedy: string|null, group: string} */
    private function datasetsDisk(): array
    {
        $disks = [(string) config('filesystems.default')];

        if ($this->isReachable() && $this->hasTable('datasets')) {
            try {
                $disks = array_merge($disks, DB::table('datasets')->distinct()->pluck('disk')->all());
            } catch (Throwable) {
                // Fall back to the default disk only; the check below still runs.
            }
        }

        $disks = array_values(array_unique(array_filter(array_map(
            static fn (mixed $disk): string => trim((string) $disk),
            $disks,
        ))));

        if ($disks === []) {
            return $this->down('no filesystem disk is configured', 'set FILESYSTEM_DISK in application/.env', 'filesystem');
        }

        $unwritable = [];
        $unknown = [];
        $remote = [];

        foreach ($disks as $disk) {
            if (! is_array(config('filesystems.disks.'.$disk))) {
                $unknown[] = $disk;

                continue;
            }

            $root = config('filesystems.disks.'.$disk.'.root');

            if (! is_string($root) || $root === '') {
                $remote[] = $disk;

                continue;
            }

            if (! is_dir($root)) {
                $unwritable[] = $disk.' (missing '.$this->relative($root).')';
            } elseif (! is_writable($root)) {
                $unwritable[] = $disk.' (not writable)';
            }
        }

        if ($unknown !== []) {
            return $this->down(
                'dataset disk(s) not defined in config/filesystems.php: '.implode(', ', $unknown),
                'add the disk definition or move those datasets to a configured disk',
                'filesystem',
            );
        }

        if ($unwritable !== []) {
            return $this->down(
                'dataset disk(s) not writable: '.implode(', ', $unwritable),
                'create the directory and grant the web user write access to it',
                'filesystem',
            );
        }

        $detail = 'disk(s) in use: '.implode(', ', $disks).' are writable';

        if ($remote !== []) {
            return $this->warn($detail.'; cannot verify remote disk(s): '.implode(', ', $remote), 'confirm the remote bucket credentials and prefix', 'filesystem');
        }

        return $this->ok($detail, 'filesystem');
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** @return array{status: string, detail: string, remedy: string|null, group: string} */
    private function ok(string $detail, string $group): array
    {
        return ['status' => self::OK, 'detail' => $detail, 'remedy' => null, 'group' => $group];
    }

    /** @return array{status: string, detail: string, remedy: string|null, group: string} */
    private function warn(string $detail, ?string $remedy = null, string $group = 'configuration'): array
    {
        return ['status' => self::WARN, 'detail' => $detail, 'remedy' => $remedy, 'group' => $group];
    }

    /** @return array{status: string, detail: string, remedy: string|null, group: string} */
    private function down(string $detail, ?string $remedy = null, string $group = 'configuration'): array
    {
        return ['status' => self::DOWN, 'detail' => $detail, 'remedy' => $remedy, 'group' => $group];
    }

    /**
     * The engine schema only ever lives in Postgres, so every driver-specific
     * check degrades to a note instead of a false alarm on sqlite test runs.
     *
     * @return array{status: string, detail: string, remedy: string|null, group: string}|null
     */
    private function skipUnlessPostgres(): ?array
    {
        if (! $this->isReachable()) {
            return $this->warn('skipped: the database connection failed, see the "database" check', 'fix the connection first', 'database');
        }

        $driver = (string) DB::connection()->getDriverName();

        if ($driver !== 'pgsql') {
            return $this->warn(
                'skipped: the connection driver is '.$driver.', the engine schema lives in PostgreSQL',
                'point DB_CONNECTION at pgsql to check the engine tables, or run the doctor against the production database',
                'database',
            );
        }

        return null;
    }

    /** @return list<string> */
    private function tablesInPostgres(): array
    {
        $rows = DB::select(
            "select table_name from information_schema.tables where table_schema in (current_schema(), 'public')"
        );

        return array_map(
            static fn (object $row): string => strtolower((string) $row->table_name),
            $rows,
        );
    }

    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    private function isReachable(): bool
    {
        if ($this->reachableResolved) {
            return $this->reachable;
        }

        $this->reachableResolved = true;

        try {
            DB::connection()->select('select 1');
            $this->reachable = true;
        } catch (Throwable) {
            $this->reachable = false;
        }

        return $this->reachable;
    }

    private function isProduction(): bool
    {
        return (string) config('app.env') === 'production';
    }

    private function isInternalHost(string $host): bool
    {
        return $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.local')
            || filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Defence in depth: a message that somehow carries the shared secret is
     * redacted before it can reach a terminal, a log line or an HTTP body.
     */
    private function redact(string $message): string
    {
        $key = (string) config('ai_engine.service_key');

        if ($key !== '' && str_contains($message, $key)) {
            $message = str_replace($key, '[redacted]', $message);
        }

        // A driver or DSN error embeds the whole connection string, password
        // included, and a readiness check reports the driver's own message. The
        // service key is not the only secret in that string: this scrubs the
        // `user:password@` of any URL as well.
        $message = preg_replace('#(\w+://[^:/\s]+):[^@/\s]+@#', '$1:[redacted]@', $message) ?? $message;
        $message = preg_replace('/\b(password|passwd|pwd|secret|token|api[_-]?key)=\S+/i', '$1=[redacted]', $message) ?? $message;

        return Str::limit(preg_replace('/\s+/', ' ', trim($message)) ?? $message, 200);
    }

    private function relative(string $path): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    private function currentUser(): string
    {
        $user = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
            ? @posix_getpwuid(posix_geteuid())
            : false;

        return is_array($user) ? (string) ($user['name'] ?? '') : '';
    }

    private function iniMegabytes(string $key): ?float
    {
        $value = trim((string) ini_get($key));

        if ($value === '') {
            return null;
        }

        $number = (float) $value;

        if ($number < 0) {
            return null;
        }

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024,
            'm' => $number,
            'k' => $number / 1024,
            default => $number / 1048576,
        };
    }

    private function iniLabel(string $key): string
    {
        $value = trim((string) ini_get($key));
        $megabytes = $this->iniMegabytes($key);

        if ($megabytes === null) {
            return $value !== '' ? $value : 'unset';
        }

        return round($megabytes, 2).'M ('.$value.')';
    }
}
