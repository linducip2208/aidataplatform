<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeleteDatasetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    protected function storedDataset(array $attributes = []): Dataset
    {
        $path = 'datasets/2026/09/penjualan.csv';
        Storage::disk('local')->put($path, "a,b\n1,2\n");

        return Dataset::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Penjualan',
            'dataset_type' => 'sales',
            'disk' => 'local',
            'path' => $path,
            'status' => 'uploaded',
            ...$attributes,
        ]);
    }

    public function test_deleting_a_dataset_removes_the_row_and_the_file_from_disk(): void
    {
        $dataset = $this->storedDataset();
        Storage::disk('local')->assertExists($dataset->path);

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('datasets.destroy', $dataset))
            ->assertRedirect(route('datasets.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('datasets', ['id' => $dataset->getKey()]);
        Storage::disk('local')->assertMissing($dataset->path);
    }

    public function test_a_viewer_cannot_delete_a_dataset(): void
    {
        $dataset = $this->storedDataset();

        $this->actingAs(User::factory()->viewer()->create())
            ->delete(route('datasets.destroy', $dataset))
            ->assertForbidden();

        $this->assertDatabaseHas('datasets', ['id' => $dataset->getKey()]);
        Storage::disk('local')->assertExists($dataset->path);
    }

    public function test_an_analyst_can_delete_a_dataset(): void
    {
        $analyst = User::factory()->analyst()->create();
        $dataset = $this->storedDataset(['user_id' => $analyst->getKey()]);

        $this->actingAs($analyst)
            ->delete(route('datasets.destroy', $dataset))
            ->assertRedirect(route('datasets.index'));

        $this->assertDatabaseMissing('datasets', ['id' => $dataset->getKey()]);
    }

    public function test_deleting_a_dataset_is_audited(): void
    {
        $analyst = User::factory()->analyst()->create();
        $dataset = $this->storedDataset(['user_id' => $analyst->getKey()]);

        $this->actingAs($analyst)
            ->delete(route('datasets.destroy', $dataset));

        // Delete removes the row and the uploaded file with no undo, so the one
        // destructive action an analyst can take must leave a trace.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'dataset.deleted',
            'resource' => 'dataset',
            'resource_id' => $dataset->getKey(),
        ]);
    }

    public function test_the_api_delete_endpoint_returns_a_message_envelope(): void
    {
        $analyst = User::factory()->analyst()->create();
        $dataset = $this->storedDataset(['user_id' => $analyst->getKey()]);

        $this->actingAs($analyst)
            ->deleteJson(route('api.datasets.destroy', $dataset))
            ->assertOk()
            ->assertJsonPath('message', 'Dataset deleted.');

        $this->assertDatabaseMissing('datasets', ['id' => $dataset->getKey()]);
    }
}
