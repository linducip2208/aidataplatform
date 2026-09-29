<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\MlController as ApiMlController;
use App\Http\Controllers\MlController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Enterprise ML surface: experiments, rollback, audit events and batch
 * prediction, on both the JSON API and the Blade UI.
 *
 * The new routes are registered here rather than in `routes/*.php` because
 * route includes are wired by master at integration; these mirrors pin the
 * intended URIs, verbs and role gates. `Http::fake()` stands in for the
 * FastAPI engine with the exact envelope it answers.
 */
class MlEnterpriseTest extends TestCase
{
    use RefreshDatabase;

    protected User $analyst;

    protected User $admin;

    /** Read at request time: stub callbacks are first-registered-wins. */
    protected bool $engineDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyst = User::factory()->analyst()->create();
        $this->admin = User::factory()->admin()->create();

        $this->registerEnterpriseRoutes();
        $this->fakeEngine();
    }

    /**
     * Mirrors of the intended `/api/ml/*` + `/ml*` wiring for the enterprise
     * endpoints. Role gates match the existing convention: reads for any
     * authenticated user, training-shaped writes for admin/analyst,
     * production decisions for admin only.
     */
    protected function registerEnterpriseRoutes(): void
    {
        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('/api/ml/experiments', [ApiMlController::class, 'experiments']);
            Route::post('/api/ml/experiments', [ApiMlController::class, 'createExperiment'])
                ->middleware('role:admin,analyst');
            Route::post('/api/ml/experiments/{experimentId}/compare', [ApiMlController::class, 'compareExperiment']);
            Route::post('/api/ml/experiments/{experimentId}/promote', [ApiMlController::class, 'promoteExperiment'])
                ->middleware('role:admin');
            Route::post('/api/ml/models/{modelId}/rollback', [ApiMlController::class, 'rollback'])
                ->middleware('role:admin');
            Route::get('/api/ml/models/{modelId}/events', [ApiMlController::class, 'events']);
            Route::get('/api/ml/models/{modelId}/detail', [ApiMlController::class, 'detail']);
            Route::post('/api/ml/batch-predict', [ApiMlController::class, 'batchPredict'])
                ->middleware('role:admin,analyst');
        });

        Route::middleware('auth')->group(function (): void {
            Route::post('/ml/experiments', [MlController::class, 'createExperiment'])
                ->middleware('role:admin,analyst');
            Route::post('/ml/experiments/{experimentId}/promote', [MlController::class, 'promoteExperiment'])
                ->middleware('role:admin');
            Route::post('/ml/{modelId}/rollback', [MlController::class, 'rollback'])
                ->middleware('role:admin');
            Route::post('/ml/batch-predict', [MlController::class, 'batchPredict'])
                ->middleware('role:admin,analyst');
        });
    }

    protected function fakeEngine(): void
    {
        Http::fake(function (ClientRequest $request) {
            if ($this->engineDown) {
                throw new ConnectionException('connection refused');
            }

            $url = $request->url();

            // Creating an experiment answers one experiment, listing answers
            // the collection; the URL is identical so the method decides.
            if (str_ends_with($url, '/training/experiments') && $request->method() === 'POST') {
                return Http::response(['success' => true, 'data' => $this->createdExperiment()], 200);
            }

            $payload = $this->enginePayload($url);

            return Http::response(['success' => true, 'data' => $payload], 200);
        });
    }

    /** @return array<string, mixed> */
    protected function enginePayload(string $url): array
    {
        return match (true) {
            str_ends_with($url, '/experiments/11/compare') => $this->comparisonResult(),
            str_ends_with($url, '/experiments/11/promote') => $this->experimentPromotionResult(),
            str_ends_with($url, '/models/7/rollback') => $this->rollbackResult(),
            str_ends_with($url, '/models/7/events') => $this->eventsTrail(),
            str_ends_with($url, '/models/7/detail') => $this->modelDetail(),
            str_ends_with($url, '/training/experiments') => $this->experimentList(),
            str_ends_with($url, '/training/batch-predict') => $this->batchResult(),
            (bool) preg_match('#/api/v1/models/\d+$#', $url) => $this->modelDetail(),
            str_ends_with($url, '/api/v1/models') => $this->modelList(),
            default => [],
        };
    }

    /** @return array<string, mixed> */
    protected function createdExperiment(): array
    {
        return [
            'id' => 11,
            'name' => 'churn_q1_baseline',
            'model_type' => 'churn',
            'status' => 'DONE',
            'split_config' => [
                'strategy' => 'stratified',
                'sizes' => ['train' => 14, 'validate' => 3, 'test' => 3],
            ],
            'metrics' => ['validate' => ['f1' => 0.83]],
            'model_id' => 7,
            'version_id' => 3,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    protected function experimentList(): array
    {
        return [$this->createdExperiment()];
    }

    /** @return array<string, mixed> */
    protected function comparisonResult(): array
    {
        return [
            'metric' => 'f1',
            'split' => 'validate',
            'higher_is_better' => true,
            'ranking' => [
                ['experiment_id' => 11, 'value' => 0.83, 'rank' => 1],
                ['experiment_id' => 12, 'value' => 0.71, 'rank' => 2],
            ],
            'best_experiment_id' => 11,
        ];
    }

    /** @return array<string, mixed> */
    protected function experimentPromotionResult(): array
    {
        return ['experiment_id' => 11, 'model_id' => 7, 'version_id' => 3, 'status' => 'PRODUCTION'];
    }

    /** @return array<string, mixed> */
    protected function rollbackResult(): array
    {
        return ['model_id' => 7, 'rolled_back_from' => 4, 'rolled_back_to' => 3, 'status' => 'PRODUCTION'];
    }

    /** @return array<string, mixed> */
    protected function eventsTrail(): array
    {
        return [
            'model_id' => 7,
            'model_name' => 'churn_pelanggan',
            'events' => [
                ['id' => 1, 'event_type' => 'lifecycle', 'version_id' => 3,
                    'from_status' => 'VALIDATED', 'to_status' => 'PRODUCTION',
                    'actor' => 'admin@example.com', 'note' => '', 'created_at' => '2026-09-29T00:00:00Z'],
                ['id' => 2, 'event_type' => 'rollback', 'version_id' => 3,
                    'from_status' => '4', 'to_status' => '3',
                    'actor' => 'admin@example.com', 'note' => 'bad deploy', 'created_at' => '2026-09-29T01:00:00Z'],
            ],
            'deployment_status' => ['3' => 'SERVING', '4' => 'RETIRED'],
        ];
    }

    /** @return array<string, mixed> */
    protected function modelDetail(): array
    {
        return [
            'id' => 7,
            'name' => 'churn_pelanggan',
            'model_type' => 'churn',
            'status' => 'PRODUCTION',
            'production_version_id' => 3,
            'versions' => [
                [
                    'id' => 3,
                    'version' => 'v3',
                    'status' => 'PRODUCTION',
                    'training_timestamp' => '2026-09-29T00:00:00Z',
                    'dataset_version' => 'v2024-01',
                    'features' => ['recency', 'frequency'],
                    'metrics' => ['f1' => 0.83],
                    'params' => ['seed' => 42],
                    'artifact_path' => '/data/models/model_7_v3.joblib',
                    'deployment_status' => 'SERVING',
                ],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    protected function modelList(): array
    {
        return [
            [
                'id' => 7,
                'name' => 'churn_pelanggan',
                'model_type' => 'churn',
                'status' => 'PRODUCTION',
                'production_version_id' => 3,
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function batchResult(): array
    {
        return [
            'run_id' => 9,
            'model_type' => 'churn',
            'model_id' => 7,
            'version_id' => 3,
            'n_rows' => 6,
            'n_chunks' => 2,
            'chunk_size' => 3,
            'summary' => ['n_rows' => 6, 'n_scored' => 6, 'n_errors' => 0],
            'artifact_path' => '/data/models/batch_9.json',
        ];
    }

    // ------------------------------------------------------------------
    // api: experiments
    // ------------------------------------------------------------------

    public function test_the_api_experiments_endpoint_lists_the_engine_experiments(): void
    {
        Sanctum::actingAs($this->analyst);

        $this->getJson('/api/ml/experiments')
            ->assertOk()
            ->assertJsonPath('data.0.id', 11)
            ->assertJsonPath('data.0.split_config.strategy', 'stratified');
    }

    public function test_the_api_create_experiment_endpoint_answers_202_and_audits(): void
    {
        Sanctum::actingAs($this->analyst);

        $this->postJson('/api/ml/experiments', [
            'model_type' => 'churn',
            'name' => 'churn_q1_baseline',
        ])
            ->assertStatus(202)
            ->assertJsonPath('data.id', 11)
            ->assertJsonPath('data.status', 'DONE');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'model.experiment_created',
            'resource' => 'experiment',
            'user_id' => $this->analyst->getKey(),
        ]);
    }

    public function test_the_api_create_experiment_endpoint_is_forbidden_for_a_viewer(): void
    {
        Sanctum::actingAs(User::factory()->viewer()->create());

        $this->postJson('/api/ml/experiments', ['model_type' => 'churn'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_the_api_compare_endpoint_returns_the_engine_ranking(): void
    {
        Sanctum::actingAs($this->analyst);

        $this->postJson('/api/ml/experiments/11/compare', ['experiment_ids' => [11, 12]])
            ->assertOk()
            ->assertJsonPath('data.best_experiment_id', 11)
            ->assertJsonPath('data.ranking.0.rank', 1);
    }

    public function test_the_api_promote_experiment_endpoint_is_admin_only_and_audits(): void
    {
        Sanctum::actingAs($this->analyst);

        $this->postJson('/api/ml/experiments/11/promote', [])
            ->assertForbidden();

        Sanctum::actingAs($this->admin);

        $this->postJson('/api/ml/experiments/11/promote', [])
            ->assertOk()
            ->assertJsonPath('data.status', 'PRODUCTION');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'model.experiment_promoted',
            'resource' => 'experiment',
            'resource_id' => 11,
            'user_id' => $this->admin->getKey(),
        ]);
    }

    public function test_the_api_experiment_routes_reject_a_non_numeric_id(): void
    {
        Sanctum::actingAs($this->analyst);

        $this->postJson('/api/ml/experiments/abc/compare', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // api: rollback / events / detail
    // ------------------------------------------------------------------

    public function test_the_api_rollback_endpoint_restores_the_predecessor(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/ml/models/7/rollback', ['note' => 'bad deploy'])
            ->assertOk()
            ->assertJsonPath('data.rolled_back_to', 3)
            ->assertJsonPath('data.status', 'PRODUCTION');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'model.rolled_back',
            'resource' => 'model',
            'resource_id' => 7,
            'user_id' => $this->admin->getKey(),
        ]);

        Http::assertSent(fn (ClientRequest $r): bool => str_ends_with($r->url(), '/api/v1/models/7/rollback')
            && $r->data() === ['actor' => '', 'note' => 'bad deploy']);
    }

    public function test_the_api_rollback_endpoint_is_forbidden_for_an_analyst_and_a_viewer(): void
    {
        Sanctum::actingAs($this->analyst);

        $this->postJson('/api/ml/models/7/rollback', [])
            ->assertForbidden();

        Sanctum::actingAs(User::factory()->viewer()->create());

        $this->postJson('/api/ml/models/7/rollback', [])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_the_api_events_endpoint_returns_the_audit_trail(): void
    {
        Sanctum::actingAs($this->analyst);

        $this->getJson('/api/ml/models/7/events')
            ->assertOk()
            ->assertJsonPath('data.model_id', 7)
            ->assertJsonPath('data.events.1.event_type', 'rollback')
            ->assertJsonPath('data.deployment_status.3', 'SERVING');
    }

    public function test_the_api_detail_endpoint_returns_complete_metadata(): void
    {
        Sanctum::actingAs($this->analyst);

        $this->getJson('/api/ml/models/7/detail')
            ->assertOk()
            ->assertJsonPath('data.versions.0.training_timestamp', '2026-09-29T00:00:00Z')
            ->assertJsonPath('data.versions.0.dataset_version', 'v2024-01')
            ->assertJsonPath('data.versions.0.deployment_status', 'SERVING');
    }

    // ------------------------------------------------------------------
    // api: batch prediction
    // ------------------------------------------------------------------

    public function test_the_api_batch_predict_endpoint_answers_202_and_audits(): void
    {
        Sanctum::actingAs($this->analyst);

        $this->postJson('/api/ml/batch-predict', [
            'model_type' => 'churn',
            'model_name' => 'churn_pelanggan',
            'dataset' => [['recency' => 12], ['recency' => 120]],
            'chunk_size' => 3,
        ])
            ->assertStatus(202)
            ->assertJsonPath('data.run_id', 9)
            ->assertJsonPath('data.n_chunks', 2);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'model.batch_predicted',
            'resource' => 'model',
            'user_id' => $this->analyst->getKey(),
        ]);
    }

    public function test_the_api_batch_predict_endpoint_requires_a_dataset(): void
    {
        Sanctum::actingAs($this->analyst);

        $this->postJson('/api/ml/batch-predict', ['model_type' => 'churn'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('dataset');

        Http::assertNothingSent();
    }

    public function test_the_api_batch_predict_endpoint_is_forbidden_for_a_viewer(): void
    {
        Sanctum::actingAs(User::factory()->viewer()->create());

        $this->postJson('/api/ml/batch-predict', [
            'model_type' => 'churn',
            'dataset' => [['recency' => 12]],
        ])->assertForbidden();

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // api: engine-down behaviour
    // ------------------------------------------------------------------

    public function test_the_api_enterprise_endpoints_surface_engine_outages(): void
    {
        $this->engineDown = true;
        Sanctum::actingAs($this->analyst);

        $this->getJson('/api/ml/experiments')
            ->assertStatus(503)
            ->assertJsonPath('code', 'ai_engine_error');

        $this->getJson('/api/ml/models/7/events')
            ->assertStatus(503)
            ->assertJsonPath('code', 'ai_engine_error');
    }

    // ------------------------------------------------------------------
    // web
    // ------------------------------------------------------------------

    public function test_the_index_renders_experiments_and_the_audit_trail(): void
    {
        $this->actingAs($this->admin)
            ->get(route('ml.index', ['model' => 7]))
            ->assertOk()
            ->assertViewHas('experiments', fn ($experiments): bool => is_array($experiments) && count($experiments) === 1)
            ->assertViewHas('events', fn ($events): bool => is_array($events) && count($events) === 2)
            ->assertSee('churn_q1_baseline')
            ->assertSee('Kembalikan ke versi sebelumnya', false);
    }

    public function test_the_index_hides_admin_controls_from_an_analyst(): void
    {
        $response = $this->actingAs($this->analyst)
            ->get(route('ml.index', ['model' => 7]))
            ->assertOk();

        $response->assertDontSee('Promosikan versi', false);
    }

    public function test_the_index_degrades_when_the_engine_is_down(): void
    {
        $this->engineDown = true;

        $this->actingAs($this->analyst)
            ->get(route('ml.index'))
            ->assertOk()
            ->assertViewHas('models', [])
            ->assertViewHas('experiments', [])
            ->assertViewHas('error', fn ($error): bool => is_string($error) && $error !== '');
    }

    public function test_web_rollback_restores_the_predecessor_and_audits(): void
    {
        $this->actingAs($this->admin)
            ->from(route('ml.index'))
            ->post('/ml/7/rollback', ['note' => 'bad deploy'])
            ->assertRedirect(route('ml.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'model.rolled_back',
            'resource' => 'model',
            'resource_id' => 7,
            'user_id' => $this->admin->getKey(),
        ]);
    }

    public function test_web_rollback_is_forbidden_for_an_analyst(): void
    {
        $this->actingAs($this->analyst)
            ->from(route('ml.index'))
            ->post('/ml/7/rollback', [])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_web_batch_predict_redirects_with_a_status_and_audits(): void
    {
        $this->actingAs($this->analyst)
            ->from(route('ml.index'))
            ->post('/ml/batch-predict', [
                'model_type' => 'churn',
                'model_name' => 'churn_pelanggan',
                'dataset' => '[{"recency": 12}]',
            ])
            ->assertRedirect(route('ml.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'model.batch_predicted',
            'resource' => 'model',
            'user_id' => $this->analyst->getKey(),
        ]);
    }

    public function test_web_batch_predict_rejects_a_non_array_dataset(): void
    {
        $this->actingAs($this->analyst)
            ->from(route('ml.index'))
            ->post('/ml/batch-predict', [
                'model_type' => 'churn',
                'dataset' => '{"recency": 12}',
            ])
            ->assertSessionHasErrors('dataset');

        Http::assertNothingSent();
    }

    public function test_web_batch_predict_is_forbidden_for_a_viewer(): void
    {
        $this->actingAs(User::factory()->viewer()->create())
            ->post('/ml/batch-predict', ['model_type' => 'churn'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_web_create_experiment_redirects_and_audits(): void
    {
        $this->actingAs($this->analyst)
            ->post('/ml/experiments', [
                'model_type' => 'churn',
                'name' => 'churn_q1_baseline',
            ])
            ->assertRedirect(route('ml.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'model.experiment_created',
            'resource' => 'experiment',
            'user_id' => $this->analyst->getKey(),
        ]);
    }

    public function test_web_promote_experiment_is_admin_only(): void
    {
        $this->actingAs($this->analyst)
            ->post('/ml/experiments/11/promote', [])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->from(route('ml.index'))
            ->post('/ml/experiments/11/promote', [])
            ->assertRedirect(route('ml.index'))
            ->assertSessionHas('status');

        Http::assertSent(fn (ClientRequest $r): bool => str_ends_with($r->url(), '/api/v1/training/experiments/11/promote'));
    }
}
