<?php

namespace Tests\Feature;

use App\Models\DataLineage;
use App\Models\Dataset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Column-level lineage: the saved column mapping is recorded as edges and
 * served back through the lineage reads.
 */
class ColumnLineageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Http::fake([
            '*/api/v1/imports/mapping' => Http::response(['success' => true, 'data' => [
                'mappings' => ['tanggal' => 'transaction_date'],
            ]], 200),
            '*/api/v1/*' => Http::response(['success' => true, 'data' => []], 200),
        ]);
    }

    private function analyst(): User
    {
        return User::factory()->analyst()->create();
    }

    private function ownedBy(User $user): Dataset
    {
        return Dataset::factory()->forUser($user)->create(['import_job_id' => 42]);
    }

    public function test_saving_a_mapping_records_column_edges(): void
    {
        $analyst = $this->analyst();
        $dataset = $this->ownedBy($analyst);
        Sanctum::actingAs($analyst);

        $this->postJson(route('api.datasets.mapping', $dataset), ['mappings' => ['tanggal' => 'transaction_date']])
            ->assertOk();

        $this->getJson(route('api.lineage.columns', $dataset))
            ->assertOk()
            ->assertJsonPath('data.dataset_id', $dataset->uuid)
            ->assertJsonPath('data.columns.0.source_column', 'tanggal')
            ->assertJsonPath('data.columns.0.target_column', 'transaction_date');
    }

    public function test_remapping_replaces_stale_column_edges(): void
    {
        $analyst = $this->analyst();
        $dataset = $this->ownedBy($analyst);
        Sanctum::actingAs($analyst);

        $this->postJson(route('api.datasets.mapping', $dataset), ['mappings' => ['tanggal' => 'transaction_date']])
            ->assertOk();
        $this->postJson(route('api.datasets.mapping', $dataset), ['mappings' => ['qty' => 'quantity']])
            ->assertOk();

        $columns = $this->getJson(route('api.lineage.columns', $dataset))->assertOk()->json('data.columns');

        $this->assertCount(1, $columns);
        $this->assertSame('qty', $columns[0]['source_column']);
        $this->assertSame('quantity', $columns[0]['target_column']);
        $this->assertSame(
            1,
            DataLineage::query()->where('transform', 'column_mapping')->count(),
            'stale mapping edges must not accumulate.',
        );
    }

    public function test_graph_edges_carry_column_detail(): void
    {
        $analyst = $this->analyst();
        $dataset = $this->ownedBy($analyst);
        Sanctum::actingAs($analyst);

        $this->postJson(route('api.datasets.mapping', $dataset), ['mappings' => ['tanggal' => 'transaction_date']])
            ->assertOk();

        $graph = $this->getJson(route('api.lineage.graph', $dataset))->assertOk()->json();

        $edges = array_merge(
            $graph['data']['downstream']['edges'] ?? [],
            $graph['data']['upstream']['edges'] ?? [],
        );

        $mapping = array_values(array_filter(
            $edges,
            static fn (array $edge): bool => ($edge['transform'] ?? null) === 'column_mapping',
        ));

        $this->assertNotEmpty($mapping, 'the mapping edge must be reachable from its own dataset.');
        $this->assertSame('tanggal', $mapping[0]['source_column']);
        $this->assertSame('transaction_date', $mapping[0]['target_column']);
    }

    public function test_viewer_may_read_columns_but_guest_may_not(): void
    {
        $analyst = $this->analyst();
        $dataset = $this->ownedBy($analyst);

        Sanctum::actingAs(User::factory()->viewer()->create());
        $this->getJson(route('api.lineage.columns', $dataset))->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson(route('api.lineage.columns', $dataset))->assertUnauthorized();
    }
}
