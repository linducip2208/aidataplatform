<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Object-level ownership on dataset write/delete paths.
 *
 * Reads stay global (shared catalog). Writes require admin or the owning
 * analyst; legacy rows with `user_id = null` stay writable by any analyst.
 */
class DatasetOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Http::fake([
            '*/api/v1/*' => Http::response(['success' => true, 'data' => []], 200),
        ]);
    }

    private function ownedBy(User $user): Dataset
    {
        return Dataset::factory()->create([
            'user_id' => $user->getKey(),
            'import_job_id' => 42,
        ]);
    }

    public function test_owner_analyst_may_write_and_delete(): void
    {
        $analyst = User::factory()->analyst()->create();
        $dataset = $this->ownedBy($analyst);
        Sanctum::actingAs($analyst);

        $this->postJson(route('api.datasets.mapping', $dataset), ['mappings' => ['tanggal' => 'transaction_date']])
            ->assertOk();
        $this->deleteJson(route('api.datasets.destroy', $dataset))
            ->assertOk();
        $this->assertDatabaseMissing('datasets', ['id' => $dataset->getKey()]);
    }

    public function test_other_analyst_is_forbidden_on_owned_dataset(): void
    {
        $owner = User::factory()->analyst()->create();
        $other = User::factory()->analyst()->create();
        $dataset = $this->ownedBy($owner);
        Sanctum::actingAs($other);

        $this->postJson(route('api.datasets.mapping', $dataset), ['mappings' => ['tanggal' => 'transaction_date']])
            ->assertForbidden();
        $this->postJson(route('api.datasets.commit', $dataset), ['run_async' => true])
            ->assertForbidden();
        $this->deleteJson(route('api.datasets.destroy', $dataset))
            ->assertForbidden();

        $this->assertDatabaseHas('datasets', ['id' => $dataset->getKey()]);
        Http::assertNothingSent();
    }

    public function test_admin_may_write_any_dataset(): void
    {
        $owner = User::factory()->analyst()->create();
        $dataset = $this->ownedBy($owner);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson(route('api.datasets.mapping', $dataset), ['mappings' => ['tanggal' => 'transaction_date']])
            ->assertOk();
        $this->deleteJson(route('api.datasets.destroy', $dataset))
            ->assertOk();
    }

    public function test_unowned_dataset_is_admin_only_for_analysts(): void
    {
        $dataset = Dataset::factory()->create(['user_id' => null, 'import_job_id' => 42]);

        Sanctum::actingAs(User::factory()->analyst()->create());
        $this->postJson(route('api.datasets.mapping', $dataset), ['mappings' => ['tanggal' => 'transaction_date']])
            ->assertForbidden();
        $this->deleteJson(route('api.datasets.destroy', $dataset))
            ->assertForbidden();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson(route('api.datasets.mapping', $dataset), ['mappings' => ['tanggal' => 'transaction_date']])
            ->assertOk();
    }

    public function test_catalog_writes_follow_the_same_ownership(): void
    {
        $owner = User::factory()->analyst()->create();
        $dataset = $this->ownedBy($owner);
        Sanctum::actingAs(User::factory()->analyst()->create());

        $this->postJson(route('api.catalog.versions.store', $dataset), ['notes' => 'x'])
            ->assertForbidden();
        $this->postJson(route('api.catalog.contracts.store', $dataset), ['owner' => 'team'])
            ->assertForbidden();
    }

    public function test_reads_stay_global(): void
    {
        $owner = User::factory()->analyst()->create();
        $dataset = $this->ownedBy($owner);
        Sanctum::actingAs(User::factory()->viewer()->create());

        $this->getJson(route('api.datasets.show', $dataset))->assertOk();
        $this->getJson(route('api.catalog.show', $dataset))->assertOk();
    }
}
