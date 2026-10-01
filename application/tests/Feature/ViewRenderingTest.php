<?php

namespace Tests\Feature;

use App\Enums\DatasetStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\ChatThread;
use App\Models\Dataset;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Renders every Blade view with real rows and a real engine payload, and
 * asserts on the response.
 *
 * `WebPagesRenderTest` already proves each page returns 200 against an engine
 * that answers `{success: true, data: []}` — the emptiest payload the contract
 * allows. That leaves almost every branch of every view unexercised: no table
 * row, no badge variant, no money formatting, no disabled button. This file
 * starts from the other end and serves a fully populated engine, so a page
 * that renders 200 with an empty payload but explodes on real data fails here.
 *
 * The second half matters more. A page that only works while the AI engine is up
 * breaks in exactly the incident it is needed for, so the engine-down and
 * partial-payload cases below are load-bearing, not padding.
 */
class ViewRenderingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $datasetName = 'Dataset Uji Mesin AI';

    /**
     * How the engine behaves. Read at request time, never at fake-registration
     * time: `Http::fake()` stubs are matched first-registered-wins, so a second
     * `Http::fake()` in a test could never override the one registered here.
     * Per-test variation therefore has to travel through state, exactly as
     * `DatasetWorkflowTest` does with its quality score.
     */
    protected string $engineMode = 'up';

    /**
     * Per-operation payload overrides, keyed by {@see self::operation()}.
     *
     * @var array<string, mixed>
     */
    protected array $payloads = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->admin = User::factory()->admin()->create();

        $this->fakeEngine();
    }

    /**
     * A single closure stub rather than a URL map, so one registration serves
     * every test and per-test variation goes through `$this->engineMode` /
     * `$this->payloads` instead of a re-fake that could never take effect.
     */
    protected function fakeEngine(): void
    {
        Http::fake(function (ClientRequest $request) {
            if ($this->engineMode === 'connection_failed') {
                throw new ConnectionException(
                    'cURL error 7: Failed to connect to fastapi.test port 80 (Connection refused)'
                );
            }

            if ($this->engineMode === 'http_502') {
                return Http::response(
                    ['success' => false, 'error' => ['message' => 'upstream engine returned a server error (502).']],
                    502
                );
            }

            $key = self::operation($request->url());

            // `/health` answers a bare pydantic model, not the `{success, data}`
            // envelope: `AiEngineClient::decode()` does not unwrap it.
            if ($key === 'health') {
                return Http::response($this->payloadFor('health'), 200);
            }

            return Http::response(['success' => true, 'data' => $this->payloadFor($key)], 200);
        });
    }

    protected static function operation(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = '/'.ltrim(Str::after($path, '/api/v1'), '/');

        return match (true) {
            $path === '/health' => 'health',
            $path === '/analytics/kpi' => 'kpi',
            $path === '/analytics/trend' => 'trend',
            $path === '/analytics/rfm' => 'rfm',
            $path === '/analytics/abc' => 'abc',
            $path === '/analytics/cohort' => 'cohort',
            $path === '/analytics/branches' => 'branches',
            $path === '/analytics/finance' => 'finance',
            $path === '/models' => 'models',
            (bool) preg_match('#^/models/\d+$#', $path) => 'model',
            $path === '/ai/report' => 'report',
            $path === '/ai/chat' => 'chat',
            (bool) preg_match('#^/imports/jobs/\d+$#', $path) => 'importJob',
            default => 'unknown',
        };
    }

    protected function payloadFor(string $key): mixed
    {
        if (array_key_exists($key, $this->payloads)) {
            return $this->payloads[$key];
        }

        return $this->fullPayloads()[$key] ?? [];
    }

    /**
     * A realistic engine: every analytics, ML and report block carries rows, so
     * the table and badge branches of the views actually execute.
     *
     * @return array<string, mixed>
     */
    protected function fullPayloads(): array
    {
        return [
            'health' => [
                'status' => 'ok',
                'app' => 'aidata-engine',
                'env' => 'prod',
                'version' => '1.4.2',
            ],
            'kpi' => [
                'revenue' => 1250000000,
                'orders' => 3412,
                'units' => 18940,
                'aov' => 366354.0,
                'growth_pct' => 12.4,
                'margin_pct' => 31.2,
            ],
            'trend' => [
                ['period' => '2026-09-01', 'revenue' => 402000000, 'orders' => 1104, 'units' => 6210],
                ['period' => '2026-09-02', 'revenue' => 438500000, 'orders' => 1189, 'units' => 6742],
            ],
            'rfm' => [
                [
                    'customer' => 'Toko Berkah Jaya',
                    'recency_days' => 4,
                    'frequency' => 12,
                    'monetary' => 184500000,
                    'r_score' => 5,
                    'f_score' => 5,
                    'm_score' => 4,
                    'segment' => 'champions',
                ],
            ],
            'abc' => [
                ['product' => 'Minyak Goreng 1L', 'revenue' => 318000000, 'share_pct' => 25.4, 'cumulative_pct' => 25.4, 'grade' => 'A'],
                ['product' => 'Beras Premium 5kg', 'revenue' => 96000000, 'share_pct' => 7.7, 'cumulative_pct' => 33.1, 'grade' => 'B'],
                ['product' => 'Gula Pasir 1kg', 'revenue' => 41000000, 'share_pct' => 3.3, 'cumulative_pct' => 36.4, 'grade' => 'C'],
            ],
            'cohort' => [
                ['cohort' => '2026-06', 'period_offset' => 0, 'retention_pct' => 100.0, 'active_customers' => 512],
                ['cohort' => '2026-06', 'period_offset' => 1, 'retention_pct' => 68.2, 'active_customers' => 349],
                ['cohort' => '2026-06', 'period_offset' => 2, 'retention_pct' => 51.4, 'active_customers' => 263],
                ['cohort' => '2026-07', 'period_offset' => 0, 'retention_pct' => 100.0, 'active_customers' => 604],
            ],
            'branches' => [
                ['branch' => 'BR-01', 'revenue' => 402000000, 'orders' => 1104, 'share_pct' => 32.2],
                ['branch' => 'BR-07', 'revenue' => 118000000, 'orders' => 402, 'share_pct' => 9.4],
            ],
            'finance' => [
                'total_revenue' => 1250000000,
                'total_cogs' => 859000000,
                'total_expenses' => 141000000,
                'gross_profit' => 391000000,
                'net_profit' => 250000000,
                'margin_pct' => 31.2,
            ],
            'models' => [
                ['id' => 7, 'name' => 'forecast_penjualan_harian', 'model_type' => 'forecast', 'status' => 'PRODUCTION', 'production_version_id' => 31],
                ['id' => 8, 'name' => 'churn_pelanggan', 'model_type' => 'churn', 'status' => 'TRAINING', 'production_version_id' => null],
            ],
            'model' => [
                'id' => 7,
                'name' => 'forecast_penjualan_harian',
                'model_type' => 'forecast',
                'status' => 'PRODUCTION',
                'versions' => [
                    ['id' => 31, 'version' => 'v3', 'status' => 'PRODUCTION', 'metrics' => ['mape' => 0.042, 'rmse' => 18400.5]],
                    ['id' => 29, 'version' => 'v2', 'status' => 'ARCHIVED', 'metrics' => []],
                ],
            ],
            'report' => [
                'narrative' => 'Penjualan naik 12,4% dibanding periode sebelumnya,镇的 utama dari cabang BR-01.',
                'kpi' => [
                    'revenue' => 1250000000,
                    'orders' => 3412,
                    'units' => 18940,
                    'aov' => 366354.0,
                    'growth_pct' => 12.4,
                    'margin_pct' => 31.2,
                ],
                'finance' => [
                    'total_revenue' => 1250000000,
                    'net_profit' => 250000000,
                    'margin_pct' => 31.2,
                ],
                'sections' => [
                    'Sorotan' => ['Kenaikan pendapatan broadest di BR-01', 'Margin kotor stabil di 31,2%'],
                    'Risiko' => ['Stok BR-07 turun di bawah minimum'],
                ],
            ],
            'importJob' => [
                'id' => 42,
                'status' => 'succeeded',
                'progress' => 100.0,
                'total_rows' => 18420,
                'processed_rows' => 18420,
                'error_rows' => 0,
                'report' => [
                    'warnings' => [],
                    'elapsed_seconds' => 12.4,
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // one test per named route, with the engine answering in full
    // ------------------------------------------------------------------

    public function test_the_dashboard_renders_the_platform_overview(): void
    {
        Dataset::factory()->committed()->create(['name' => 'Penjualan Retail 2026']);
        ChatThread::factory()->forUser($this->admin)->titled('Analisis penjualan', 4)->create();

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewIs('dashboard')
            ->assertSee('Sehat')
            ->assertSee('1.4.2')
            ->assertSee('aidata-engine')
            ->assertSee('Dataset terbaru')
            ->assertSee('Aksi cepat')
            ->assertSee('Penjualan Retail 2026')
            ->assertSee('Analisis penjualan');
    }

    public function test_the_dataset_index_renders_every_status_badge(): void
    {
        Dataset::factory()->committed()->create(['name' => 'Dataset terkomit']);
        Dataset::factory()->quarantined()->create(['name' => 'Dataset dikarantina']);
        Dataset::factory()->importing()->create(['name' => 'Dataset diimpor']);

        $this->actingAs($this->admin)
            ->get(route('datasets.index'))
            ->assertOk()
            ->assertViewIs('datasets.index')
            ->assertSee('Dataset terkomit')
            ->assertSee('Dataset dikarantina')
            ->assertSee('Dataset diimpor')
            ->assertSee('Lolos')
            ->assertSee('Dikarantina');
    }

    public function test_the_dataset_create_page_renders_the_upload_form(): void
    {
        $this->actingAs($this->admin)
            ->get(route('datasets.create'))
            ->assertOk()
            ->assertViewIs('datasets.create')
            ->assertSee('Unggah dataset')
            ->assertSee('name="file"', escape: false)
            ->assertSee('name="dataset_type"', escape: false);
    }

    public function test_the_dataset_show_page_renders_the_full_wizard_for_a_committed_dataset(): void
    {
        $dataset = Dataset::factory()->committed()->create(['name' => 'Penjualan Retail 2026']);

        $this->actingAs($this->admin)
            ->get(route('datasets.show', $dataset))
            ->assertOk()
            ->assertViewIs('datasets.show')
            ->assertSee('Penjualan Retail 2026')
            ->assertSee(DatasetStatus::Committed->localizedLabel())
            ->assertSee('Komit terakhir')
            ->assertSee('Lolos')
            ->assertSee('18.420')
            ->assertSee('transaction_date');
    }

    public function test_the_import_index_renders(): void
    {
        Dataset::factory()->importing()->create(['name' => 'Impor Berjalan']);

        $this->actingAs($this->admin)
            ->get(route('imports.index'))
            ->assertOk()
            ->assertViewIs('imports.index')
            ->assertSee('Impor Berjalan');
    }

    public function test_the_import_show_page_renders_a_refreshed_engine_job_report(): void
    {
        $dataset = Dataset::factory()->importing()->create(['name' => 'Impor Berjalan']);

        $this->actingAs($this->admin)
            ->get(route('imports.show', ['dataset' => $dataset, 'refresh' => 1]))
            ->assertOk()
            ->assertViewIs('imports.show')
            ->assertSee('Impor Berjalan')
            ->assertSee('Selesai')
            ->assertSee('18.420')
            ->assertSee('Elapsed Seconds');

        // `syncStatus` mirrors the engine's verdict onto the local row.
        $this->assertSame('committed', $dataset->fresh()->status->value);
    }

    public function test_the_quality_index_renders_scores_verdicts_and_the_threshold(): void
    {
        Dataset::factory()->committed()->create(['name' => 'Lolos Mutu']);
        Dataset::factory()->quarantined()->create(['name' => 'Jatuh Mutu']);

        $this->actingAs($this->admin)
            ->get(route('quality.index'))
            ->assertOk()
            ->assertViewIs('quality.index')
            ->assertSee('Lolos Mutu')
            ->assertSee('Jatuh Mutu')
            ->assertSee('Di atas ambang')
            ->assertSee('Di bawah ambang')
            ->assertSee('75,0%');
    }

    public function test_the_analytics_index_renders_every_block_with_rows(): void
    {
        $response = $this->actingAs($this->admin)->get(route('analytics.index'))->assertOk()->assertViewIs('analytics.index');

        $response
            ->assertSee('2026-09-01')
            ->assertSee('BR-01')
            ->assertSee('Toko Berkah Jaya')
            ->assertSee('champions')
            ->assertSee('Minyak Goreng 1L')
            ->assertSee('2026-06')
            // The figure, not the label: the finance `<dl>` is a config-driven
            // label/value loop and every label is localisation churn.
            ->assertSee('391.000.000');

        // Grade A is the one classification styled as a success, and the word
        // next to it is localised, so assert the pairing rather than the copy:
        // a copy change should not be able to unhook the colour.
        $this->assertSame('badge-success', $this->rowBadge($response->getContent(), 'Minyak Goreng 1L')['variant']);
    }

    public function test_the_ml_index_renders_a_selected_model_with_its_versions(): void
    {
        $this->actingAs($this->admin)
            ->get(route('ml.index', ['model' => 7]))
            ->assertOk()
            ->assertViewIs('ml.index')
            ->assertSee('forecast_penjualan_harian')
            ->assertSee('churn_pelanggan')
            ->assertSee('v3')
            ->assertSee('v2')
            ->assertSee('Produksi')
            ->assertSee('Diarsipkan')
            ->assertSee('Tidak ada metrik')
            ->assertSee('Promosikan');
    }

    public function test_the_assistant_index_renders_a_thread_with_messages_and_evidence(): void
    {
        $thread = ChatThread::factory()->forUser($this->admin)->titled('Analisis penjualan', 2)->create();

        $thread->messages()->create([
            'role' => 'user',
            'content' => 'Cabang mana yang paling turun?',
        ]);

        $thread->messages()->create([
            'role' => 'assistant',
            'content' => 'BR-07 turun 9,4% dibanding periode sebelumnya.',
            'evidence' => [
                ['source' => 'sql.branches', 'data' => ['branch' => 'BR-07', 'revenue' => 118000000]],
            ],
            'steps' => 2,
        ]);

        $this->actingAs($this->admin)
            ->get(route('assistant.index', ['thread' => $thread->getKey()]))
            ->assertOk()
            ->assertViewIs('assistant.index')
            ->assertSee('Analisis penjualan')
            ->assertSee('Cabang mana yang paling turun?')
            ->assertSee('BR-07 turun')
            ->assertSee('sql.branches')
            ->assertSee('Bukti')
            ->assertSee('2 langkah');
    }

    public function test_the_reports_index_renders_the_narrative_kpi_and_sections(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.index', ['period' => 'weekly']))
            ->assertOk()
            ->assertViewIs('reports.index')
            ->assertSee('Laporan eksekutif')
            ->assertSee('utama dari cabang BR-01')
            ->assertSee('Sorotan')
            ->assertSee('Risiko')
            ->assertSee('Stok BR-07 turun di bawah minimum');
    }

    public function test_the_admin_user_index_renders_every_account_and_role(): void
    {
        User::factory()->analyst()->create(['name' => 'Analis Satu', 'email' => 'analis1@example.com']);
        User::factory()->viewer()->inactive()->create(['name' => 'Pengunjung Dua', 'email' => 'pengunjung2@example.com']);

        $this->actingAs($this->admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertViewIs('admin.users.index')
            ->assertSee('Analis Satu')
            ->assertSee('Pengunjung Dua')
            ->assertSee('Aktif')
            ->assertSee('Nonaktif')
            ->assertSee(UserRole::Admin->localizedLabel())
            ->assertSee(UserRole::Analyst->localizedLabel())
            ->assertSee(UserRole::Viewer->localizedLabel())
            ->assertSee(UserRole::Viewer->description());
    }

    public function test_the_audit_index_renders_logged_actions(): void
    {
        AuditLog::record('auth.login', 'user', 1, ['remember' => false], $this->admin);
        AuditLog::record('dataset.committed', 'dataset', 7, ['status' => 'queued'], $this->admin);

        $this->actingAs($this->admin)
            ->get(route('audit.index'))
            ->assertOk()
            ->assertViewIs('audit.index')
            ->assertSee('auth.login')
            ->assertSee('dataset.committed')
            ->assertSee($this->admin->email)
            ->assertSee('Lihat rincian');
    }

    public function test_the_password_page_renders_for_a_logged_in_user(): void
    {
        $this->actingAs($this->admin)
            ->get(route('password.edit'))
            ->assertOk()
            ->assertViewIs('profile.password')
            ->assertSee('Ubah password')
            ->assertSee($this->admin->email)
            ->assertSee(UserRole::Admin->localizedLabel());
    }

    public function test_the_login_page_renders_for_a_guest(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertViewIs('auth.login')
            ->assertSee('Masuk ke platform')
            ->assertSee('name="email"', escape: false)
            ->assertSee('name="password"', escape: false)
            ->assertSee('name="_token"', escape: false);
    }

    public function test_the_engine_error_view_renders_when_a_page_lets_the_failure_through(): void
    {
        $dataset = Dataset::factory()->importing()->create();
        $this->engineMode = 'http_502';

        $this->actingAs($this->admin)
            ->get(route('imports.show', ['dataset' => $dataset, 'refresh' => 1]))
            ->assertStatus(502)
            ->assertViewIs('errors.engine')
            ->assertSee('Mesin AI tidak dapat dihubungi')
            ->assertSee('imports.jobs');
    }

    public function test_the_inactive_account_view_renders_for_a_deactivated_user(): void
    {
        $inactive = User::factory()->admin()->inactive()->create();

        $this->actingAs($inactive)
            ->get(route('dashboard'))
            ->assertStatus(403)
            ->assertViewIs('errors.inactive')
            ->assertSee('Akun dinonaktifkan')
            ->assertSee('Kembali ke halaman masuk');
    }

    // ------------------------------------------------------------------
    // the engine is down
    // ------------------------------------------------------------------

    /**
     * Every page that answers a plain GET, with the evidence that proves it
     * degraded rather than merely returning 200.
     *
     * `datasets.*`, `imports.*` and `quality.*` read the local mirror only, so
     * their evidence is the dataset still on the page — a local outage must not
     * be able to blank a page that never needed the engine. The rest read the
     * engine and must show an empty state. `imports.show` is listed without
     * `?refresh=1` on purpose: a user who did not ask to re-poll the engine
     * must not be handed an error page.
     *
     * @return array<string, list<string>>
     */
    public static function engineBackedPages(): array
    {
        return [
            'dashboard' => ['dashboard', ['Mesin AI', 'Tidak terjangkau']],
            'datasets.index' => ['datasets.index', ['@dataset']],
            'datasets.show' => ['datasets.show', ['@dataset']],
            'imports.index' => ['imports.index', ['@dataset']],
            'imports.show' => ['imports.show', ['@dataset']],
            'quality.index' => ['quality.index', ['@dataset']],
            'analytics.index' => ['analytics.index', ['Mesin AI tidak tersedia', 'Belum ada data tren', 'Belum ada data cabang', 'Belum ada data RFM']],
            'ml.index' => ['ml.index', ['Belum ada model', 'Belum ada versi']],
            'reports.index' => ['reports.index', ['Mesin AI tidak tersedia', 'Laporan belum tersedia']],
        ];
    }

    #[DataProvider('engineBackedPages')]
    public function test_every_page_degrades_to_an_empty_state_when_the_engine_answers_502(string $page, array $evidence): void
    {
        $this->engineMode = 'http_502';

        $this->assertDegraded($this->visitPage($page), $evidence);
    }

    #[DataProvider('engineBackedPages')]
    public function test_every_page_degrades_to_an_empty_state_when_the_connection_fails(string $page, array $evidence): void
    {
        $this->engineMode = 'connection_failed';

        $this->assertDegraded($this->visitPage($page), $evidence);
    }

    /**
     * @param  list<string>  $evidence
     */
    protected function assertDegraded(TestResponse $response, array $evidence): void
    {
        // A 500 here is the whole point: these pages exist for the incident in
        // which the engine is unreachable, and a stack trace is not a fallback.
        $response->assertOk();

        foreach ($evidence as $marker) {
            $response->assertSee($marker === '@dataset' ? $this->datasetName : $marker);
        }
    }

    protected function visitPage(string $page): TestResponse
    {
        // Committed so the row is visible on every one of these pages: it is
        // scored (quality.index) and it has an import job (imports.*).
        $dataset = Dataset::factory()->committed()->create(['name' => $this->datasetName]);

        return match ($page) {
            'dashboard' => $this->actingAs($this->admin)->get(route('dashboard')),
            'datasets.index' => $this->actingAs($this->admin)->get(route('datasets.index')),
            'datasets.show' => $this->actingAs($this->admin)->get(route('datasets.show', $dataset)),
            'imports.index' => $this->actingAs($this->admin)->get(route('imports.index')),
            'imports.show' => $this->actingAs($this->admin)->get(route('imports.show', $dataset)),
            'quality.index' => $this->actingAs($this->admin)->get(route('quality.index')),
            'analytics.index' => $this->actingAs($this->admin)->get(route('analytics.index')),
            'ml.index' => $this->actingAs($this->admin)->get(route('ml.index')),
            'reports.index' => $this->actingAs($this->admin)->get(route('reports.index')),
            default => throw new InvalidArgumentException("Unknown page [{$page}]."),
        };
    }

    // ------------------------------------------------------------------
    // partial and null engine payloads
    // ------------------------------------------------------------------

    public function test_analytics_renders_a_kpi_block_with_missing_keys_and_no_trend(): void
    {
        // `trend` comes back empty and `kpi` is missing four of its six keys:
        // the shape an engine mid-deploy or a half-migrated schema sends.
        $this->payloads = [
            'kpi' => ['revenue' => 1250000000, 'orders' => 3412],
            'trend' => [],
            'rfm' => [],
            'abc' => [],
            'cohort' => [],
            'branches' => [],
            'finance' => [],
        ];

        $this->actingAs($this->admin)
            ->get(route('analytics.index'))
            ->assertOk()
            ->assertViewIs('analytics.index')
            ->assertSee('Belum ada data tren')
            ->assertSee('Belum ada data cabang')
            ->assertSee('Belum ada data keuangan')
            ->assertSee('Belum ada data RFM')
            ->assertSee('Belum ada klasifikasi ABC')
            ->assertSee('Belum ada data cohort')
            ->assertSee('Pertumbuhan')
            ->assertSee('3.412');
    }

    public function test_ml_renders_an_empty_model_registry(): void
    {
        $this->payloads = ['models' => [], 'model' => []];

        $this->actingAs($this->admin)
            ->get(route('ml.index'))
            ->assertOk()
            ->assertViewIs('ml.index')
            ->assertSee('Belum ada model')
            ->assertSee('Belum ada versi');
    }

    public function test_reports_renders_a_report_that_has_only_a_narrative(): void
    {
        $this->payloads = ['report' => ['narrative' => 'Belum ada angka untuk periode ini.']];

        $this->actingAs($this->admin)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertViewIs('reports.index')
            ->assertSee('Belum ada angka untuk periode ini.')
            ->assertSee('Data keuangan kosong')
            ->assertSee('Pendapatan');
    }

    public function test_assistant_renders_a_thread_with_no_messages(): void
    {
        $thread = ChatThread::factory()
            ->forUser($this->admin)
            ->titled('Percakapan masih kosong', 0)
            ->create();

        $this->actingAs($this->admin)
            ->get(route('assistant.index', ['thread' => $thread->getKey()]))
            ->assertOk()
            ->assertViewIs('assistant.index')
            ->assertSee('Belum ada pesan')
            ->assertSee('Percakapan masih kosong')
            ->assertSee('0 pesan');
    }

    public function test_a_dataset_with_no_profiled_columns_still_renders_the_wizard(): void
    {
        $dataset = Dataset::factory()->create([
            'name' => 'Berkas Belum Diprofil',
            'import_job_id' => null,
            'row_count' => 0,
            'column_count' => 0,
            'columns' => [],
            'mappings' => [],
            'metadata' => [],
        ]);

        $this->actingAs($this->admin)
            ->get(route('datasets.show', $dataset))
            ->assertOk()
            ->assertViewIs('datasets.show')
            ->assertSee('Berkas Belum Diprofil')
            ->assertSee('Profil kolom belum tersedia')
            ->assertSee('Contoh data belum tersedia')
            ->assertSee('Belum ada laporan kualitas');
    }

    // ------------------------------------------------------------------
    // the dataset wizard, one assertion set per state
    // ------------------------------------------------------------------

    public function test_the_wizard_disables_every_action_when_the_dataset_has_no_import_job(): void
    {
        $dataset = Dataset::factory()->create([
            'import_job_id' => null,
            'row_count' => 0,
            'column_count' => 0,
            'columns' => [],
            'mappings' => [],
            'metadata' => [],
        ]);

        $html = $this->renderWizard($dataset);

        $this->assertSame(['present' => true, 'disabled' => true], $this->button($html, $dataset, 'preview'));
        $this->assertSame(['present' => true, 'disabled' => true], $this->button($html, $dataset, 'quality'));
        $this->assertSame(['present' => true, 'disabled' => true], $this->button($html, $dataset, 'commit'));

        // With no column profile there is nothing to map, so the mapping form is
        // replaced by an empty state rather than rendered with no rows in it.
        $this->assertSame(['present' => false, 'disabled' => null], $this->button($html, $dataset, 'mapping'));
        $this->assertStringContainsString('Profil kolom belum tersedia', $html);
        $this->assertStringContainsString('dataset belum memiliki job import', $html);
    }

    public function test_the_wizard_offers_mapping_once_the_dataset_is_profiled(): void
    {
        $dataset = Dataset::factory()->create([
            'import_job_id' => 42,
            'columns' => [
                ['name' => 'tanggal', 'dtype' => 'date', 'missing' => 0, 'unique' => 273],
                ['name' => 'qty', 'dtype' => 'integer', 'missing' => 3, 'unique' => 41],
            ],
            'mappings' => [],
        ]);

        $html = $this->renderWizard($dataset);

        $this->assertSame(['present' => true, 'disabled' => false], $this->button($html, $dataset, 'preview'));
        $this->assertSame(['present' => true, 'disabled' => false], $this->button($html, $dataset, 'mapping'));

        // Quality and commit both wait on the mapping that has not been saved.
        $this->assertSame(['present' => true, 'disabled' => true], $this->button($html, $dataset, 'quality'));
        $this->assertSame(['present' => true, 'disabled' => true], $this->button($html, $dataset, 'commit'));
        $this->assertStringContainsString('pemetaan kolom belum disimpan', $html);

        $this->assertStringContainsString('name="mappings[tanggal]"', $html);
        $this->assertStringContainsString('name="mappings[qty]"', $html);
    }

    public function test_the_wizard_offers_quality_once_the_columns_are_mapped(): void
    {
        $dataset = Dataset::factory()->create([
            'import_job_id' => 42,
            'mappings' => ['tanggal' => 'transaction_date', 'qty' => 'quantity'],
        ]);

        $html = $this->renderWizard($dataset);

        $this->assertSame(['present' => true, 'disabled' => false], $this->button($html, $dataset, 'quality'));

        // Commit still waits on a quality verdict that has never been run.
        $this->assertSame(['present' => true, 'disabled' => true], $this->button($html, $dataset, 'commit'));
        $this->assertStringContainsString('kualitas belum mencapai ambang minimum', $html);
    }

    public function test_the_wizard_blocks_commit_when_the_dataset_failed_quality(): void
    {
        $dataset = Dataset::factory()->quarantined()->create();

        $html = $this->renderWizard($dataset);

        $this->assertStringContainsString(DatasetStatus::Quarantined->localizedLabel(), $html);
        $this->assertStringContainsString('Belum lolos', $html);

        // A failed check can be re-run, but it can never be committed.
        $this->assertSame(['present' => true, 'disabled' => false], $this->button($html, $dataset, 'quality'));
        $this->assertSame(['present' => true, 'disabled' => true], $this->button($html, $dataset, 'commit'));
    }

    public function test_the_wizard_blocks_the_commit_action_on_an_already_committed_dataset(): void
    {
        $dataset = Dataset::factory()->committed()->create();

        $html = $this->renderWizard($dataset);

        $this->assertStringContainsString(DatasetStatus::Committed->localizedLabel(), $html);
        $this->assertStringContainsString('Komit terakhir', $html);

        $this->assertSame(['present' => true, 'disabled' => true], $this->button($html, $dataset, 'commit'));
        $this->assertStringContainsString('dataset sudah dikomit', $html);
    }

    // ------------------------------------------------------------------
    // markup helpers
    // ------------------------------------------------------------------

    protected function renderWizard(Dataset $dataset): string
    {
        return $this->actingAs($this->admin)
            ->get(route('datasets.show', $dataset))
            ->assertOk()
            ->getContent();
    }

    /**
     * The wizard's four action forms, located by their route rather than by
     * their position in the page, and identified by the one submit control each
     * of them owns. Matching on the button's label instead would make every
     * copy change ("Commit dataset" / "Komit dataset") read as a missing
     * action, which is churn, not a regression.
     *
     * @return array{present: bool, disabled: bool|null}
     */
    protected function button(string $html, Dataset $dataset, string $action): array
    {
        $route = match ($action) {
            'preview' => 'datasets.preview',
            'mapping' => 'datasets.mapping',
            'quality' => 'datasets.quality',
            'commit' => 'datasets.commit',
            default => throw new InvalidArgumentException("Unknown wizard action [{$action}]."),
        };

        $xpath = $this->xpath($html);

        foreach ($xpath->query('//form') as $form) {
            if (! $form instanceof DOMElement || $form->getAttribute('action') !== route($route, $dataset)) {
                continue;
            }

            foreach ($xpath->query('.//button[@type="submit"]', $form) as $button) {
                if ($button instanceof DOMElement) {
                    return ['present' => true, 'disabled' => $button->hasAttribute('disabled')];
                }
            }
        }

        return ['present' => false, 'disabled' => null];
    }

    protected function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($document);
    }

    /**
     * The first `.badge` inside the table row that mentions this text, as a
     * class list. Lets a test pin a colour without pinning the wording next to
     * it, which is otherwise a copy edit away from a false failure.
     *
     * @return array{classes: string, variant: string, text: string}
     */
    protected function rowBadge(string $html, string $rowText): array
    {
        $xpath = $this->xpath($html);

        foreach ($xpath->query('//tr') as $row) {
            if (! $row instanceof DOMElement || ! Str::contains($row->textContent, $rowText)) {
                continue;
            }

            foreach ($xpath->query('.//span[contains(@class, "badge")]', $row) as $badge) {
                if (! $badge instanceof DOMElement) {
                    continue;
                }

                $classes = trim($badge->getAttribute('class'));
                $variant = '';

                foreach (explode(' ', $classes) as $class) {
                    if (Str::startsWith($class, 'badge-')) {
                        $variant = $class;
                    }
                }

                return ['classes' => $classes, 'variant' => $variant, 'text' => trim($badge->textContent)];
            }
        }

        $this->fail("No row mentioning [{$rowText}] carries a badge.");
    }
}
