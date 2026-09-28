<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MlTest extends TestCase
{
    use RefreshDatabase;

    protected User $analyst;

    protected User $admin;

    /**
     * Read at request time, not at fake-registration time: stub callbacks are
     * matched first-registered-wins, so a later `Http::fake()` call could never
     * override the single closure registered in `setUp()`. Per-test variation
     * has to go through state.
     */
    protected bool $engineDown = false;

    protected ?int $modelDetailStatus = null;

    protected int $trainedModelId = 7;

    protected int $trainedVersionId = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyst = User::factory()->analyst()->create();
        $this->admin = User::factory()->admin()->create();
        $this->fakeEngine();
    }

    /**
     * One closure stub rather than a URL map: `Http::response()` returns a
     * promise, so per-endpoint fakes registered in a test would race the map
     * from `setUp()` instead of replacing it.
     */
    protected function fakeEngine(): void
    {
        Http::fake(function (ClientRequest $request) {
            if ($this->engineDown) {
                throw new ConnectionException('connection refused');
            }

            $url = $request->url();

            if ($this->modelDetailStatus !== null && $this->isModelDetail($url)) {
                return Http::response(['detail' => 'Not Found'], $this->modelDetailStatus);
            }

            $payload = match (true) {
                str_ends_with($url, '/api/v1/training/train') => $this->trainingResult(),
                str_ends_with($url, '/promote') => $this->promotionResult(),
                $this->isModelDetail($url) => $this->modelDetail(),
                str_ends_with($url, '/api/v1/models') => $this->modelList(),
                default => [],
            };

            return Http::response(['success' => true, 'data' => $payload], 200);
        });
    }

    protected function isModelDetail(string $url): bool
    {
        return preg_match('#/api/v1/models/\d+$#', $url) === 1;
    }

    /** `GET /api/v1/models` -> a list of registry rows, exactly as the engine builds it. */
    protected function modelList(): array
    {
        return [
            [
                'id' => 7,
                'name' => 'forecast_penjualan_harian',
                'model_type' => 'forecast',
                'status' => 'PRODUCTION',
                'production_version_id' => 3,
            ],
            [
                'id' => 8,
                'name' => 'churn_pelanggan',
                'model_type' => 'churn',
                'status' => 'DRAFT',
                'production_version_id' => null,
            ],
        ];
    }

    /** `GET /api/v1/models/{id}` -> one model plus its version rows. */
    protected function modelDetail(): array
    {
        return [
            'id' => 7,
            'name' => 'forecast_penjualan_harian',
            'model_type' => 'forecast',
            'status' => 'PRODUCTION',
            'versions' => [
                [
                    'id' => 3,
                    'version' => 'v3',
                    'status' => 'PRODUCTION',
                    'metrics' => ['mape' => 0.081, 'wape' => 0.12],
                    'artifact_path' => '/data/artifacts/forecast/v3.pkl',
                ],
                [
                    'id' => 2,
                    'version' => 'v2',
                    'status' => 'ARCHIVED',
                    'metrics' => ['mape' => 0.11],
                    'artifact_path' => '/data/artifacts/forecast/v2.pkl',
                ],
            ],
        ];
    }

    /** `POST /api/v1/training/train` -> `TrainResponse`. */
    protected function trainingResult(): array
    {
        return [
            'model_id' => $this->trainedModelId,
            'version_id' => $this->trainedVersionId,
            'version' => 'v1',
            'metrics' => ['mape' => 0.081],
            'status' => 'VALIDATED',
        ];
    }

    /** `POST /api/v1/models/{id}/promote` -> `app/ml/registry.py::promote`. */
    protected function promotionResult(): array
    {
        return ['model_id' => 7, 'version_id' => 3, 'status' => 'PRODUCTION'];
    }

    /** @return array<string, array{string}> */
    public static function supportedModelTypes(): array
    {
        return [
            'forecast' => ['forecast'],
            'churn' => ['churn'],
            'segmentation' => ['segmentation'],
            'anomaly' => ['anomaly'],
            'recommend' => ['recommend'],
        ];
    }

    // ------------------------------------------------------------------
    // web: index
    // ------------------------------------------------------------------

    public function test_the_index_lists_the_engine_registry_models(): void
    {
        $this->actingAs($this->analyst)
            ->get(route('ml.index'))
            ->assertOk()
            ->assertViewIs('ml.index')
            ->assertViewHas('models', fn ($models): bool => is_array($models) && count($models) === 2)
            ->assertViewHas('selected', null)
            ->assertViewHas('error', null);
    }

    public function test_the_index_loads_the_version_history_of_the_selected_model(): void
    {
        $this->actingAs($this->analyst)
            ->get(route('ml.index', ['model' => 7]))
            ->assertOk()
            ->assertViewHas(
                'selected',
                fn ($selected): bool => is_array($selected) && count($selected['versions'] ?? []) === 2
            );

        Http::assertSent(fn (ClientRequest $r): bool => str_ends_with($r->url(), '/api/v1/models/7')
            && $r->method() === 'GET');
    }

    public function test_the_index_renders_an_empty_list_and_an_error_when_the_engine_is_down(): void
    {
        $this->engineDown = true;

        $this->actingAs($this->analyst)
            ->get(route('ml.index'))
            ->assertOk()
            ->assertViewIs('ml.index')
            ->assertViewHas('models', [])
            ->assertViewHas('error', fn ($error): bool => is_string($error) && $error !== '');
    }

    public function test_the_index_hides_the_promote_controls_from_a_non_admin(): void
    {
        $this->actingAs($this->analyst)
            ->get(route('ml.index', ['model' => 7]))
            ->assertOk()
            ->assertViewHas('canApprove', false);
    }

    public function test_the_index_shows_the_promote_controls_to_an_admin(): void
    {
        $this->actingAs($this->admin)
            ->get(route('ml.index', ['model' => 7]))
            ->assertOk()
            ->assertViewHas('canApprove', true);
    }

    // ------------------------------------------------------------------
    // web: train
    // ------------------------------------------------------------------

    #[DataProvider('supportedModelTypes')]
    public function test_train_accepts_each_supported_model_type(string $modelType): void
    {
        $this->actingAs($this->analyst)
            ->post(route('ml.train'), [
                'model_type' => $modelType,
                'name' => 'model-'.$modelType,
            ])
            ->assertRedirect(route('ml.index'))
            ->assertSessionHas('status');

        Http::assertSent(fn (ClientRequest $r): bool => str_ends_with($r->url(), '/api/v1/training/train')
            && $r['model_type'] === $modelType);
    }

    public function test_train_rejects_a_model_type_outside_the_five_supported_ones(): void
    {
        $this->actingAs($this->analyst)
            ->post(route('ml.train'), ['model_type' => 'prophet', 'name' => 'model-prophet'])
            ->assertSessionHasErrors('model_type');

        Http::assertNothingSent();
    }

    public function test_train_rejects_malformed_json_params(): void
    {
        $this->actingAs($this->analyst)
            ->post(route('ml.train'), [
                'model_type' => 'forecast',
                'name' => 'forecast_penjualan_harian',
                'params' => '{"horizon": ',
            ])
            ->assertSessionHasErrors('params');

        Http::assertNothingSent();
    }

    public function test_train_forwards_the_decoded_params_to_the_engine(): void
    {
        $this->actingAs($this->analyst)
            ->post(route('ml.train'), [
                'model_type' => 'forecast',
                'name' => 'forecast_penjualan_harian',
                'params' => '{"horizon": 30, "granularity": "weekly"}',
            ]);

        Http::assertSent(function (ClientRequest $request): bool {
            if (! str_ends_with($request->url(), '/api/v1/training/train')) {
                return false;
            }

            $this->assertSame([
                'model_type' => 'forecast',
                'name' => 'forecast_penjualan_harian',
                'params' => ['horizon' => 30, 'granularity' => 'weekly'],
                'dataset' => null,
            ], $request->data());

            return true;
        });
    }

    public function test_train_writes_an_audit_row(): void
    {
        $this->actingAs($this->analyst)
            ->post(route('ml.train'), ['model_type' => 'churn', 'name' => 'churn_pelanggan']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'model.trained',
            'resource' => 'model',
            'resource_id' => $this->trainedModelId,
            'user_id' => $this->analyst->getKey(),
        ]);
    }

    public function test_train_is_forbidden_for_a_viewer(): void
    {
        $this->actingAs(User::factory()->viewer()->create())
            ->post(route('ml.train'), ['model_type' => 'forecast', 'name' => 'forecast'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // web: promote
    // ------------------------------------------------------------------

    public function test_promote_forwards_the_version_and_target_status_to_the_engine(): void
    {
        $this->actingAs($this->admin)
            ->from(route('ml.index'))
            ->post(route('ml.promote', ['modelId' => 7]), [
                'version_id' => 3,
                'to_status' => 'STAGED',
            ])
            ->assertRedirect(route('ml.index'))
            ->assertSessionHas('status');

        Http::assertSent(fn (ClientRequest $r): bool => str_ends_with($r->url(), '/api/v1/models/7/promote')
            && $r->data() === ['version_id' => 3, 'to_status' => 'STAGED']);
    }

    public function test_promote_defaults_the_target_status_to_production(): void
    {
        $this->actingAs($this->admin)
            ->from(route('ml.index'))
            ->post(route('ml.promote', ['modelId' => 7]), ['version_id' => 3])
            ->assertRedirect(route('ml.index'));

        Http::assertSent(fn (ClientRequest $r): bool => str_ends_with($r->url(), '/api/v1/models/7/promote')
            && $r->data() === ['version_id' => 3, 'to_status' => 'PRODUCTION']);
    }

    public function test_promote_rejects_a_target_status_outside_the_registry_lifecycle(): void
    {
        $this->actingAs($this->admin)
            ->from(route('ml.index'))
            ->post(route('ml.promote', ['modelId' => 7]), [
                'version_id' => 3,
                'to_status' => 'production',
            ])
            ->assertSessionHasErrors('to_status');

        Http::assertNothingSent();
    }

    public function test_promote_is_forbidden_for_an_analyst(): void
    {
        $this->actingAs($this->analyst)
            ->from(route('ml.index'))
            ->post(route('ml.promote', ['modelId' => 7]), ['version_id' => 3])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_promote_writes_an_audit_row(): void
    {
        $this->actingAs($this->admin)
            ->from(route('ml.index'))
            ->post(route('ml.promote', ['modelId' => 7]), ['version_id' => 3, 'to_status' => 'ARCHIVED']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'model.promoted',
            'resource' => 'model',
            'resource_id' => 7,
            'user_id' => $this->admin->getKey(),
        ]);
    }

    // ------------------------------------------------------------------
    // api
    // ------------------------------------------------------------------

    public function test_the_api_train_endpoint_answers_202(): void
    {
        Sanctum::actingAs($this->analyst);

        $this->postJson(route('api.ml.train'), [
            'model_type' => 'forecast',
            'name' => 'forecast_penjualan_harian',
        ])
            ->assertStatus(202)
            ->assertJsonPath('data.model_id', $this->trainedModelId)
            ->assertJsonPath('data.version', 'v1')
            ->assertJsonPath('data.status', 'VALIDATED');
    }

    public function test_the_api_model_detail_endpoint_answers_404_when_the_engine_reports_not_found(): void
    {
        $this->modelDetailStatus = 404;
        Sanctum::actingAs($this->analyst);

        $this->getJson(route('api.ml.models.show', ['modelId' => 999]))
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found')
            ->assertJsonPath('message', 'Model not found.');
    }

    public function test_the_api_model_detail_endpoint_returns_the_engine_model_payload(): void
    {
        Sanctum::actingAs($this->analyst);

        $this->getJson(route('api.ml.models.show', ['modelId' => 7]))
            ->assertOk()
            ->assertJsonPath('data.id', 7)
            ->assertJsonPath('data.versions.0.id', 3);
    }

    public function test_the_api_train_endpoint_is_forbidden_for_a_viewer(): void
    {
        Sanctum::actingAs(User::factory()->viewer()->create());

        $this->postJson(route('api.ml.train'), ['model_type' => 'forecast', 'name' => 'forecast'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_the_api_promote_endpoint_is_forbidden_for_an_analyst(): void
    {
        Sanctum::actingAs($this->analyst);

        $this->postJson(route('api.ml.models.promote', ['modelId' => 7]), ['version_id' => 3])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_the_api_promote_endpoint_forwards_the_version_and_status_to_the_engine(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson(route('api.ml.models.promote', ['modelId' => 7]), [
            'version_id' => 3,
            'to_status' => 'ARCHIVED',
        ])->assertOk();

        Http::assertSent(fn (ClientRequest $r): bool => str_ends_with($r->url(), '/api/v1/models/7/promote')
            && $r->data() === ['version_id' => 3, 'to_status' => 'ARCHIVED']);
    }
}
