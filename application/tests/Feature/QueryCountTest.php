<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Dataset;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pins the number of SQL statements each page and list endpoint issues.
 *
 * A reading review of the Blade templates found no N+1, but that conclusion
 * lived only in a reviewer's head: nothing failed if the next person added
 * `$dataset->user->name` inside a `@foreach`. The regression it catches is
 * quiet — the page still renders, it just goes from 2 queries to 200 — so this
 * file exists to make it loud.
 *
 * The assertion that matters is the SHAPE, not the absolute number. Every page
 * is rendered twice, once against a 5-row corpus and once against a 50-row
 * corpus, and the two counts must be identical. A count that grows with the
 * row count is an N+1 whatever the number happens to be, and a magic ceiling
 * cannot express that: it can be satisfied by an N+1 that stays under the
 * magic number, and it breaks on a legitimate extra aggregate. The budgets in
 * `PAGES` are the second half of the contract — a ceiling derived from what
 * the page actually needs, plus a floor so a page that stops reading its data
 * fails too.
 */
class QueryCountTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The corpus the budgets were measured against: the sizes a real
     * deployment of this platform accumulates, not the sizes that make a
     * paginator return one page.
     */
    private const REALISTIC = ['datasets' => 20, 'threads' => 25, 'auditLogs' => 100, 'users' => 20, 'engineRows' => 12];

    /** The two row counts the shape assertion compares. */
    private const FEW_ROWS = 5;

    private const MANY_ROWS = 50;

    /**
     * Query budgets in statements per request.
     *
     * `min` is a floor as well as a ceiling for a reason: a page that drops to
     * zero queries has stopped reading the data it renders, which is a
     * different regression and just as silent as an N+1.
     *
     * `why` exists so the next person who trips a number can raise it with a
     * reason instead of deleting the assertion. Raising `max` is fine — the
     * shape test still fails a real N+1. Deleting the entry is not: that is
     * the only thing that makes the page unguarded again.
     *
     * @var array<string, array{min: int, max: int, why: string}>
     */
    private const PAGES = [
        'dashboard' => [
            'min' => 6,
            'max' => 7,
            'why' => 'Four dataset aggregates (total, committed, quarantined, in-flight) plus the two '
                .'"recent" lists the page shows: 6. The budget is 7 so a fifth aggregate can be added '
                .'deliberately. Both recent lists are already LIMIT-ed, so a row-scaling query here is '
                .'always a bug, never a legitimate cost.',
        ],

        'datasets.index' => [
            'min' => 2,
            'max' => 3,
            'why' => 'A paginated list: one COUNT for the paginator, one SELECT for the page. The status and '
                .'type filter dropdowns are enums, not queries, and must stay that way. 3 leaves room to '
                .'eager-load a relation the table starts showing (withCount counts as one statement).',
        ],

        'datasets.show' => [
            'min' => 1,
            'max' => 2,
            'why' => 'One statement: the route-model binding. The column profile, preview rows and quality '
                .'breakdown all live in the row\'s own JSON columns, so the page needs no second read. 2 '
                .'leaves room for a single eager-loaded relation.',
        ],

        'imports.index' => [
            'min' => 2,
            'max' => 3,
            'why' => 'Same shape as datasets.index: COUNT plus the page of datasets that have an import job. '
                .'It shares the table, so it is expected to cost exactly what datasets.index costs.',
        ],

        'imports.show' => [
            'min' => 1,
            'max' => 2,
            'why' => 'One statement: the route-model binding. The job status is read from the dataset\'s '
                .'metadata JSON; it is only fetched over HTTP when ?refresh=1 is passed explicitly, which '
                .'this test does not do.',
        ],

        'quality.index' => [
            'min' => 5,
            'max' => 7,
            'why' => 'A paginated list (2) plus three breakdown counters — pass, quarantine, unscored — that '
                .'the page renders above the table: 5. These are three separate counts() calls because they '
                .'are three separate verdicts; collapsing them into one conditional aggregate would be a '
                .'legitimate way to reach 3.',
        ],

        'analytics.index' => [
            'min' => 0,
            'max' => 0,
            'why' => 'Every figure on this page comes from the AI engine over HTTP (kpi, trend, rfm, abc, '
                .'cohort, branches, finance) and Laravel keeps no local copy of any of it. Zero is the '
                .'correct number, not a generous budget: the moment one row is looked up locally to decorate '
                .'an engine result, the engine is no longer the only source of truth for that page.',
        ],

        'ml.index' => [
            'min' => 0,
            'max' => 0,
            'why' => 'The model registry lives in the engine. The page reads the signed-in user from the '
                .'session, never from a query. Zero, for the same reason as analytics.index.',
        ],

        'assistant.index' => [
            'min' => 2,
            'max' => 3,
            'why' => 'The thread sidebar (1) plus the messages of the one open thread (1). The sidebar is '
                .'LIMIT 25 and the transcript LIMIT 100, so neither statement scales with the table — the '
                .'page is bounded on both sides and needs no eager load. 3 covers eager-loading the user '
                .'onto the sidebar rows if the UI ever shows an owner.',
        ],

        'reports.index' => [
            'min' => 0,
            'max' => 0,
            'why' => 'The executive report is generated by the engine, narrative included. Nothing on this '
                .'page is read from the database. Zero.',
        ],

        'admin.users.index' => [
            'min' => 2,
            'max' => 3,
            'why' => 'A paginated list: COUNT plus the page. The role filter is a PHP enum, not a query. Same '
                .'shape as datasets.index, and it should stay identical to it.',
        ],

        'audit.index' => [
            'min' => 3,
            'max' => 4,
            'why' => 'A paginated log (2) plus the DISTINCT action catalogue that fills the filter dropdown (1). '
                .'The catalogue is one query, not one per row — that is the whole point of the distinct '
                .'pluck, and a render that iterated actions one at a time is exactly what the shape test '
                .'catches.',
        ],

        'password.edit' => [
            'min' => 0,
            'max' => 0,
            'why' => 'A static form. The user comes from the session, so the page needs no query at all. '
                .'Anything above zero means the form started reading a table it does not display.',
        ],

        'api.datasets.index' => [
            'min' => 2,
            'max' => 3,
            'why' => 'The token-API mirror of datasets.index: COUNT plus the page, presented row by row from '
                .'the already-loaded collection. The presenter reads columns off each row it was given; a '
                .'presenter that reached back for a relation would turn this into 1 + N.',
        ],
    ];

    /** @var list<string> */
    private array $queries = [];

    /**
     * Every URL the engine was asked for during the last measurement.
     *
     * Collected inside the fake rather than with `Http::recorded()`: the
     * Guzzle stub handler short-circuits the stack before the recorder runs,
     * so a faked call never reaches `Http::recorded()`.
     *
     * @var list<string>
     */
    private array $engineUrls = [];

    private User $admin;

    private Dataset $target;

    /** How many rows the fake engine returns per list endpoint. */
    private int $engineRows = 1;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->admin = User::factory()->admin()->create([
            'name' => 'Administrator Platform',
            'email' => 'admin@example.com',
        ]);

        // The listener is attached here, deliberately AFTER RefreshDatabase has
        // migrated the schema and AFTER the admin row above was written. A
        // listener attached earlier would count the harness's own setup and
        // the measurement would be meaningless.
        DB::listen(function (QueryExecuted $event): void {
            $this->queries[] = $event->sql;
        });
    }

    // ------------------------------------------------------------------
    // the budget
    // ------------------------------------------------------------------

    /**
     * Every page, rendered against a realistic corpus, must land inside its
     * documented budget.
     */
    public function test_every_page_stays_inside_its_documented_query_budget(): void
    {
        $this->seedCorpus(...self::REALISTIC);

        $measured = $this->measureEveryPage();

        $this->assertSame([], $this->budgetViolations($measured), $this->report($measured));
    }

    // ------------------------------------------------------------------
    // the shape — the assertion that actually catches an N+1
    // ------------------------------------------------------------------

    /**
     * The load-bearing test. A page whose query count grows when its table
     * grows is doing one query per row, whatever the absolute number is. This
     * cannot be satisfied by any magic constant: 5 rows and 50 rows have to
     * cost exactly the same.
     */
    public function test_no_page_issues_more_queries_as_its_rows_grow(): void
    {
        $this->seedCorpus(
            datasets: self::FEW_ROWS,
            threads: self::FEW_ROWS,
            auditLogs: self::FEW_ROWS,
            users: self::FEW_ROWS,
            engineRows: self::FEW_ROWS,
        );

        $few = $this->measureEveryPage();

        $this->seedCorpus(
            datasets: self::MANY_ROWS,
            threads: self::MANY_ROWS,
            auditLogs: self::MANY_ROWS,
            users: self::MANY_ROWS,
            engineRows: self::MANY_ROWS,
        );

        $many = $this->measureEveryPage();

        $drift = [];

        foreach ($few as $page => $count) {
            if ($many[$page] !== $count) {
                $drift[] = sprintf('%-22s %d queries at %d rows, %d at %d rows', $page, $count, self::FEW_ROWS, $many[$page], self::MANY_ROWS);
            }
        }

        $this->assertSame([], $drift, sprintf(
            "Every table grew %dx (datasets, chat threads with messages, audit rows, users, and the rows\n"
            ."the fake engine returns). A page whose count moved with it is issuing one query per row:\n\n  %s\n\n"
            ."Current counts:\n%s",
            self::MANY_ROWS / self::FEW_ROWS,
            implode("\n  ", $drift),
            $this->table($few, $many),
        ));
    }

    // ------------------------------------------------------------------
    // engine-backed pages
    // ------------------------------------------------------------------

    /**
     * The engine pages get no database statements at all — not "a few", zero.
     * They are the one place where a flat count is genuinely achievable,
     * because Laravel holds no local copy of an engine result.
     *
     * The endpoint assertion is what keeps this honest: without it, zero
     * queries would also be what a page that quietly stopped calling the
     * engine looks like. Endpoints rather than call counts, because
     * `AiEngineClient` retries and a retry would otherwise make the number
     * depend on the client's backoff policy instead of on this page.
     */
    public function test_an_engine_backed_page_makes_no_query_per_engine_call(): void
    {
        $this->seedCorpus(...self::REALISTIC);

        $expected = [
            'analytics.index' => [
                '/api/v1/analytics/kpi', '/api/v1/analytics/trend', '/api/v1/analytics/rfm',
                '/api/v1/analytics/abc', '/api/v1/analytics/cohort', '/api/v1/analytics/branches',
                '/api/v1/analytics/finance',
            ],
            'ml.index' => ['/api/v1/models'],
            'reports.index' => ['/api/v1/ai/report'],
        ];

        foreach ($expected as $page => $endpoints) {
            $queries = $this->measure($page);

            $called = array_map(
                static fn (string $url): string => parse_url($url, PHP_URL_PATH) ?: $url,
                $this->engineUrls,
            );

            $this->assertSame(
                $endpoints,
                array_values(array_unique($called)),
                "{$page} must be backed by the engine: it must ask for these endpoints and no others. "
                .'A page that quietly stopped calling the engine would pass the zero-query assertion below for the wrong reason.',
            );

            $this->assertSame(0, $queries, "{$page} is backed entirely by the engine and must issue no SQL.\n".$this->sqlLog());
        }
    }

    // ------------------------------------------------------------------
    // API list endpoints
    // ------------------------------------------------------------------

    /**
     * The two list endpoints, at 5 rows and at 50 rows.
     *
     * The `meta.total` assertions matter as much as the query counts: a count
     * that stayed flat because the endpoint stopped reading the rows would
     * pass the shape test and fail the platform. These are the two ways a flat
     * count can be hollow, and both are checked.
     */
    public function test_the_list_endpoints_do_not_issue_more_queries_as_their_rows_grow(): void
    {
        $counts = [];
        $rowsSeen = [];

        foreach ([self::FEW_ROWS, self::MANY_ROWS] as $rows) {
            $this->seedCorpus(
                datasets: $rows,
                threads: self::FEW_ROWS,
                auditLogs: self::FEW_ROWS,
                users: $rows,
                engineRows: self::FEW_ROWS,
            );

            $counts[$rows] = [
                'api.datasets.index' => $this->measure('api.datasets.index'),
                'admin.users.index' => $this->measure('admin.users.index'),
            ];

            $rowsSeen[$rows] = [
                'api.datasets.index' => $this->lastApiTotal,
                'admin.users.index' => $this->lastPaginatorTotal,
            ];
        }

        // The flat query count must not be flat because the data went missing.
        $this->assertSame(
            ['api.datasets.index' => self::FEW_ROWS, 'admin.users.index' => self::FEW_ROWS + 1],
            $rowsSeen[self::FEW_ROWS],
            'At 5 rows both endpoints must report every seeded row back to the caller, plus the signed-in admin.',
        );

        $this->assertSame(
            ['api.datasets.index' => self::MANY_ROWS, 'admin.users.index' => self::MANY_ROWS + 1],
            $rowsSeen[self::MANY_ROWS],
            'At 50 rows both endpoints must report every seeded row back to the caller, plus the signed-in admin.',
        );

        $this->assertSame(
            $counts[self::FEW_ROWS],
            $counts[self::MANY_ROWS],
            "Both list endpoints cost the same at 5 and at 50 rows.\n".$this->table($counts[self::FEW_ROWS], $counts[self::MANY_ROWS]),
        );

        $this->assertSame([], $this->budgetViolations($counts[self::MANY_ROWS]), $this->report($counts[self::MANY_ROWS]));
    }

    // ------------------------------------------------------------------
    // measuring
    // ------------------------------------------------------------------

    private int $lastApiTotal = 0;

    private int $lastPaginatorTotal = 0;

    /**
     * Render every page once and return its statement count.
     *
     * @return array<string, int>
     */
    private function measureEveryPage(): array
    {
        $measured = [];

        foreach (array_keys(self::PAGES) as $page) {
            $measured[$page] = $this->measure($page);
        }

        return $measured;
    }

    private function measure(string $page): int
    {
        $this->queries = [];
        $this->engineUrls = [];
        $this->fakeEngine();

        if ($page === 'api.datasets.index') {
            Sanctum::actingAs($this->admin);

            $response = $this->getJson($this->url($page));
        } else {
            $response = $this->actingAs($this->admin)->get($this->url($page));
        }

        $response->assertOk();

        if ($page === 'api.datasets.index') {
            $this->lastApiTotal = (int) $response->json('meta.total');
        }

        if ($page === 'admin.users.index') {
            $this->lastPaginatorTotal = (int) $response->viewData('users')->total();
        }

        return count($this->queries);
    }

    private function url(string $page): string
    {
        return in_array($page, ['datasets.show', 'imports.show'], true)
            ? route($page, $this->target)
            : route($page);
    }

    // ------------------------------------------------------------------
    // corpus
    // ------------------------------------------------------------------

    private function seedCorpus(int $datasets, int $threads, int $auditLogs, int $users, int $engineRows): void
    {
        $this->truncateCorpus();

        $this->engineRows = $engineRows;

        // A mix of lifecycle states, because the index pages filter and order
        // on exactly these. A single-status table would not exercise the
        // branches the count is measured against.
        $committed = max(1, (int) round($datasets * 0.6));
        $quarantined = (int) round($datasets * 0.2);
        $importing = (int) round($datasets * 0.2);
        $uploaded = max(0, $datasets - $committed - $quarantined - $importing);

        $committedRows = Dataset::factory()->committed()->count($committed)
            ->create(['user_id' => $this->admin->getKey()]);

        $this->target = $committedRows->first();

        Dataset::factory()->quarantined()->count($quarantined)->create(['user_id' => $this->admin->getKey()]);
        Dataset::factory()->importing()->count($importing)->create(['user_id' => $this->admin->getKey()]);
        Dataset::factory()->count($uploaded)->create(['user_id' => $this->admin->getKey()]);

        // Threads carry messages, because the assistant page reads both and a
        // transcript that never grows would hide a per-message lookup.
        ChatThread::factory()->count($threads)->forUser($this->admin)->create()
            ->each(function (ChatThread $thread): void {
                ChatMessage::factory()->count(3)->forThread($thread)->create();
            });

        $actions = ['dataset.uploaded', 'dataset.committed', 'dataset.deleted', 'user.created', 'user.updated', 'auth.login'];

        foreach (range(1, $auditLogs) as $index) {
            AuditLog::create([
                'user_id' => $this->admin->getKey(),
                'actor' => $this->admin->email,
                'action' => $actions[$index % count($actions)],
                'resource' => 'dataset',
                'resource_id' => $index,
                'ip' => '127.0.0.1',
                'detail' => ['seeded' => true, 'index' => $index],
                'created_at' => now()->subMinutes($index),
            ]);
        }

        User::factory()->count($users)->create();
    }

    private function truncateCorpus(): void
    {
        ChatMessage::query()->delete();
        ChatThread::query()->delete();
        Dataset::query()->delete();
        AuditLog::query()->delete();
        User::query()->where('id', '!=', $this->admin->getKey())->delete();
    }

    // ------------------------------------------------------------------
    // the fake engine
    // ------------------------------------------------------------------

    /**
     * The engine is an HTTP service, so it is faked at the HTTP boundary — the
     * same boundary production uses. List endpoints answer with
     * `$this->engineRows` rows, which is what lets the shape test grow the
     * engine's answer 10x and watch the query count not move.
     */
    private function fakeEngine(): void
    {
        Http::fake(function (ClientRequest $request): Response {
            $this->engineUrls[] = $request->url();

            return Http::response(['success' => true, 'data' => $this->enginePayload($request)], 200);
        });
    }

    /** @return array<mixed> */
    private function enginePayload(ClientRequest $request): array
    {
        $url = $request->url();
        $rows = $this->engineRows;

        // `/health` answers with a bare model, not the envelope.
        if (str_ends_with($url, '/health')) {
            return ['status' => 'ok', 'app' => 'ai-engine', 'env' => 'testing', 'version' => '1.0.0'];
        }

        return match (true) {
            str_ends_with($url, '/analytics/kpi') => [
                'revenue' => 1_250_000_000, 'orders' => 3_140, 'units' => 9_820,
                'aov' => 398_089, 'growth_pct' => 12.4, 'margin_pct' => 31.8,
            ],
            str_ends_with($url, '/analytics/trend') => array_map(
                fn (int $i): array => ['period' => "2026-01-{$i}", 'revenue' => 40_000_000 + $i, 'orders' => 100 + $i, 'units' => 300 + $i],
                range(1, $rows),
            ),
            str_ends_with($url, '/analytics/rfm') => array_map(
                fn (int $i): array => [
                    'customer' => "Pelanggan {$i}", 'recency_days' => 10 + $i, 'frequency' => 4 + $i,
                    'monetary' => 2_500_000 + $i, 'r_score' => 4, 'f_score' => 3, 'm_score' => 5,
                    'segment' => 'loyal',
                ],
                range(1, $rows),
            ),
            str_ends_with($url, '/analytics/abc') => array_map(
                fn (int $i): array => [
                    'product' => "Produk {$i}", 'revenue' => 90_000_000 - $i,
                    'share_pct' => 1.5, 'cumulative_pct' => 10 + $i, 'grade' => 'A',
                ],
                range(1, $rows),
            ),
            str_ends_with($url, '/analytics/cohort') => array_map(
                fn (int $i): array => [
                    'cohort' => '2026-0'.(($i % 9) + 1), 'period_offset' => $i % 4,
                    'retention_pct' => 100 - $i, 'active_customers' => 500 - $i,
                ],
                range(1, $rows),
            ),
            str_ends_with($url, '/analytics/branches') => array_map(
                fn (int $i): array => ['branch' => sprintf('BR-%02d', $i), 'revenue' => 80_000_000 + $i, 'orders' => 900 + $i, 'share_pct' => 8.3],
                range(1, $rows),
            ),
            str_ends_with($url, '/analytics/finance') => [
                'total_revenue' => 1_250_000_000, 'total_cogs' => 850_000_000, 'total_expenses' => 120_000_000,
                'gross_profit' => 400_000_000, 'net_profit' => 280_000_000, 'margin_pct' => 22.4,
            ],
            str_ends_with($url, '/models') => array_map(
                fn (int $i): array => [
                    'id' => $i, 'name' => "model_{$i}", 'model_type' => 'forecast',
                    'status' => 'PRODUCTION', 'production_version_id' => 100 + $i,
                ],
                range(1, $rows),
            ),
            str_ends_with($url, '/ai/report') => [
                'narrative' => 'Penjualan naik konsisten sepanjang periode.',
                'kpi' => ['revenue' => 1_250_000_000, 'orders' => 3_140, 'units' => 9_820, 'aov' => 398_089, 'growth_pct' => 12.4, 'margin_pct' => 31.8],
                'finance' => ['total_revenue' => 1_250_000_000, 'net_profit' => 280_000_000, 'margin_pct' => 22.4],
                'sections' => array_map(
                    fn (int $i): array => ['highlight' => ['text' => "Sorotan {$i}"], 'risk' => ['text' => "Risiko {$i}"]],
                    range(1, max(1, intdiv($rows, 5))),
                ),
            ],
            default => [],
        };
    }

    // ------------------------------------------------------------------
    // failure messages
    // ------------------------------------------------------------------

    /**
     * @param  array<string, int>  $measured
     * @return list<string>
     */
    private function budgetViolations(array $measured): array
    {
        $violations = [];

        foreach ($measured as $page => $count) {
            $budget = self::PAGES[$page];

            if ($count < $budget['min'] || $count > $budget['max']) {
                $violations[] = sprintf(
                    '%-22s %d queries, budget %d-%d. %s',
                    $page,
                    $count,
                    $budget['min'],
                    $budget['max'],
                    $count > $budget['max']
                        ? 'This is above the budget — check for a relation being read inside a loop.'
                        : 'This is below the floor — the page has stopped reading the data it renders.',
                );
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, int>  $measured
     */
    private function report(array $measured): string
    {
        $lines = [];

        foreach ($measured as $page => $count) {
            $budget = self::PAGES[$page];
            $lines[] = sprintf('%-22s %2d  (budget %d-%d)', $page, $count, $budget['min'], $budget['max']);
        }

        return 'Measured against a realistic corpus (20 datasets, 25 chat threads, 100 audit rows, '
            ."20 users, 12 engine rows).\n\n".implode("\n", $lines);
    }

    /**
     * @param  array<string, int>  $left
     * @param  array<string, int>  $right
     */
    private function table(array $left, array $right): string
    {
        $rows = [];

        foreach ($left as $page => $count) {
            $rows[] = sprintf('%-22s %3d -> %3d  %s', $page, $count, $right[$page] ?? -1, $count === ($right[$page] ?? -1) ? 'stable' : 'DRIFT');
        }

        return implode("\n", $rows);
    }

    private function sqlLog(): string
    {
        return "Statements issued:\n".implode("\n", array_map(
            fn (string $sql, int $index): string => sprintf('  %2d. %s', $index + 1, $sql),
            $this->queries,
            array_keys($this->queries),
        ));
    }
}
