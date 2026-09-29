<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\CatalogController;
use App\Models\Dataset;
use App\Models\User;
use App\Services\CatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Same controller actions and URIs the master wires into routes/api.php.
        // SubstituteBindings mirrors the `api` middleware group the master
        // wires in routes/api.php: without it `{dataset}` stays a raw string
        // and implicit binding resolves to an empty model.
        Route::middleware(['auth:sanctum', SubstituteBindings::class, 'role:admin,analyst,viewer'])->group(function (): void {
            Route::get('/api/catalog/datasets/{dataset}', [CatalogController::class, 'show']);
            Route::get('/api/catalog/datasets/{dataset}/columns', [CatalogController::class, 'columns']);
            Route::get('/api/catalog/datasets/{dataset}/versions', [CatalogController::class, 'versions']);
            Route::get('/api/catalog/datasets/{dataset}/contracts', [CatalogController::class, 'contract']);
            Route::get('/api/catalog/datasets/{dataset}/health', [CatalogController::class, 'health']);
            Route::get('/api/schema-registry/datasets/{dataset}', [CatalogController::class, 'registry']);
        });

        Route::middleware(['auth:sanctum', SubstituteBindings::class, 'role:admin,analyst'])->group(function (): void {
            Route::post('/api/catalog/datasets/{dataset}/versions', [CatalogController::class, 'storeVersion']);
            Route::post('/api/catalog/datasets/{dataset}/columns/{column}/annotate', [CatalogController::class, 'annotate']);
            Route::post('/api/catalog/datasets/{dataset}/contracts', [CatalogController::class, 'upsertContract']);
            Route::post('/api/schema-registry/datasets/{dataset}/drift', [CatalogController::class, 'drift']);
        });
    }

    protected function analyst(): User
    {
        return User::factory()->analyst()->create();
    }

    protected function dataset(array $attributes = []): Dataset
    {
        return Dataset::factory()->create($attributes);
    }

    public function test_registering_versions_snapshots_schema_and_increments(): void
    {
        $analyst = $this->analyst();
        Sanctum::actingAs($analyst);
        $dataset = $this->dataset(['user_id' => $analyst->getKey()]);

        $this->postJson('/api/catalog/datasets/'.$dataset->uuid.'/versions', ['notes' => 'initial snapshot'])
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonStructure(['data' => ['version', 'schema_hash', 'schema_snapshot', 'row_count']]);

        $this->postJson('/api/catalog/datasets/'.$dataset->uuid.'/versions', ['notes' => 'after remap'])
            ->assertCreated()
            ->assertJsonPath('data.version', 2);

        $this->assertDatabaseCount('dataset_versions', 2);

        // Registering a version syncs the column registry from the snapshot.
        $this->assertDatabaseCount('column_metadata', count($dataset->columns));

        $this->getJson('/api/catalog/datasets/'.$dataset->uuid.'/versions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.version', 2);
    }

    public function test_catalog_show_returns_entry_with_counts(): void
    {
        Sanctum::actingAs($this->analyst());
        $dataset = $this->dataset();

        app(CatalogService::class)->registerVersion($dataset);

        $this->getJson('/api/catalog/datasets/'.$dataset->uuid)
            ->assertOk()
            ->assertJsonPath('data.id', $dataset->uuid)
            ->assertJsonPath('data.versions_count', 1)
            ->assertJsonPath('data.columns_count', count($dataset->columns))
            ->assertJsonPath('data.has_contract', false)
            ->assertJsonStructure(['data' => ['schema_hash', 'quality_score', 'status']]);
    }

    public function test_column_annotation_updates_description_sensitivity_and_pii(): void
    {
        $analyst = $this->analyst();
        Sanctum::actingAs($analyst);
        $dataset = $this->dataset(['user_id' => $analyst->getKey()]);
        app(CatalogService::class)->registerVersion($dataset);

        $this->postJson('/api/catalog/datasets/'.$dataset->uuid.'/columns/kode_pelanggan/annotate', [
            'business_description' => 'Kode unik pelanggan dari master CRM.',
            'sensitivity' => 'confidential',
            'is_pii' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'kode_pelanggan')
            ->assertJsonPath('data.business_description', 'Kode unik pelanggan dari master CRM.')
            ->assertJsonPath('data.sensitivity', 'confidential')
            ->assertJsonPath('data.is_pii', true);

        $this->getJson('/api/catalog/datasets/'.$dataset->uuid.'/columns')
            ->assertOk()
            ->assertJsonCount(count($dataset->columns), 'data');

        // Stats synced earlier survive the annotation round-trip.
        $this->assertDatabaseHas('column_metadata', [
            'dataset_id' => $dataset->getKey(),
            'name' => 'kode_pelanggan',
            'sensitivity' => 'confidential',
            'is_pii' => true,
        ]);
    }

    public function test_column_annotation_rejects_unknown_sensitivity_and_unknown_column(): void
    {
        $analyst = $this->analyst();
        Sanctum::actingAs($analyst);
        $dataset = $this->dataset(['user_id' => $analyst->getKey()]);
        app(CatalogService::class)->registerVersion($dataset);

        $this->postJson('/api/catalog/datasets/'.$dataset->uuid.'/columns/kode_pelanggan/annotate', [
            'sensitivity' => 'top-secret',
        ])->assertStatus(422)->assertJsonValidationErrors('sensitivity');

        $this->postJson('/api/catalog/datasets/'.$dataset->uuid.'/columns/no_such_column/annotate', [
            'sensitivity' => 'low',
        ])->assertNotFound();
    }

    public function test_viewer_can_read_the_catalog_but_cannot_write(): void
    {
        Sanctum::actingAs(User::factory()->viewer()->create());
        $dataset = $this->dataset();

        $this->getJson('/api/catalog/datasets/'.$dataset->uuid)->assertOk();
        $this->getJson('/api/catalog/datasets/'.$dataset->uuid.'/health')->assertOk();

        $this->postJson('/api/catalog/datasets/'.$dataset->uuid.'/versions', [])
            ->assertForbidden();
        $this->postJson('/api/catalog/datasets/'.$dataset->uuid.'/contracts', ['owner' => 'data-team'])
            ->assertForbidden();
    }

    public function test_schema_drift_detects_added_removed_and_type_changed_columns(): void
    {
        $analyst = $this->analyst();
        Sanctum::actingAs($analyst);
        $dataset = $this->dataset(['user_id' => $analyst->getKey()]);
        app(CatalogService::class)->registerVersion($dataset);

        $evolved = collect($dataset->columns)->map(function (array $column): array {
            if ($column['name'] === 'qty') {
                $column['dtype'] = 'float';
            }

            return $column;
        })->reject(fn (array $column): bool => $column['name'] === 'diskon')->values()->all();
        $evolved[] = ['name' => 'catatan', 'dtype' => 'string'];

        $this->postJson('/api/schema-registry/datasets/'.$dataset->uuid.'/drift', ['columns' => $evolved])
            ->assertOk()
            ->assertJsonPath('data.added', ['catatan'])
            ->assertJsonPath('data.removed', ['diskon'])
            ->assertJsonPath('data.type_changed.0.name', 'qty')
            ->assertJsonPath('data.type_changed.0.from', 'integer')
            ->assertJsonPath('data.type_changed.0.to', 'float')
            ->assertJsonPath('data.has_drift', true);

        $this->postJson('/api/schema-registry/datasets/'.$dataset->uuid.'/drift', ['columns' => $dataset->columns])
            ->assertOk()
            ->assertJsonPath('data.has_drift', false);
    }

    public function test_schema_registry_entry_exposes_current_hash(): void
    {
        Sanctum::actingAs($this->analyst());
        $dataset = $this->dataset();
        app(CatalogService::class)->registerVersion($dataset);

        $this->getJson('/api/schema-registry/datasets/'.$dataset->uuid)
            ->assertOk()
            ->assertJsonPath('data.dataset_id', $dataset->uuid)
            ->assertJsonStructure(['data' => ['schema_hash', 'columns']]);
    }

    public function test_contract_upsert_and_freshness_evaluation(): void
    {
        $analyst = $this->analyst();
        Sanctum::actingAs($analyst);
        $dataset = $this->dataset(['user_id' => $analyst->getKey(), 'committed_at' => now(), 'quality_score' => 0.9, 'quality_verdict' => 'pass']);

        $this->postJson('/api/catalog/datasets/'.$dataset->uuid.'/contracts', [
            'owner' => 'data-platform@example.co.id',
            'freshness_sla_hours' => 72,
            'quality_threshold' => 0.75,
        ])
            ->assertCreated()
            ->assertJsonPath('data.owner', 'data-platform@example.co.id')
            ->assertJsonPath('data.freshness_sla_hours', 72);

        $this->getJson('/api/catalog/datasets/'.$dataset->uuid.'/contracts')
            ->assertOk()
            ->assertJsonPath('data.evaluation.has_contract', true)
            ->assertJsonPath('data.evaluation.meets_schema', true)
            ->assertJsonPath('data.evaluation.meets_freshness', true)
            ->assertJsonPath('data.evaluation.meets_quality', true)
            ->assertJsonPath('data.evaluation.passed', true);

        // A stale dataset breaches the same contract.
        $dataset->forceFill(['committed_at' => now()->subHours(100)])->save();

        $this->getJson('/api/catalog/datasets/'.$dataset->uuid.'/contracts')
            ->assertOk()
            ->assertJsonPath('data.evaluation.meets_freshness', false)
            ->assertJsonPath('data.evaluation.passed', false);
    }

    public function test_contract_rejects_invalid_threshold(): void
    {
        $analyst = $this->analyst();
        Sanctum::actingAs($analyst);
        $dataset = $this->dataset(['user_id' => $analyst->getKey()]);

        $this->postJson('/api/catalog/datasets/'.$dataset->uuid.'/contracts', [
            'owner' => 'data-platform@example.co.id',
            'quality_threshold' => 1.5,
        ])->assertStatus(422)->assertJsonValidationErrors('quality_threshold');
    }

    public function test_health_reports_healthy_and_critical_verdicts(): void
    {
        Sanctum::actingAs($this->analyst());

        $healthy = $this->dataset([
            'committed_at' => now(),
            'quality_score' => 0.92,
            'quality_verdict' => 'pass',
        ]);

        $this->getJson('/api/catalog/datasets/'.$healthy->uuid.'/health')
            ->assertOk()
            ->assertJsonPath('data.verdict', 'healthy')
            ->assertJsonPath('data.issues', []);

        $bad = Dataset::factory()->quarantined()->create();

        $this->getJson('/api/catalog/datasets/'.$bad->uuid.'/health')
            ->assertOk()
            ->assertJsonPath('data.verdict', 'critical')
            ->assertJsonPath('data.quality.meets_threshold', false);
    }

    public function test_catalog_mutations_write_audit_logs(): void
    {
        Sanctum::actingAs($analyst = $this->analyst());
        $dataset = $this->dataset(['user_id' => $analyst->getKey()]);

        $this->postJson('/api/catalog/datasets/'.$dataset->uuid.'/versions', []);
        $this->postJson('/api/catalog/datasets/'.$dataset->uuid.'/columns/tanggal/annotate', [
            'business_description' => 'Tanggal transaksi.',
        ]);
        $this->postJson('/api/catalog/datasets/'.$dataset->uuid.'/contracts', [
            'owner' => 'data-platform@example.co.id',
        ]);

        foreach (['catalog.version_registered', 'catalog.column_annotated', 'catalog.contract_upserted'] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'action' => $action,
                'resource' => 'dataset',
                'resource_id' => $dataset->getKey(),
                'user_id' => $analyst->getKey(),
            ]);
        }
    }
}
