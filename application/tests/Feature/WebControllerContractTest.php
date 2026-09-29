<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Dataset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The web layer's contract, pinned in one place.
 *
 * `WebWorkflowTest` and `ViewRenderingTest` both walk these routes, but they
 * assert on a feature at a time — that a mapping persists, that a page renders.
 * What they cannot see is the contract that spans all the routes at once, and
 * that is where the failures that look like something else live:
 *
 *  - A mutating action that redirects to a list the user then has to hunt for the
 *    thing they just did, or that says nothing at all, is indistinguishable from
 *    a button that did not work.
 *  - A field name in a Blade form that the controller's rules do not expect is a
 *    silent 422. The page reloads, the message is in a field that is not on the
 *    screen, and the report is "the button is broken".
 *  - A GET that writes turns every crawler, prefetcher and browser reload into a
 *    state change, and it is the failure nobody notices until the audit trail
 *    disagrees with the database.
 *  - A validation failure that renders 422 to a browser is a JSON error page
 *    shown to a person.
 *
 * The role matrix is asserted exhaustively rather than per route so that opening
 * a route to the wrong role cannot pass unnoticed: `EnsureRole` fails closed, and
 * the point of the matrix is that the *route* stays in step with it.
 */
class WebControllerContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * How the engine answers. Read at request time, never at fake-registration
     * time: `Http::fake()` stubs are matched first-registered-wins, so a second
     * `Http::fake()` inside a test could never override the one registered here.
     */
    protected string $engineMode = 'up';

    /**
     * The status the engine reports for an import job. `running` mirrors
     * nothing, so the refresh test has to ask for a terminal one.
     */
    protected string $jobStatus = 'running';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->fakeEngine();
    }

    protected function fakeEngine(): void
    {
        Http::fake(function (ClientRequest $request) {
            if ($this->engineMode === 'down') {
                throw new ConnectionException(
                    'cURL error 7: Failed to connect to fastapi.test port 80 (Connection refused)'
                );
            }

            $operation = self::operation($request->url());

            // `/health` answers a bare pydantic model; `AiEngineClient::decode()`
            // only unwraps `{success, data}` for everything else.
            if ($operation === 'health') {
                return Http::response(['status' => 'ok', 'app' => 'aidata-engine'], 200);
            }

            return Http::response(['success' => true, 'data' => $this->payload($operation)], 200);
        });
    }

    /**
     * The engine route, named the way `AiEngineClient` calls it.
     *
     * Pinned against the client's own paths rather than a loose prefix match:
     * `/imports/{id}` and `/imports/{id}/quality` are different endpoints, and a
     * fake that answers the preview payload to a quality call turns a payload
     * bug into a passing test.
     */
    protected static function operation(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = '/'.ltrim(Str::after($path, '/api/v1'), '/');

        return match (true) {
            $path === '/health' => 'health',
            (bool) preg_match('#^/analytics/#', $path) => 'analytics',
            $path === '/models' => 'models',
            (bool) preg_match('#^/models/\d+$#', $path) => 'model',
            (bool) preg_match('#^/models/\d+/promote$#', $path) => 'promote',
            $path === '/training/train' => 'train',
            $path === '/ai/report' => 'report',
            $path === '/ai/chat' => 'chat',
            $path === '/imports/upload' => 'upload',
            $path === '/imports/mapping' => 'mapping',
            $path === '/imports/commit' => 'commit',
            (bool) preg_match('#^/imports/preview/\d+$#', $path) => 'preview',
            (bool) preg_match('#^/imports/quality/\d+$#', $path) => 'quality',
            (bool) preg_match('#^/imports/jobs/\d+$#', $path) => 'importJob',
            default => 'unknown',
        };
    }

    /** @return array<string, mixed> */
    protected function payload(string $operation): array
    {
        return match ($operation) {
            'upload' => [
                'import_job_id' => 4242,
                'validation' => ['ok' => true, 'meta' => ['size_bytes' => 512, 'mime' => 'text/csv']],
            ],
            'preview' => [
                'row_count' => 12,
                'column_count' => 2,
                'columns' => [
                    ['name' => 'tanggal', 'dtype' => 'date', 'missing' => 0, 'unique' => 12],
                    ['name' => 'qty', 'dtype' => 'integer', 'missing' => 0, 'unique' => 5],
                ],
            ],
            // Above the 0.75 default threshold, so the passing branch is the one
            // the success path exercises.
            'quality' => ['score' => 0.95, 'passed' => true, 'breakdown' => [], 'issues' => []],
            'commit' => ['import_job_id' => 4242, 'status' => 'queued', 'total_rows' => 12],
            'importJob' => ['status' => $this->jobStatus, 'total_rows' => 4096],
            'mapping' => ['applied' => ['tanggal' => 'transaction_date']],
            'train' => ['model_id' => 4242, 'version' => 1, 'model_type' => 'forecast', 'name' => 'Model Kontrak'],
            'promote' => ['model_id' => 4242, 'version_id' => 7, 'to_status' => 'PRODUCTION'],
            'models' => [],
            'model' => ['id' => 4242, 'name' => 'Model Kontrak', 'versions' => []],
            'chat' => [
                'answer' => 'Margin kuartal pertama 31,2%.',
                'conversation_id' => 99,
                'evidence' => [],
                'steps' => 2,
            ],
            'report' => ['narrative' => 'Laporan mingguan.', 'kpi' => [], 'finance' => [], 'sections' => []],
            'analytics' => [
                'revenue' => 1250000000, 'orders' => 3412, 'units' => 18940,
                'aov' => 366354.0, 'growth_pct' => 12.4, 'margin_pct' => 31.2,
                'rows' => [], 'total_revenue' => 1, 'total_cogs' => 1, 'total_expenses' => 1,
                'gross_profit' => 1, 'net_profit' => 1,
            ],
            default => [],
        };
    }

    // ------------------------------------------------------------------
    // Route matrix
    // ------------------------------------------------------------------

    /**
     * Every route in `routes/web.php` with the status each role must get.
     *
     * `[method, route name, payload key, admin, analyst, viewer]`. The matrix
     * is the authorisation contract: `role:admin,analyst` may read and write,
     * `role:admin` is admin-only, and a bare `auth` route is open to all three.
     *
     * @return array<string, array{string, string, string|null, int, int, int}>
     */
    public static function webRoutes(): array
    {
        return [
            // public
            'GET /login' => ['GET', 'login', null, 302, 302, 302],

            // authenticated, any role
            'GET /' => ['GET', 'dashboard', null, 200, 200, 200],
            'GET /profile/password' => ['GET', 'password.edit', null, 200, 200, 200],
            'GET /datasets' => ['GET', 'datasets.index', null, 200, 200, 200],
            'GET /datasets/create' => ['GET', 'datasets.create', null, 200, 200, 200],
            'GET /datasets/{dataset}' => ['GET', 'datasets.show', null, 200, 200, 200],
            'GET /imports' => ['GET', 'imports.index', null, 200, 200, 200],
            'GET /imports/{dataset}' => ['GET', 'imports.show', null, 200, 200, 200],
            'GET /quality' => ['GET', 'quality.index', null, 200, 200, 200],
            'GET /analytics' => ['GET', 'analytics.index', null, 200, 200, 200],
            'GET /ml' => ['GET', 'ml.index', null, 200, 200, 200],
            'GET /reports' => ['GET', 'reports.index', null, 200, 200, 200],
            'GET /assistant' => ['GET', 'assistant.index', null, 200, 200, 200],
            'GET /assistant/threads/{thread}' => ['GET', 'assistant.threads.show', null, 302, 302, 302],
            'PUT /profile/password' => ['PUT', 'password.update', 'password', 302, 302, 302],

            // role:admin,analyst
            'POST /datasets' => ['POST', 'datasets.store', 'store', 302, 302, 403],
            'POST /datasets/{dataset}/preview' => ['POST', 'datasets.preview', 'preview', 302, 302, 403],
            'POST /datasets/{dataset}/mapping' => ['POST', 'datasets.mapping', 'mapping', 302, 302, 403],
            'POST /datasets/{dataset}/quality' => ['POST', 'datasets.quality', 'quality', 302, 302, 403],
            'POST /datasets/{dataset}/commit' => ['POST', 'datasets.commit', 'commit', 302, 302, 403],
            'DELETE /datasets/{dataset}' => ['DELETE', 'datasets.destroy', 'destroy', 302, 302, 403],
            'POST /assistant/threads' => ['POST', 'assistant.store', 'chat', 302, 302, 403],
            'DELETE /assistant/threads/{thread}' => ['DELETE', 'assistant.threads.destroy', 'destroyThread', 302, 302, 403],
            'POST /ml/train' => ['POST', 'ml.train', 'train', 302, 302, 403],

            // role:admin
            'POST /ml/{modelId}/promote' => ['POST', 'ml.promote', 'promote', 302, 403, 403],
            'GET /admin/users' => ['GET', 'admin.users.index', null, 200, 403, 403],
            'POST /admin/users' => ['POST', 'admin.users.store', 'user', 302, 403, 403],
            'PATCH /admin/users/{user}' => ['PATCH', 'admin.users.update', 'userUpdate', 302, 403, 403],
            'DELETE /admin/users/{user}' => ['DELETE', 'admin.users.destroy', 'userDestroy', 302, 403, 403],
            'GET /audit' => ['GET', 'audit.index', null, 200, 403, 403],
        ];
    }

    #[DataProvider('webRoutes')]
    public function test_every_web_route_answers_the_documented_status_for_each_role(
        string $method,
        string $routeName,
        ?string $payloadKey,
        int $admin,
        int $analyst,
        int $viewer
    ): void {
        $expected = ['admin' => $admin, 'analyst' => $analyst, 'viewer' => $viewer];

        foreach ($expected as $role => $status) {
            $user = $this->userFor($role);

            // Fresh fixtures per role: a delete in the previous role's pass must
            // not decide the status of the next one.
            $dataset = Dataset::factory()->importing()->create(['user_id' => $user->getKey()]);
            $thread = ChatThread::factory()->create(['user_id' => $user->getKey()]);
            $other = User::factory()->viewer()->create();

            $uri = $this->uriFor($routeName, $dataset, $thread, $other);
            $payload = $payloadKey === null ? [] : $this->payloadFor($payloadKey, $user, $other);

            $this->actingAs($user)
                ->from(route('dashboard'))
                ->call($method, $uri, $payload)
                ->assertStatus($status);
        }
    }

    // ------------------------------------------------------------------
    // Flash contract
    // ------------------------------------------------------------------

    /**
     * Every mutating action, with the field names its own Blade form submits.
     *
     * The payloads are the form bodies verbatim — `mappings[tanggal]`,
     * `run_async`, `version_id`, the `file` input — so a controller whose rules
     * and a view whose `name=` attributes drift apart fails here as a 302 back
     * with errors instead of a 422, which is the failure that reads as "the
     * button is broken".
     *
     * `from` is the page the form actually sits on, because `back()` reads the
     * referer: pointing it at an unrelated page would let an action that
     * redirects to a dead end pass. `destination` is where the action must then
     * land, and null means the route's own redirect is already the right answer
     * (a delete belongs back on the list it came from).
     *
     * @return array<string, array{string, string, string, string, string|null}>
     */
    public static function mutatingActions(): array
    {
        return [
            'login' => ['POST', 'login.store', 'login', 'login', null],
            'logout' => ['POST', 'logout', 'logout', 'dashboard', null],
            'password update' => ['PUT', 'password.update', 'password', 'password.edit', 'password.edit'],
            'dataset upload' => ['POST', 'datasets.store', 'store', 'datasets.create', 'datasets.show'],
            'dataset delete' => ['DELETE', 'datasets.destroy', 'destroy', 'datasets.show', null],
            'dataset preview' => ['POST', 'datasets.preview', 'preview', 'datasets.show', 'datasets.show'],
            'dataset mapping' => ['POST', 'datasets.mapping', 'mapping', 'datasets.show', 'datasets.show'],
            'dataset quality' => ['POST', 'datasets.quality', 'quality', 'datasets.show', 'datasets.show'],
            'dataset commit' => ['POST', 'datasets.commit', 'commit', 'datasets.show', 'imports.show'],
            'assistant turn' => ['POST', 'assistant.store', 'chat', 'assistant.index', 'assistant.index'],
            'thread delete' => ['DELETE', 'assistant.threads.destroy', 'destroyThread', 'assistant.index', null],
            'ml train' => ['POST', 'ml.train', 'train', 'ml.index', 'ml.index'],
            'ml promote' => ['POST', 'ml.promote', 'promote', 'ml.index', null],
        ];
    }

    #[DataProvider('mutatingActions')]
    public function test_a_mutating_action_redirects_with_a_success_flash(
        string $method,
        string $routeName,
        string $payloadKey,
        string $from,
        ?string $destination
    ): void {
        $user = User::factory()->admin()->create();
        $dataset = Dataset::factory()->importing()->create(['user_id' => $user->getKey()]);
        $thread = ChatThread::factory()->create(['user_id' => $user->getKey()]);
        $other = User::factory()->viewer()->create();

        $payload = $this->payloadFor($payloadKey, $user, $other, $thread);
        $referer = $this->pageFor($from, $dataset, $thread);

        // `login.store` sits behind `guest`, so it must be reached with no
        // established session; everything else needs one.
        $response = $routeName === 'login.store'
            ? $this->from($referer)->post(route($routeName), $payload)
            : $this->actingAs($user)
                ->from($referer)
                ->call($method, $this->uriFor($routeName, $dataset, $thread, $other), $payload);

        $response->assertStatus(302);
        $response->assertSessionHas('status');

        if ($destination !== null) {
            // An upload lands on the row it just created, which is not the
            // fixture the request was built against.
            $expected = $routeName === 'datasets.store'
                ? route('datasets.show', Dataset::query()->orderByDesc('id')->firstOrFail())
                : $this->pageFor($destination, $dataset, $thread);

            $response->assertRedirect($expected);
        }
    }

    /**
     * Failure paths, one per action that owns one.
     *
     * Two shapes are covered, because both have to leave the user with something
     * on screen: an action that decides the failure itself flashes `error`, and
     * a rejected input redirects back with the errors in the session. Neither
     * may reach the browser as a 5xx.
     *
     * @return array<string, array{string, string, string, string, string, bool}>
     */
    public static function mutatingFailurePaths(): array
    {
        return [
            // engine down: the global AiEngineException renderable flashes `error`
            'upload with the engine down' => ['POST', 'datasets.store', 'store', 'error', 'flash', true],
            'preview with the engine down' => ['POST', 'datasets.preview', 'preview', 'error', 'flash', true],
            'mapping with the engine down' => ['POST', 'datasets.mapping', 'mapping', 'error', 'flash', true],
            'quality with the engine down' => ['POST', 'datasets.quality', 'quality', 'error', 'flash', true],
            'commit with the engine down' => ['POST', 'datasets.commit', 'commit', 'error', 'flash', true],
            'train with the engine down' => ['POST', 'ml.train', 'train', 'error', 'flash', true],
            'promote with the engine down' => ['POST', 'ml.promote', 'promote', 'error', 'flash', true],
            'assistant turn with the engine down' => ['POST', 'assistant.store', 'chat', 'error', 'flash', true],

            // decided by the controller, no engine needed
            'mapping with nothing mapped' => ['POST', 'datasets.mapping', 'emptyMapping', 'error', 'flash', true],

            // rejected input: the errors belong in the session, not in a JSON body
            'login with a wrong password' => ['POST', 'login.store', 'wrongLogin', 'email', 'errors', false],
            'password change with the wrong current password' => [
                'PUT', 'password.update', 'wrongPassword', 'current_password', 'errors', true,
            ],
            'upload with no file' => ['POST', 'datasets.store', 'noFile', 'file', 'errors', true],
        ];
    }

    #[DataProvider('mutatingFailurePaths')]
    public function test_a_mutating_action_reports_its_failure_path(
        string $method,
        string $routeName,
        string $payloadKey,
        string $expectedKey,
        string $style,
        bool $authenticated
    ): void {
        $this->engineMode = 'down';

        $user = User::factory()->admin()->create();
        $dataset = Dataset::factory()->importing()->create(['user_id' => $user->getKey()]);
        $thread = ChatThread::factory()->create(['user_id' => $user->getKey()]);
        $other = User::factory()->viewer()->create();

        $payload = $this->payloadFor($payloadKey, $user, $other, $thread);
        $uri = $this->uriFor($routeName, $dataset, $thread, $other);

        $response = $authenticated
            ? $this->actingAs($user)
                ->from($this->pageFor('datasets.show', $dataset, $thread))
                ->call($method, $uri, $payload)
            : $this->from(route('login'))->call($method, $uri, $payload);

        $response->assertStatus(302);

        if ($style === 'flash') {
            $response->assertSessionHas($expectedKey);
        } else {
            $response->assertSessionHasErrors($expectedKey);
        }
    }

    // ------------------------------------------------------------------
    // GET must not write
    // ------------------------------------------------------------------

    /**
     * Every page that takes no model, as `[route name, query]` pairs.
     *
     * The provider cannot call `route()`: it is resolved before the
     * application is booted, so it hands back names and the test builds the
     * URLs.
     *
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function readOnlyPages(): array
    {
        return [
            'dashboard' => ['dashboard', []],
            'password' => ['password.edit', []],
            'datasets' => ['datasets.index', []],
            'dataset search' => ['datasets.index', ['q' => 'Penjualan', 'status' => 'uploaded', 'dataset_type' => 'sales']],
            'dataset create' => ['datasets.create', []],
            'imports' => ['imports.index', []],
            'quality' => ['quality.index', []],
            'quality filtered' => ['quality.index', ['dataset_type' => 'sales', 'verdict' => 'pass']],
            'analytics' => ['analytics.index', []],
            'analytics filtered' => ['analytics.index', ['granularity' => 'monthly', 'branch' => 'BR-01']],
            'ml' => ['ml.index', []],
            'reports' => ['reports.index', []],
            'reports monthly' => ['reports.index', ['period' => 'monthly']],
            'assistant' => ['assistant.index', []],
            'assistant with a thread' => ['assistant.index', ['thread' => 1]],
            'admin users' => ['admin.users.index', []],
            'admin users filtered' => ['admin.users.index', ['q' => 'Uji', 'role' => 'viewer']],
            'audit' => ['audit.index', []],
            'audit filtered' => ['audit.index', ['action' => 'auth.login', 'actor' => 'admin']],
        ];
    }

    #[DataProvider('readOnlyPages')]
    public function test_a_get_does_not_write_to_the_database(string $routeName, array $query): void
    {
        $user = User::factory()->admin()->create();
        Dataset::factory()->importing()->create(['user_id' => $user->getKey()]);
        ChatThread::factory()->create(['user_id' => $user->getKey()]);

        $uri = route($routeName, $query);
        $before = $this->rowCounts();

        $this->actingAs($user)->get($uri)->assertOk();

        $this->assertSame(
            $before,
            $this->rowCounts(),
            "GET {$uri} changed a row count: a read route is writing to the database.",
        );
    }

    /**
     * The three pages that resolve a model, which a provider cannot build.
     */
    public function test_a_model_page_does_not_write_to_the_database(): void
    {
        $user = User::factory()->admin()->create();
        $dataset = Dataset::factory()->importing()->create(['user_id' => $user->getKey()]);
        $thread = ChatThread::factory()->create(['user_id' => $user->getKey()]);

        $before = $this->rowCounts();

        $this->actingAs($user)->get(route('datasets.show', $dataset))->assertOk();
        $this->actingAs($user)->get(route('imports.show', $dataset))->assertOk();
        $this->actingAs($user)->get(route('assistant.threads.show', $thread))->assertRedirect();

        $this->assertSame(
            $before,
            $this->rowCounts(),
            'A model page changed a row count: a GET is writing to the database.',
        );
    }

    /**
     * `imports.show` writes on `?refresh=1`, and this is the record of it.
     *
     * `DatasetIngestionService::syncStatus()` calls `save()` and records an audit
     * row, and the "Perbarui" link in `resources/views/imports/show.blade.php`
     * reaches it with a plain GET. A GET that mutates breaks prefetch, breaks the
     * browser's back button, and makes the audit trail disagree with a request
     * that was never meant to change anything. The fix is a POST form on that
     * link, which lives in a view this change does not own, so the behaviour is
     * pinned here rather than left unasserted.
     */
    public function test_the_import_refresh_parameter_is_the_one_get_that_writes(): void
    {
        $user = User::factory()->admin()->create();
        $dataset = Dataset::factory()->importing()->create([
            'user_id' => $user->getKey(),
            'import_job_id' => 4242,
            'status' => 'importing',
        ]);

        $this->jobStatus = 'succeeded';

        $this->actingAs($user)
            ->get(route('imports.show', ['dataset' => $dataset, 'refresh' => 1]))
            ->assertOk();

        $this->assertTrue(
            AuditLog::query()->where('action', 'dataset.status_synced')->exists(),
            'The refresh link no longer mirrors the job. If that was fixed in the view, '
            .'delete this test rather than leaving it asserting that a GET writes.',
        );
    }

    // ------------------------------------------------------------------
    // Validation redirects instead of rendering 422
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{string, string, array<string, mixed>, string}>
     */
    public static function invalidSubmissions(): array
    {
        return [
            'password change with no confirmation' => ['PUT', 'password.update', [
                'current_password' => 'password', 'password' => 'RahasiaKuat1',
            ], 'password'],
            'password change with a weak password' => ['PUT', 'password.update', [
                'current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'short',
            ], 'password'],
            'upload with no file' => ['POST', 'datasets.store', ['dataset_type' => 'sales'], 'file'],
            'upload with an unknown type' => ['POST', 'datasets.store', [
                'file' => 'placeholder', 'dataset_type' => 'bukan-tipe',
            ], 'dataset_type'],
            'upload with an over long name' => ['POST', 'datasets.store', [
                'file' => 'placeholder', 'dataset_type' => 'sales', 'name' => str_repeat('a', 151),
            ], 'name'],
            'mapping with no payload' => ['POST', 'datasets.mapping', [], 'mappings'],
            'mapping with a non string target' => ['POST', 'datasets.mapping', [
                'mappings' => ['tanggal' => ['nested']],
            ], 'mappings.tanggal'],
            'commit with a non boolean flag' => ['POST', 'datasets.commit', ['run_async' => 'ya'], 'run_async'],
            'assistant with an empty message' => ['POST', 'assistant.store', ['message' => ''], 'message'],
            'assistant with an over long message' => ['POST', 'assistant.store', [
                'message' => str_repeat('a', 4001),
            ], 'message'],
            'assistant with a non numeric thread' => ['POST', 'assistant.store', [
                'message' => 'Halo', 'thread_id' => 'abc',
            ], 'thread_id'],
            'ml train with an unknown type' => ['POST', 'ml.train', [
                'model_type' => 'ramalan', 'name' => 'Model',
            ], 'model_type'],
            'ml train with no name' => ['POST', 'ml.train', ['model_type' => 'forecast'], 'name'],
            'ml train with an over long name' => ['POST', 'ml.train', [
                'model_type' => 'forecast', 'name' => str_repeat('a', 101),
            ], 'name'],
            'ml train with a malformed params blob' => ['POST', 'ml.train', [
                'model_type' => 'forecast', 'name' => 'Model', 'params' => '{not json',
            ], 'params'],
            'ml promote with no version' => ['POST', 'ml.promote', [], 'version_id'],
            'ml promote with an unknown status' => ['POST', 'ml.promote', [
                'version_id' => 3, 'to_status' => 'STAGED_MAYBE',
            ], 'to_status'],
        ];
    }

    #[DataProvider('invalidSubmissions')]
    public function test_a_rejected_submission_redirects_back_with_errors_not_a_422(
        string $method,
        string $routeName,
        array $payload,
        string $field
    ): void {
        $user = User::factory()->admin()->create();
        $dataset = Dataset::factory()->importing()->create(['user_id' => $user->getKey()]);
        $thread = ChatThread::factory()->create(['user_id' => $user->getKey()]);
        $other = User::factory()->viewer()->create();

        $response = $this->actingAs($user)
            ->from(route('datasets.show', $dataset))
            ->call($method, $this->uriFor($routeName, $dataset, $thread, $other), $this->resolveUploads($payload));

        $this->assertNotSame(422, $response->getStatusCode(), "{$method} {$routeName} answered a browser with 422.");
        $response->assertRedirect();
        $response->assertSessionHasErrors($field);
    }

    /**
     * @return array<string, array{string, string, array<string, string>, string}>
     */
    public static function invalidLogins(): array
    {
        return [
            'login with no password' => ['POST', 'login.store', ['email' => 'a@example.com'], 'password'],
            'login with a malformed email' => ['POST', 'login.store', [
                'email' => 'not-an-email', 'password' => 'x',
            ], 'email'],
        ];
    }

    #[DataProvider('invalidLogins')]
    public function test_a_rejected_login_redirects_back_with_errors_not_a_422(
        string $method,
        string $routeName,
        array $payload,
        string $field
    ): void {
        $this->from(route('login'))
            ->call($method, route($routeName), $payload)
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors($field);
    }

    public function test_a_rejected_get_redirects_back_with_errors_not_a_422(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->from(route('reports.index'))
            ->get(route('reports.index', ['period' => 'decade']))
            ->assertRedirect(route('reports.index'))
            ->assertSessionHasErrors('period');
    }

    // ------------------------------------------------------------------
    // The controller and the view agree
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{string, string, list<string>}>
     */
    public static function pageViewVariables(): array
    {
        return [
            'dashboard' => ['dashboard', 'dashboard', ['stats', 'engineHealth', 'kpi', 'recentDatasets', 'recentThreads']],
            'password' => ['password.edit', 'profile.password', ['user']],
            'datasets' => ['datasets.index', 'datasets.index', ['datasets', 'datasetTypes', 'statuses', 'filters']],
            'dataset create' => ['datasets.create', 'datasets.create', ['datasetTypes', 'maxUploadMb', 'allowedExtensions']],
            'dataset show' => ['datasets.show', 'datasets.show', ['dataset', 'sampleRows', 'quality', 'threshold']],
            'imports' => ['imports.index', 'imports.index', ['datasets']],
            'import show' => ['imports.show', 'imports.show', ['dataset', 'job']],
            'quality' => ['quality.index', 'quality.index', ['datasets', 'breakdown', 'threshold', 'verdicts', 'filters']],
            'analytics' => ['analytics.index', 'analytics.index', [
                'kpi', 'trend', 'rfm', 'abc', 'cohort', 'branches', 'finance', 'filters', 'engineAvailable',
            ]],
            'ml' => ['ml.index', 'ml.index', ['models', 'selected', 'modelTypes', 'canApprove', 'error']],
            'reports' => ['reports.index', 'reports.index', ['report', 'periods', 'period', 'engineAvailable']],
            'assistant' => ['assistant.index', 'assistant.index', ['threads', 'thread', 'messages', 'messagesTruncated']],
        ];
    }

    #[DataProvider('pageViewVariables')]
    public function test_every_page_hands_its_view_the_documented_variables(
        string $routeName,
        string $view,
        array $expected
    ): void {
        $user = User::factory()->admin()->create();
        $dataset = Dataset::factory()->importing()->create(['user_id' => $user->getKey()]);
        ChatThread::factory()->create(['user_id' => $user->getKey()]);

        $response = $this->actingAs($user)
            ->get($this->uriFor($routeName, $dataset, ChatThread::query()->first(), User::query()->first()))
            ->assertOk()
            ->assertViewIs($view);

        $data = $response->viewData();

        foreach ($expected as $variable) {
            $this->assertArrayHasKey(
                $variable,
                $data,
                "{$routeName} does not pass `{$variable}` to `{$view}`. A variable the controller "
                .'drops renders as an empty section, and one the view invents renders as null.',
            );
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array<string, int> */
    protected function rowCounts(): array
    {
        $counts = [];

        foreach ([User::class, Dataset::class, ChatThread::class, ChatMessage::class, AuditLog::class] as $model) {
            $counts[$model] = $model::query()->count();
        }

        return $counts;
    }

    protected function userFor(string $role): User
    {
        return User::factory()->{$role}()->create();
    }

    protected function uriFor(string $routeName, Dataset $dataset, ChatThread $thread, User $user): string
    {
        return match ($routeName) {
            'datasets.show', 'datasets.destroy', 'datasets.preview', 'datasets.mapping',
            'datasets.quality', 'datasets.commit', 'imports.show' => route($routeName, $dataset),
            'assistant.threads.show', 'assistant.threads.destroy' => route($routeName, $thread),
            'ml.promote' => route($routeName, ['modelId' => 4242]),
            'admin.users.update', 'admin.users.destroy' => route($routeName, $user),
            default => route($routeName),
        };
    }

    /**
     * The page a named form lives on, so `back()` has somewhere real to go.
     */
    protected function pageFor(string $page, Dataset $dataset, ChatThread $thread): string
    {
        return match ($page) {
            'datasets.show' => route('datasets.show', $dataset),
            'datasets.create' => route('datasets.create'),
            'imports.show' => route('imports.show', $dataset),
            // The assistant round trip lands on the thread it just extended,
            // which is only knowable once the thread exists.
            'assistant.index' => route('assistant.index', ['thread' => $thread->getKey()]),
            default => route($page),
        };
    }

    /** @return array<string, mixed> */
    protected function payloadFor(string $key, User $user, User $other, ?ChatThread $thread = null): array
    {
        $csv = fn (): UploadedFile => UploadedFile::fake()
            ->createWithContent('penjualan.csv', "tanggal,qty\n2026-01-05,3\n");

        return match ($key) {
            'login' => ['email' => $user->email, 'password' => 'password'],
            'wrongLogin' => ['email' => $user->email, 'password' => 'bukan-password'],
            'logout' => [],
            'password' => [
                'current_password' => 'password',
                'password' => 'PasswordBaru123!',
                'password_confirmation' => 'PasswordBaru123!',
            ],
            'wrongPassword' => [
                'current_password' => 'bukan-password',
                'password' => 'PasswordBaru123!',
                'password_confirmation' => 'PasswordBaru123!',
            ],
            'store' => [
                'file' => $csv(),
                'dataset_type' => 'sales',
                'name' => 'Penjualan Uji Kontrak',
            ],
            'noFile' => ['dataset_type' => 'sales', 'name' => 'Tanpa Berkas'],
            'destroy' => [],
            'preview' => [],
            'mapping' => ['mappings' => ['tanggal' => 'transaction_date']],
            'emptyMapping' => ['mappings' => ['tanggal' => '   ']],
            'quality' => [],
            'commit' => ['run_async' => true],
            // The `thread_id` hidden input the assistant form always posts.
            'chat' => [
                'message' => 'Berapa margin kuartal pertama?',
                'thread_id' => $thread?->getKey(),
            ],
            'destroyThread' => [],
            'train' => ['model_type' => 'forecast', 'name' => 'Model Kontrak', 'params' => '{"horizon":30}'],
            'promote' => ['version_id' => 7, 'to_status' => 'PRODUCTION'],
            'user' => [
                'name' => 'Pengguna Uji Kontrak',
                'email' => 'uji-kontrak@example.com',
                'password' => 'PasswordBaru123!',
                'role' => 'viewer',
            ],
            'userUpdate' => ['name' => 'Pengguna Diubah', 'email' => $other->email, 'role' => 'analyst'],
            'userDestroy' => [],
            default => [],
        };
    }

    /**
     * A data provider cannot build an `UploadedFile`, so the placeholder is
     * swapped for a real one here.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function resolveUploads(array $payload): array
    {
        if (($payload['file'] ?? null) === 'placeholder') {
            $payload['file'] = UploadedFile::fake()
                ->createWithContent('penjualan.csv', "tanggal,qty\n2026-01-05,3\n");
        }

        return $payload;
    }
}
