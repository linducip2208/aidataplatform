<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Conformance test for the published contract in `docs/api.md`.
 *
 * `docs/api.md` is a document, not code, so nothing else keeps it honest. It
 * already shipped once describing five endpoints that were never implemented.
 * This class reads the document at test time — the expected values are never
 * duplicated in PHP, because a second copy is a second thing to forget — and
 * asserts that the routes, the role gates, the status codes and the response
 * envelopes still say the same thing.
 *
 * URI normalisation, applied identically to both sides: the leading slash is
 * stripped and every `{placeholder}` is collapsed to `{}`, so the document's
 * `/api/datasets/{uuid}` and the route's `api/datasets/{dataset}` compare
 * equal. Placeholder *names* are not part of the wire contract; the static
 * segments and the verb are, and those are compared literally.
 *
 * Table parsing is deliberately intolerant of rows it cannot read: a row with
 * the wrong number of cells, an unknown verb, or a path cell with no
 * backticked path is collected and reported by line number. Silently skipping
 * it would turn a documentation bug into a green build.
 */
class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    private const ENGINE_PREFIX = '/api/v1';

    /** @var array<int, array{method: string, path: string, key: string, side: string, line: int, raw: string, role: ?string, auth: ?string, statuses: array<int, int>}>|null */
    private ?array $contract = null;

    /** @var array<int, string> */
    private array $unparsed = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    // ------------------------------------------------------------------
    // docs/api.md <-> routes
    // ------------------------------------------------------------------

    public function test_every_documented_laravel_endpoint_exists_with_the_documented_verb(): void
    {
        $routes = $this->laravelRouteMap();
        $missing = [];

        foreach ($this->contract() as $entry) {
            if ($entry['side'] !== 'laravel') {
                continue;
            }

            if (! array_key_exists($entry['key'], $routes)) {
                $missing[] = sprintf(
                    'docs/api.md line %d documents %s %s but no such Laravel route exists (documented line: %s)',
                    $entry['line'],
                    $entry['method'],
                    $entry['path'],
                    $entry['raw'],
                );
            }
        }

        $this->assertSame([], $missing, implode(PHP_EOL, $missing));
        $this->assertNotEmpty($this->contract(), 'No endpoint rows were parsed out of docs/api.md.');
    }

    public function test_every_api_route_is_documented(): void
    {
        // The reverse direction matters more: an undocumented `api/` route is an
        // unadvertised public endpoint, and nothing else in the repository would
        // ever mention it again.
        $documented = [];

        foreach ($this->contract() as $entry) {
            if ($entry['side'] === 'laravel') {
                $documented[$entry['key']] = true;
            }
        }

        $undocumented = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $key = $method.' '.self::normalise($route->uri());

                if (! isset($documented[$key])) {
                    $undocumented[] = $key.' ('.($route->getName() ?? 'unnamed').')';
                }
            }
        }

        $this->assertSame(
            [],
            $undocumented,
            'Unadvertised public endpoints: '.implode(', ', $undocumented).'. Add them to docs/api.md or delete the route.',
        );
    }

    public function test_every_documented_engine_endpoint_exists_in_the_engine_router(): void
    {
        // The engine surface is checked forward only. The engine deliberately
        // exposes more than the contract publishes (the direct-task endpoints
        // such as /forecast and /anomaly/detect are internal, not proxied), so
        // a reverse check here would be noise. The forward direction is the one
        // that catches a documented endpoint that was never implemented.
        $engine = $this->engineRouteMap();
        $missing = [];

        foreach ($this->contract() as $entry) {
            if ($entry['side'] !== 'engine') {
                continue;
            }

            if (! isset($engine[$entry['key']])) {
                $missing[] = sprintf(
                    'docs/api.md line %d documents %s %s but no such route exists in ai-engine/app/api/v1 (documented line: %s)',
                    $entry['line'],
                    $entry['method'],
                    $entry['path'],
                    $entry['raw'],
                );
            }
        }

        $this->assertSame([], $missing, implode(PHP_EOL, $missing));
    }

    public function test_every_documented_engine_call_exists_in_the_engine_router(): void
    {
        // The analytics table documents both sides: the Laravel proxy path and
        // the engine path it is backed by. Only the Path column is parsed as an
        // endpoint, so the "Engine call" column is read separately here.
        $engine = $this->engineRouteMap();
        $missing = [];

        foreach ($this->contract() as $entry) {
            foreach ($entry['engine_calls'] as $call) {
                if (preg_match('/^(GET|POST|PUT|PATCH|DELETE)\b/i', $call['raw'], $verb) !== 1) {
                    $this->fail(sprintf(
                        'docs/api.md line %d documents an engine call "%s" without a recognisable verb.',
                        $entry['line'],
                        $call['raw'],
                    ));
                }

                $method = strtoupper($verb[1]);
                $path = ltrim(substr($call['raw'], strlen($verb[0])), " \t");

                if (! isset($engine[$method.' '.self::normalise($path)])) {
                    $missing[] = sprintf(
                        'docs/api.md line %d says %s %s calls %s, which the engine does not expose.',
                        $entry['line'],
                        $entry['method'],
                        $entry['path'],
                        $call['raw'],
                    );
                }
            }
        }

        $this->assertSame([], $missing, implode(PHP_EOL, $missing));
    }

    // ------------------------------------------------------------------
    // docs/api.md <-> middleware
    // ------------------------------------------------------------------

    public function test_the_role_policy_matches_the_documented_role_column(): void
    {
        $checked = 0;
        $mismatches = [];

        foreach ($this->contract() as $entry) {
            if ($entry['side'] !== 'laravel' || $entry['role'] === null) {
                continue;
            }

            $route = $this->laravelRouteMap()[$entry['key']][0] ?? null;
            $this->assertNotNull($route, sprintf('Route for docs/api.md line %d vanished mid-test.', $entry['line']));

            $checked++;
            $documented = $this->documentedRoles($entry['role']);
            $actual = $this->routeRoles($route);

            if ($documented === $actual) {
                continue;
            }

            $mismatches[] = sprintf(
                'docs/api.md line %d documents role "%s" for %s %s, the route is restricted to %s (documented line: %s)',
                $entry['line'],
                $entry['role'],
                $entry['method'],
                $entry['path'],
                $actual === null ? 'nobody (no role: middleware)' : '"'.implode(',', $actual).'"',
                $entry['raw'],
            );
        }

        $this->assertSame([], $mismatches, implode(PHP_EOL, $mismatches));
        $this->assertGreaterThan(0, $checked, 'No row with a Role column was found in docs/api.md.');
    }

    public function test_the_auth_policy_matches_the_documented_auth_column(): void
    {
        $checked = 0;
        $mismatches = [];

        foreach ($this->contract() as $entry) {
            if ($entry['side'] !== 'laravel' || $entry['auth'] === null) {
                continue;
            }

            $route = $this->laravelRouteMap()[$entry['key']][0] ?? null;
            $this->assertNotNull($route, sprintf('Route for docs/api.md line %d vanished mid-test.', $entry['line']));

            $checked++;
            $wantsBearer = str_contains(strtolower($entry['auth']), 'bearer');
            $isBearer = $this->routeGuard($route) !== null;

            if ($wantsBearer === $isBearer) {
                continue;
            }

            $mismatches[] = sprintf(
                'docs/api.md line %d documents auth "%s" for %s %s, the route is %s (documented line: %s)',
                $entry['line'],
                $entry['auth'],
                $entry['method'],
                $entry['path'],
                $isBearer ? 'behind '.($this->routeGuard($route) ?? 'auth') : 'unauthenticated',
                $entry['raw'],
            );
        }

        $this->assertSame([], $mismatches, implode(PHP_EOL, $mismatches));
        $this->assertGreaterThan(0, $checked, 'No row with an Auth column was found in docs/api.md.');
    }

    // ------------------------------------------------------------------
    // docs/api.md <-> status codes
    // ------------------------------------------------------------------

    public function test_the_documented_success_status_codes_are_the_ones_the_code_produces(): void
    {
        $produced = $this->successStatusProbes();

        $this->assertNotEmpty($produced);
        $this->assertSame($produced['POST /api/datasets'], 201);
        $this->assertSame($produced['POST /api/ml/train'], 202);
        $this->assertSame($produced['POST /api/datasets/{}/commit'], 202);
        $this->assertSame($produced['GET /api/health'], 200);
    }

    public function test_the_documented_error_status_codes_are_the_ones_the_code_produces(): void
    {
        $produced = $this->errorStatusProbes();

        $this->assertNotEmpty($produced);

        $statuses = array_values($produced);
        sort($statuses);

        $this->assertSame(
            [401, 403, 404, 422, 502, 503],
            $statuses,
            'The Errors section of docs/api.md and the responses the code actually produces have drifted apart.',
        );
    }

    /**
     * The exhaustive statement: the set of status codes the contract names is
     * the set of status codes this codebase can actually be made to return.
     * A code that appears in docs/api.md but nowhere else is a promise the
     * implementation does not keep; a code the implementation returns that the
     * contract never mentions is a surprise for every client.
     */
    public function test_the_documented_status_codes_are_exactly_the_ones_the_code_produces(): void
    {
        $documented = $this->documentedStatuses();

        $produced = array_values(array_unique(array_merge(
            array_values($this->successStatusProbes()),
            array_values($this->errorStatusProbes()),
        )));
        sort($produced);

        $this->assertSame(
            $documented,
            $produced,
            'docs/api.md names status codes the code never produces, or the code produces codes the contract never mentions.',
        );
    }

    /**
     * Each probe asserts the documented status for its own endpoint and then
     * returns the status the code really produced, so the per-endpoint test and
     * the exhaustive set comparison above read the same evidence.
     *
     * Every fake is pinned to the one engine path its probe exercises: `Http::fake`
     * merges stubs rather than replacing them, so a catch-all registered by one
     * probe would answer the next probe's request and quietly prove nothing.
     *
     * @return array<string, int>
     */
    private function successStatusProbes(): array
    {
        $produced = [];

        Sanctum::actingAs(User::factory()->analyst()->create());
        Http::fake(['*/api/v1/imports/upload' => Http::response(['success' => true, 'data' => [
            'import_job_id' => 42,
            'validation' => ['ok' => true, 'meta' => ['size_bytes' => 12]],
        ]], 200)]);

        // A real multipart round trip, so the 201 comes from the controller and
        // not from a mocked engine response.
        $response = $this->post(route('api.datasets.store'), [
            'file' => UploadedFile::fake()->createWithContent('sales.csv', "a,b\n1,2\n"),
            'dataset_type' => 'sales',
        ]);

        $this->assertContains(
            $response->getStatusCode(),
            $this->documentedStatusFor('/api/datasets', 'POST'),
            'POST /api/datasets returns '.$response->getStatusCode().' and docs/api.md does not document that.',
        );

        $produced['POST /api/datasets'] = $response->getStatusCode();

        Sanctum::actingAs(User::factory()->analyst()->create());
        Http::fake(['*/api/v1/training/train' => Http::response(['success' => true, 'data' => [
            'model_id' => 7,
            'version' => 'v1',
        ]], 200)]);

        $response = $this->postJson(route('api.ml.train'), ['model_type' => 'forecast', 'name' => 'Contract probe']);

        $this->assertContains(
            $response->getStatusCode(),
            $this->documentedStatusFor('/api/ml/train', 'POST'),
            'POST /api/ml/train returns '.$response->getStatusCode().' and docs/api.md does not document that.',
        );

        $produced['POST /api/ml/train'] = $response->getStatusCode();

        $dataset = Dataset::factory()->create(['import_job_id' => 42]);
        Sanctum::actingAs(User::factory()->analyst()->create());
        Http::fake(['*/api/v1/imports/commit' => Http::response(['success' => true, 'data' => [
            'status' => 'queued',
        ]], 200)]);

        $response = $this->postJson(route('api.datasets.commit', $dataset), ['run_async' => true]);

        $this->assertContains(
            $response->getStatusCode(),
            $this->documentedStatusFor('/api/datasets/{uuid}/commit', 'POST'),
            'POST /api/datasets/{uuid}/commit returns '.$response->getStatusCode()
                .' and docs/api.md does not document that. Body: '.$response->getContent(),
        );

        $produced['POST /api/datasets/{}/commit'] = $response->getStatusCode();

        Http::fake(['*/api/v1/health' => fn () => throw new ConnectionException('Connection refused')]);

        $response = $this->getJson(route('api.health'));
        $response->assertJsonPath('data.engine.status', 'unreachable');

        $this->assertContains(
            $response->getStatusCode(),
            $this->documentedStatusFor('/api/health', 'GET'),
            'GET /api/health returns '.$response->getStatusCode().' with the engine down and docs/api.md does not document that.',
        );

        $produced['GET /api/health'] = $response->getStatusCode();

        return $produced;
    }

    /**
     * Every expectation is the status docs/api.md states, read out of the
     * document rather than hard-coded here: a rewrite of the Errors section that
     * drops or renames a code makes this fail with the phrase it looked for.
     *
     * @return array<string, int>
     */
    private function errorStatusProbes(): array
    {
        $unauthenticated = $this->statusAfter('Unauthenticated');
        $wrongRole = $this->statusAfter('wrong role');
        $validation = $this->statusAfter('Laravel validation');
        $engineDown = $this->statusAfter('connection refused');
        $engineBroken = $this->statusAfter('rejected service key');
        $unknownJob = $this->statusAfter('/api/v1/imports/jobs/{id}`;');

        $produced = [];

        // `Sanctum::actingAs()` is sticky for the rest of the test, and these
        // probes run in both orders depending on which test called them.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/datasets')->assertStatus($unauthenticated);
        $produced['GET /api/datasets (guest)'] = $unauthenticated;

        Sanctum::actingAs(User::factory()->viewer()->create());
        $this->postJson(route('api.datasets.store'), ['dataset_type' => 'sales'])
            ->assertStatus($wrongRole)
            ->assertJsonPath('code', 'forbidden');
        $produced['POST /api/datasets (viewer)'] = $wrongRole;

        Sanctum::actingAs(User::factory()->analyst()->create());
        $this->postJson(route('api.datasets.store'), ['dataset_type' => 'sales'])
            ->assertStatus($validation)
            ->assertJsonValidationErrors('file');
        $produced['POST /api/datasets (analyst, no file)'] = $validation;

        Http::fake(['*/api/v1/imports/jobs/*' => Http::response(['error' => ['message' => 'no such job']], 404)]);
        $this->getJson(route('api.import-jobs.show', 4242))
            ->assertStatus($unknownJob)
            ->assertJsonPath('code', 'not_found');
        $produced['GET /api/import-jobs/{} (engine 404)'] = $unknownJob;

        Http::fake(['*/api/v1/models' => fn () => throw new ConnectionException('Connection refused')]);
        $this->getJson(route('api.ml.models'))
            ->assertStatus($engineDown)
            ->assertJsonPath('code', 'ai_engine_error');
        $produced['GET /api/ml/models (engine refused)'] = $engineDown;

        Http::fake(['*/api/v1/analytics/kpi' => Http::response(['error' => ['message' => 'boom']], 500)]);
        $this->getJson(route('api.analytics.kpi'))
            ->assertStatus($engineBroken)
            ->assertJsonPath('code', 'ai_engine_error');
        $produced['GET /api/analytics/kpi (engine 5xx)'] = $engineBroken;

        return $produced;
    }

    // ------------------------------------------------------------------
    // docs/api.md <-> response envelopes
    // ------------------------------------------------------------------

    public function test_the_list_envelope_matches_the_documented_shape(): void
    {
        $envelope = $this->documentedEnvelope('list');
        $this->assertContains('data', $envelope['keys']);
        $this->assertContains('meta', $envelope['keys']);
        $this->assertNotEmpty($envelope['meta'], 'docs/api.md documents a list envelope with an empty meta block.');

        Sanctum::actingAs(User::factory()->analyst()->create());
        Dataset::factory()->count(3)->create();

        $payload = $this->getJson(route('api.datasets.index'))->assertOk()->json();

        $this->assertSame(
            $envelope['keys'],
            $this->sortedKeys(array_keys($payload)),
            'GET /api/datasets does not return the documented list envelope keys.',
        );
        $this->assertSame($envelope['meta'], $this->sortedKeys(array_keys($payload['meta'] ?? [])));
        $this->assertIsList($payload['data'] ?? null);

        foreach (['total', 'page', 'per_page'] as $key) {
            $this->assertArrayHasKey($key, $payload['meta'] ?? [], "meta.{$key} is missing from the list envelope.");
        }
    }

    public function test_per_page_is_clamped_to_the_documented_maximum(): void
    {
        $this->assertSame(
            1,
            preg_match('/per_page`? is clamped to (\d+)/', $this->docFlat(), $match),
            'docs/api.md no longer states the per_page clamp.',
        );
        $clamp = (int) $match[1];

        Sanctum::actingAs(User::factory()->analyst()->create());
        Dataset::factory()->count(3)->create();

        $this->getJson(route('api.datasets.index', ['per_page' => 500]))
            ->assertOk()
            ->assertJsonPath('meta.per_page', $clamp);
    }

    public function test_the_single_resource_envelope_matches_the_documented_shape(): void
    {
        $envelope = $this->documentedEnvelope('single resource');
        $this->assertSame(['data'], $envelope['keys']);

        Sanctum::actingAs(User::factory()->analyst()->create());
        $dataset = Dataset::factory()->committed()->create();

        $payload = $this->getJson(route('api.datasets.show', $dataset))->assertOk()->json();

        $this->assertSame($envelope['keys'], $this->sortedKeys(array_keys($payload)));
        $this->assertArrayHasKey('data', $payload);
        $this->assertIsArray($payload['data']);
        $this->assertNotEmpty($payload['data']);
        $this->assertFalse(array_is_list($payload['data']), 'A single resource must not be a JSON list.');
    }

    // ------------------------------------------------------------------
    // document parsing
    // ------------------------------------------------------------------

    public function test_the_documented_endpoint_tables_are_fully_parsable(): void
    {
        $this->contract();

        $this->assertSame([], $this->unparsed, 'Unparsed rows in docs/api.md:'.PHP_EOL.implode(PHP_EOL, $this->unparsed));
    }

    /**
     * @return array<int, array{method: string, path: string, key: string, side: string, line: int, raw: string, role: ?string, auth: ?string, statuses: array<int, int>, engine_calls: array<int, array{raw: string, path: string}>}>
     */
    private function contract(): array
    {
        if ($this->contract !== null) {
            return $this->contract;
        }

        $lines = preg_split('/\R/', $this->doc()) ?: [];
        $entries = [];
        $this->unparsed = [];

        for ($i = 0, $count = count($lines); $i < $count; $i++) {
            $header = $this->tableCells($lines[$i]);

            if ($header === null || ! $this->isSeparator($lines[$i + 1] ?? null)) {
                continue;
            }

            $columns = array_map(fn (string $cell): string => strtolower($this->trim($cell)), $header);

            if (! in_array('method', $columns, true) || ! in_array('path', $columns, true)) {
                continue;
            }

            $methodAt = array_search('method', $columns, true);
            $pathAt = array_search('path', $columns, true);
            $roleAt = array_search('role', $columns, true);
            $authAt = array_search('auth', $columns, true);
            $engineAt = array_search('engine call', $columns, true);

            for ($j = $i + 2, $count = count($lines); $j < $count; $j++) {
                $raw = $lines[$j];

                if (! str_starts_with($this->trim($raw), '|')) {
                    break;
                }

                if ($this->isSeparator($raw)) {
                    continue;
                }

                $cells = $this->tableCells($raw);

                if ($cells === null || count($cells) !== count($columns)) {
                    $this->unparsed[] = sprintf(
                        'line %d: expected %d cells, found %d in %s',
                        $j + 1,
                        count($columns),
                        $cells === null ? 0 : count($cells),
                        $this->trim($raw),
                    );

                    continue;
                }

                $method = strtoupper($this->trim($cells[$methodAt]));

                if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
                    $this->unparsed[] = sprintf('line %d: unknown method "%s" in %s', $j + 1, $method, $this->trim($raw));

                    continue;
                }

                $paths = $this->backticked($cells[$pathAt], '/');

                if ($paths === []) {
                    $this->unparsed[] = sprintf('line %d: no backticked path in %s', $j + 1, $this->trim($raw));

                    continue;
                }

                $statuses = [];

                foreach ($cells as $at => $cell) {
                    if (in_array($at, [$methodAt, $pathAt, $roleAt, $authAt, $engineAt], true)) {
                        continue;
                    }

                    foreach ($this->backticked($cell, null, true) as $token) {
                        if (preg_match('/^\d{3}$/', $token)) {
                            $statuses[] = (int) $token;
                        }
                    }
                }

                $engineCalls = [];

                if ($engineAt !== false) {
                    foreach ($this->backticked($cells[$engineAt]) as $call) {
                        if (str_starts_with($call, '/')) {
                            $engineCalls[] = ['raw' => $call];
                        }
                    }
                }

                foreach ($paths as $path) {
                    $side = str_contains($raw, '(engine)') || str_starts_with($path, self::ENGINE_PREFIX)
                        ? 'engine'
                        : 'laravel';

                    $entries[] = [
                        'method' => $method,
                        'path' => $path,
                        'key' => $method.' '.self::normalise($path),
                        'side' => $side,
                        'line' => $j + 1,
                        'raw' => $this->trim($raw),
                        'role' => $roleAt === false ? null : $this->trim($cells[$roleAt]),
                        'auth' => $authAt === false ? null : $this->trim($cells[$authAt]),
                        'statuses' => array_values(array_unique($statuses)),
                        'engine_calls' => $engineCalls,
                    ];
                }
            }

            $i = $j - 1;
        }

        $this->assertSame(
            [],
            $this->unparsed,
            'docs/api.md changed shape in a way this parser cannot read. Fix the row or the parser, never silence it:'.PHP_EOL.implode(PHP_EOL, $this->unparsed),
        );

        return $this->contract = $entries;
    }

    private function doc(): string
    {
        $path = base_path('../docs/api.md');

        $this->assertFileExists($path, 'docs/api.md is the published contract and must exist next to the application.');

        return (string) file_get_contents($path);
    }

    private function docFlat(): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $this->doc()));
    }

    /**
     * @return array<int, int>
     */
    private function documentedStatuses(): array
    {
        preg_match_all('/`(\d{3})`/', $this->doc(), $matches);

        $statuses = array_values(array_unique(array_map('intval', $matches[1])));
        sort($statuses);

        return $statuses;
    }

    /**
     * @return array<int, int>
     */
    private function documentedStatusFor(string $path, string $method): array
    {
        foreach ($this->contract() as $entry) {
            if ($entry['side'] === 'laravel' && $entry['method'] === $method && $entry['key'] === $method.' '.self::normalise($path)) {
                return $entry['statuses'];
            }
        }

        $this->fail(sprintf('docs/api.md has no %s row for %s.', $method, $path));
    }

    private function statusAfter(string $needle): int
    {
        $flat = $this->docFlat();
        $at = strpos($flat, $needle);

        $this->assertNotFalse(
            $at,
            sprintf('docs/api.md no longer contains "%s", so the status it documents cannot be verified.', $needle),
        );

        $this->assertSame(
            1,
            preg_match('/`(\d{3})`/', substr($flat, $at + strlen($needle)), $match),
            sprintf('docs/api.md states no status code after "%s".', $needle),
        );

        return (int) $match[1];
    }

    /**
     * @return array{keys: array<int, string>, meta: array<int, string>}
     */
    private function documentedEnvelope(string $label): array
    {
        $this->assertSame(
            1,
            preg_match('/^- '.preg_quote($label, '/').':\s*`(.*)`\s*$/m', $this->doc(), $match),
            sprintf('docs/api.md no longer documents the "%s" envelope with a single inline example.', $label),
        );

        $meta = [];

        if (preg_match('/"meta":\s*\{(.*?)\}/', $match[1], $inner)) {
            $meta = $this->topLevelKeys('{'.$inner[1].'}');
        }

        return ['keys' => $this->topLevelKeys($match[1]), 'meta' => $meta];
    }

    /**
     * @return array<int, string>
     */
    private function topLevelKeys(string $object): array
    {
        $keys = [];
        $depth = 0;
        $offset = 0;
        $length = strlen($object);

        while ($offset < $length) {
            $char = $object[$offset];

            if ($char === '{' || $char === '[') {
                $depth++;
                $offset++;

                continue;
            }

            if ($char === '}' || $char === ']') {
                $depth--;
                $offset++;

                continue;
            }

            if ($depth === 1 && $char === '"' && preg_match('/\G"([^"]+)"/', $object, $match, 0, $offset)) {
                $keys[] = $match[1];
                $offset += strlen($match[0]);

                continue;
            }

            $offset++;
        }

        return $this->sortedKeys($keys);
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    private function sortedKeys(array $keys): array
    {
        sort($keys);

        return array_values($keys);
    }

    // ------------------------------------------------------------------
    // routes
    // ------------------------------------------------------------------

    /**
     * Every Laravel route the contract may talk about, keyed by `VERB /path`
     * with both sides normalised the same way.
     *
     * @return array<string, array<int, RouteDefinition>>
     */
    private function laravelRouteMap(): array
    {
        $map = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/') && $route->uri() !== 'up') {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $map[$method.' '.self::normalise($route->uri())][] = $route;
            }
        }

        return $map;
    }

    /**
     * Static scan of the engine's own router. Reading the source keeps the check
     * dependency-free: no Python import, no database, no running service.
     *
     * @return array<string, true>
     */
    private function engineRouteMap(): array
    {
        $routes = [];

        foreach ($this->engineSources() as [$prefix, $source]) {
            if (preg_match('/prefix\s*=\s*[\'"]([^\'"]*)[\'"]/', $source, $found) === 1) {
                $prefix .= $found[1];
            }

            // `*` not `+`: a route may be declared at the router's own root, as
            // `@router.get("")` does when the module sets `prefix=`. Requiring
            // one character silently drops those, which would make a documented
            // `/api/v1/alerts` look like a route that does not exist.
            preg_match_all('/@router\.(get|post|put|patch|delete)\(\s*[\'"]([^\'"]*)[\'"]/', $source, $hits, PREG_SET_ORDER);

            foreach ($hits as $hit) {
                $routes[strtoupper($hit[1]).' '.self::normalise($prefix.$hit[2])] = true;
            }
        }

        return $routes;
    }

    /**
     * Every v1 module paired with the aggregate prefix declared once in
     * `ai-engine/app/api/v1/router.py`, plus the application-level routes
     * FastAPI serves outside that prefix and the schema endpoints it is
     * configured with — all three of which docs/api.md publishes.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function engineSources(): array
    {
        $directory = base_path('../ai-engine/app/api/v1');
        $aggregate = (string) file_get_contents($directory.'/router.py');

        preg_match('/APIRouter\(\s*prefix\s*=\s*[\'"]([^\'"]*)[\'"]/', $aggregate, $prefix);
        $root = $prefix[1] ?? '';

        $sources = [];

        foreach (glob($directory.'/*.py') ?: [] as $file) {
            $sources[] = [$root, (string) file_get_contents($file)];
        }

        $main = (string) file_get_contents(base_path('../ai-engine/app/main.py'));

        if (preg_match_all('/^\s*(?:docs_url|redoc_url|openapi_url)\s*=\s*"([^"]+)"/m', $main, $hits) > 0) {
            foreach ($hits[1] as $url) {
                $sources[] = ['', '@router.get("'.$url.'")'];
            }
        }

        if (preg_match_all('/@app\.(get|post|put|patch|delete)\(\s*[\'"]([^\'"]+)[\'"]/', $main, $hits, PREG_SET_ORDER) > 0) {
            foreach ($hits as $hit) {
                $sources[] = ['', '@router.'.$hit[1].'("'.$hit[2].'")'];
            }
        }

        return $sources;
    }

    private static function normalise(string $path): string
    {
        $path = '/'.ltrim(trim($path), '/');

        return (string) preg_replace('/\{[^{}]+\}/', '{}', $path);
    }

    // ------------------------------------------------------------------
    // middleware
    // ------------------------------------------------------------------

    /**
     * The roles a route is restricted to, or null when it is open to any
     * authenticated user. The middleware class is resolved from the router's
     * own alias map rather than written down here, so re-registering the alias
     * in bootstrap/app.php keeps working and removing it fails loudly.
     *
     * @return array<int, string>|null
     */
    private function routeRoles(RouteDefinition $route): ?array
    {
        $alias = $this->middlewareAlias('EnsureRole');

        foreach ($route->gatherMiddleware() as $middleware) {
            [$name, $parameters] = array_pad(explode(':', $middleware, 2), 2, '');

            if (! $this->isMiddleware($name, $alias)) {
                continue;
            }

            $roles = array_map('trim', explode(',', $parameters));
            sort($roles);

            return $roles;
        }

        return null;
    }

    /**
     * The guard a route sits behind (`sanctum`, `web`, …) or null when it is
     * unauthenticated.
     */
    private function routeGuard(RouteDefinition $route): ?string
    {
        $alias = $this->middlewareAlias('Authenticate');

        foreach ($route->gatherMiddleware() as $middleware) {
            [$name, $parameters] = array_pad(explode(':', $middleware, 2), 2, '');

            if ($this->isMiddleware($name, $alias)) {
                return $parameters;
            }
        }

        return null;
    }

    /**
     * `gatherMiddleware()` hands back whatever the route file wrote, so a
     * grouped `role:admin,analyst` arrives unresolved while a per-route
     * `->middleware(EnsureRole::class.':admin')` arrives as the class name.
     * Both spellings name the same middleware and both have to be recognised.
     *
     * @param  class-string  $middleware
     */
    private function isMiddleware(string $name, string $middleware): bool
    {
        return $name === $middleware
            || ltrim($name, '\\') === ltrim($middleware, '\\')
            || str_ends_with(ltrim($name, '\\'), '\\'.class_basename($middleware));
    }

    /**
     * The alias the router registered for a middleware class, so the test never
     * hard-codes a fully qualified class name.
     */
    private function middlewareAlias(string $class): string
    {
        foreach ($this->app->make('router')->getMiddleware() as $alias => $middleware) {
            if (class_basename($middleware) === $class) {
                return $alias;
            }
        }

        $this->fail(sprintf(
            'bootstrap/app.php registers no middleware resolving to %s, so the route policy cannot be verified.',
            $class,
        ));
    }

    /**
     * @return array<int, string>|null
     */
    private function documentedRoles(string $cell): ?array
    {
        if (strtolower($cell) === 'any' || strtolower($cell) === 'none') {
            return null;
        }

        $roles = array_map(
            static fn (string $role): string => strtolower(trim($role)),
            explode(',', $cell),
        );
        sort($roles);

        return $roles;
    }

    // ------------------------------------------------------------------
    // markdown helpers
    // ------------------------------------------------------------------

    /**
     * Cells are split on unescaped pipes only: a `\|` inside a code span is a
     * literal pipe in a GFM table, not a cell boundary
     * (`{question\|query,top_k?}` on the RAG row is the one that needs it).
     *
     * @return array<int, string>|null
     */
    private function tableCells(string $line): ?array
    {
        $line = $this->trim($line);

        if (! str_starts_with($line, '|')) {
            return null;
        }

        $cells = preg_split('/(?<!\\\\)\|/', trim($line, '|')) ?: [];

        return array_map(fn (string $cell): string => $this->trim($cell), $cells);
    }

    private function isSeparator(?string $line): bool
    {
        if ($line === null) {
            return false;
        }

        $line = $this->trim($line);

        return $line !== '' && preg_match('/^[-:| ]+$/', $line) === 1 && str_contains($line, '-');
    }

    /**
     * @return array<int, string>
     */
    private function backticked(string $cell, ?string $prefix = null, bool $numericOnly = false): array
    {
        $found = [];
        $pattern = $numericOnly ? '/`([^`]+)`/' : ($prefix === null ? '/`([^`]+)`/' : '/`('.preg_quote($prefix, '/').'[^`]*)`/');

        while (preg_match($pattern, $cell, $match, PREG_OFFSET_CAPTURE) === 1) {
            $found[] = $match[1][0];
            $cell = substr($cell, $match[0][1] + strlen($match[0][0]));
        }

        return $found;
    }

    private function trim(string $value): string
    {
        return trim($value, " \t\r\n\x0B\0\"'");
    }
}
