<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\LineageController;
use App\Models\DataLineage;
use App\Models\Dataset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LineageTest extends TestCase
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
            Route::get('/api/lineage/datasets/{dataset}/graph', [LineageController::class, 'graph']);
            Route::get('/api/lineage/{nodeType}/{nodeId}/upstream', [LineageController::class, 'upstream']);
            Route::get('/api/lineage/{nodeType}/{nodeId}/downstream', [LineageController::class, 'downstream']);
        });

        Route::middleware(['auth:sanctum', SubstituteBindings::class, 'role:admin,analyst'])->group(function (): void {
            Route::post('/api/lineage', [LineageController::class, 'store']);
        });
    }

    protected function analyst(): User
    {
        return User::factory()->analyst()->create();
    }

    protected function chain(Dataset $dataset): void
    {
        DataLineage::recordImportLineage($dataset, 7, 'etl_load:sales', 'run-7');
        DataLineage::recordTransformation('dataset', $dataset->uuid, 'table', 'fact_sales', 'warehouse_load', 'run-7');
        DataLineage::recordTransformation('table', 'fact_sales', 'model', 'churn:v3', 'feature_build', 'run-9');
    }

    public function test_recording_a_lineage_edge_via_api(): void
    {
        Sanctum::actingAs($this->analyst());
        $dataset = Dataset::factory()->create();

        $this->postJson('/api/lineage', [
            'source_type' => 'import_job',
            'source_id' => '7',
            'target_type' => 'dataset',
            'target_id' => $dataset->uuid,
            'transform' => 'etl_load:sales',
            'run_reference' => 'run-7',
        ])
            ->assertCreated()
            ->assertJsonPath('data.source_type', 'import_job')
            ->assertJsonPath('data.target_id', $dataset->uuid);

        $this->assertDatabaseHas('data_lineages', [
            'source_type' => 'import_job',
            'source_id' => '7',
            'target_type' => 'dataset',
            'target_id' => $dataset->uuid,
        ]);
    }

    public function test_recording_the_same_edge_twice_is_idempotent(): void
    {
        Sanctum::actingAs($this->analyst());
        $dataset = Dataset::factory()->create();

        $payload = [
            'source_type' => 'import_job',
            'source_id' => '7',
            'target_type' => 'dataset',
            'target_id' => $dataset->uuid,
            'transform' => 'etl_load:sales',
        ];

        $this->postJson('/api/lineage', $payload)->assertCreated();
        $this->postJson('/api/lineage', $payload)->assertCreated();
        $this->assertDatabaseCount('data_lineages', 1);
    }

    public function test_record_import_lineage_hook_creates_the_import_edge(): void
    {
        $dataset = Dataset::factory()->create(['import_job_id' => 42]);

        // The one-liner master calls from DatasetIngestionService::commit().
        $edge = DataLineage::recordImportLineage($dataset, (int) $dataset->import_job_id);

        $this->assertSame('import_job', $edge->source_type);
        $this->assertSame('42', $edge->source_id);
        $this->assertSame('dataset', $edge->target_type);
        $this->assertSame($dataset->uuid, $edge->target_id);
    }

    public function test_upstream_traversal_walks_back_to_the_import_job(): void
    {
        Sanctum::actingAs($this->analyst());
        $dataset = Dataset::factory()->create();
        $this->chain($dataset);

        $this->getJson('/api/lineage/table/fact_sales/upstream')
            ->assertOk()
            ->assertJsonPath('data.edges.0.target_id', 'fact_sales');

        $response = $this->getJson('/api/lineage/table/fact_sales/upstream?depth=5')->assertOk();
        $nodeIds = collect($response->json('data.nodes'))->pluck('id')->all();

        $this->assertContains('fact_sales', $nodeIds);
        $this->assertContains($dataset->uuid, $nodeIds);
        $this->assertContains('7', $nodeIds);
    }

    public function test_downstream_traversal_walks_forward_to_the_model(): void
    {
        Sanctum::actingAs($this->analyst());
        $dataset = Dataset::factory()->create();
        $this->chain($dataset);

        $response = $this->getJson('/api/lineage/import_job/7/downstream?depth=5')->assertOk();
        $nodeIds = collect($response->json('data.nodes'))->pluck('id')->all();

        $this->assertContains('7', $nodeIds);
        $this->assertContains($dataset->uuid, $nodeIds);
        $this->assertContains('fact_sales', $nodeIds);
        $this->assertContains('churn:v3', $nodeIds);
    }

    public function test_dataset_graph_returns_both_directions(): void
    {
        Sanctum::actingAs($this->analyst());
        $dataset = Dataset::factory()->create();
        $this->chain($dataset);

        $this->getJson('/api/lineage/datasets/'.$dataset->uuid.'/graph')
            ->assertOk()
            ->assertJsonPath('data.node.id', $dataset->uuid)
            ->assertJsonStructure(['data' => ['node', 'upstream', 'downstream']]);
    }

    public function test_lineage_reads_allow_viewers_but_writes_do_not(): void
    {
        Sanctum::actingAs(User::factory()->viewer()->create());
        $dataset = Dataset::factory()->create();
        $this->chain($dataset);

        $this->getJson('/api/lineage/datasets/'.$dataset->uuid.'/graph')->assertOk();

        $this->postJson('/api/lineage', [
            'source_type' => 'dataset',
            'source_id' => $dataset->uuid,
            'target_type' => 'table',
            'target_id' => 'fact_sales',
        ])->assertForbidden();
    }

    public function test_lineage_recording_validates_its_payload(): void
    {
        Sanctum::actingAs($this->analyst());

        $this->postJson('/api/lineage', ['source_type' => 'dataset'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['source_id', 'target_type', 'target_id']);
    }

    public function test_lineage_recording_writes_an_audit_log(): void
    {
        Sanctum::actingAs($analyst = $this->analyst());
        $dataset = Dataset::factory()->create();

        $response = $this->postJson('/api/lineage', [
            'source_type' => 'import_job',
            'source_id' => '7',
            'target_type' => 'dataset',
            'target_id' => $dataset->uuid,
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'catalog.lineage_recorded',
            'resource' => 'lineage',
            'resource_id' => $response->json('data.id'),
            'user_id' => $analyst->getKey(),
        ]);
    }
}
