<?php

namespace Tests\Feature;

use App\Enums\DatasetStatus;
use App\Http\Controllers\Api\QualityController;
use App\Models\Dataset;
use App\Models\QualityRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Enterprise quality governance: rule CRUD, engine evaluation, read-only GETs.
 *
 * Routes are registered here in setUp (self-contained: `routes/api.php` is
 * master-owned). The engine is faked via Http::fake with the
 * `{"success": true, "data": ...}` envelope the real engine sends.
 */
class QualityEnterpriseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('auth:sanctum')->prefix('api/quality')->group(function (): void {
            Route::get('/rules', [QualityController::class, 'rules']);
            Route::post('/rules', [QualityController::class, 'storeRule']);
            Route::post('/evaluate', [QualityController::class, 'evaluate']);
            Route::get('/history', [QualityController::class, 'history']);
            Route::get('/runs/{id}', [QualityController::class, 'showRun']);
        });

        Http::fake([
            '*/api/v1/quality/evaluate' => Http::response(['success' => true, 'data' => [
                'run_id' => 11,
                'dataset_ref' => null,
                'job_id' => 42,
                'profile' => 'sales_strict',
                'score' => 0.42,
                'column_scores' => ['customer' => 0.5],
                'verdict' => 'fail',
                'results' => [],
            ]], 200),
            '*/api/v1/quality/history*' => Http::response(['success' => true, 'data' => [
                'runs' => [['id' => 11, 'verdict' => 'fail']],
                'trend' => ['direction' => 'stable', 'delta' => 0.0],
            ]], 200),
            '*/api/v1/quality/runs/*' => Http::response(['success' => true, 'data' => [
                'id' => 11, 'verdict' => 'fail', 'findings' => [],
            ]], 200),
            '*/api/v1/*' => Http::response(['success' => true, 'data' => []], 200),
        ]);
    }

    public function test_storing_a_valid_rule_persists_it(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());

        $response = $this->postJson('/api/quality/rules', [
            'name' => 'email_required',
            'dataset_type' => 'customers',
            'column' => 'email',
            'rule_type' => 'required',
            'params' => [],
            'severity' => 'error',
        ]);

        $response->assertCreated()->assertJsonPath('data.name', 'email_required');
        $this->assertDatabaseHas('quality_rules', [
            'name' => 'email_required',
            'rule_type' => 'required',
        ]);
    }

    public function test_storing_a_rule_with_an_unknown_type_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());

        $this->postJson('/api/quality/rules', [
            'name' => 'bad_rule',
            'column' => 'email',
            'rule_type' => 'spellcheck',
        ])->assertUnprocessable()->assertJsonValidationErrors('rule_type');

        $this->assertDatabaseMissing('quality_rules', ['name' => 'bad_rule']);
    }

    public function test_storing_a_rule_with_a_bad_severity_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());

        $this->postJson('/api/quality/rules', [
            'name' => 'bad_severity',
            'column' => 'email',
            'rule_type' => 'required',
            'severity' => 'fatal',
        ])->assertUnprocessable()->assertJsonValidationErrors('severity');
    }

    public function test_listing_rules_reads_without_writing(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());
        QualityRule::create([
            'name' => 'qty_positive', 'dataset_type' => 'sales', 'column' => 'qty',
            'rule_type' => 'validity', 'params' => ['check' => 'no_negative'],
            'severity' => 'error', 'active' => true,
        ]);

        $before = $this->tableCounts();

        $response = $this->getJson('/api/quality/rules?dataset_type=sales');

        $response->assertOk()->assertJsonPath('data.0.name', 'qty_positive');
        $this->assertSame($before, $this->tableCounts(), 'GET /rules must never write.');
    }

    public function test_evaluate_posts_to_the_engine_and_persists_the_score(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());
        $dataset = Dataset::factory()->create([
            'status' => DatasetStatus::Uploaded,
            'import_job_id' => 42,
        ]);

        $response = $this->postJson('/api/quality/evaluate', [
            'dataset_id' => $dataset->uuid,
            'profile' => 'sales_strict',
        ]);

        $response->assertCreated()->assertJsonPath('data.run_id', 11);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/api/v1/quality/evaluate');
        });

        $dataset->refresh();
        $this->assertSame(0.42, $dataset->quality_score);
        $this->assertSame('quarantine', $dataset->quality_verdict);
        $this->assertNotNull($dataset->quality_checked_at);
        $this->assertSame(DatasetStatus::Quarantined, $dataset->status());
    }

    public function test_evaluate_preserves_terminal_statuses(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());
        $dataset = Dataset::factory()->committed()->create();

        $this->postJson('/api/quality/evaluate', [
            'dataset_id' => $dataset->uuid,
            'profile' => 'sales_strict',
        ])->assertCreated();

        $dataset->refresh();
        // The fail verdict is recorded but the committed row stays committed.
        $this->assertSame('quarantine', $dataset->quality_verdict);
        $this->assertSame(DatasetStatus::Committed, $dataset->status());
    }

    public function test_history_and_run_detail_are_read_only(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());
        $dataset = Dataset::factory()->create([
            'status' => DatasetStatus::Uploaded,
            'import_job_id' => 42,
            'quality_score' => 0.9,
            'quality_verdict' => 'pass',
        ]);

        $before = $this->tableCounts();

        $this->getJson('/api/quality/history?dataset_ref='.$dataset->uuid)
            ->assertOk()
            ->assertJsonPath('data.trend.direction', 'stable');

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && str_contains(strtok($request->url(), '?'), '/api/v1/quality/history');
        });

        $this->getJson('/api/quality/runs/11')->assertOk()->assertJsonPath('data.id', 11);

        $this->assertSame($before, $this->tableCounts(), 'GET endpoints must never write.');
        $dataset->refresh();
        $this->assertSame(0.9, $dataset->quality_score);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/quality/rules')->assertUnauthorized();
        $this->postJson('/api/quality/rules', [])->assertUnauthorized();
        $this->postJson('/api/quality/evaluate', [])->assertUnauthorized();
        $this->getJson('/api/quality/history')->assertUnauthorized();
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        return [
            'quality_rules' => QualityRule::count(),
            'datasets' => Dataset::count(),
        ];
    }
}
