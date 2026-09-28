<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DatasetApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->fakeEngine();
    }

    protected function fakeEngine(): void
    {
        Http::fake([
            '*/api/v1/imports/upload' => Http::response(['success' => true, 'data' => [
                'upload_id' => 1,
                'import_job_id' => 42,
                'validation' => [
                    'ok' => true,
                    'meta' => [
                        'size_bytes' => 2048,
                        'mime' => 'text/csv',
                        'checksum_sha256' => 'abc123',
                    ],
                ],
                'stored_path' => '/data/storage/sales.csv',
            ]], 200),
            '*/api/v1/*' => Http::response(['success' => true, 'data' => []], 200),
        ]);
    }

    protected function analyst(): User
    {
        return User::factory()->analyst()->create();
    }

    public function test_uploading_a_csv_creates_a_dataset_row_and_stores_the_file(): void
    {
        Sanctum::actingAs($this->analyst());

        $response = $this->post(route('api.datasets.store'), [
            'file' => UploadedFile::fake()->createWithContent('sales.csv', "a,b\n1,2\n"),
            'dataset_type' => 'sales',
        ]);

        $response->assertCreated()->assertJsonPath('data.dataset_type', 'sales')
            ->assertJsonPath('data.source_filename', 'sales.csv')
            ->assertJsonPath('data.status', 'uploaded')
            ->assertJsonPath('data.import_job_id', 42);

        $this->assertDatabaseCount('datasets', 1);

        $dataset = Dataset::firstOrFail();
        Storage::disk('local')->assertExists($dataset->path);
        $this->assertSame('local', $dataset->disk);
        $this->assertSame(42, (int) $dataset->import_job_id);
    }

    public function test_a_missing_file_returns_422(): void
    {
        Sanctum::actingAs($this->analyst());

        $this->postJson(route('api.datasets.store'), ['dataset_type' => 'sales'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('datasets', 0);
    }

    public function test_an_unsupported_extension_returns_422(): void
    {
        Sanctum::actingAs($this->analyst());

        // `post`, not `postJson`, and still a JSON 422: the `api/` prefix forces
        // the response type, so a client that never sets an Accept header cannot
        // be handed a redirect it cannot parse.
        $this->post(route('api.datasets.store'), [
            'file' => UploadedFile::fake()->createWithContent('malware.exe', 'MZ'),
            'dataset_type' => 'sales',
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('datasets', 0);
    }

    public function test_an_oversized_file_returns_422(): void
    {
        config(['ai_engine.max_upload_mb' => 1]);
        Sanctum::actingAs($this->analyst());

        $this->post(route('api.datasets.store'), [
            'file' => UploadedFile::fake()->create('sales.csv', 2, 'megabytes'),
            'dataset_type' => 'sales',
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('datasets', 0);
    }

    public function test_an_unknown_dataset_type_returns_422(): void
    {
        Sanctum::actingAs($this->analyst());

        $this->post(route('api.datasets.store'), [
            'file' => UploadedFile::fake()->createWithContent('sales.csv', "a,b\n"),
            'dataset_type' => 'nonsense',
        ])->assertStatus(422)->assertJsonValidationErrors('dataset_type');
    }

    public function test_the_index_returns_the_documented_pagination_envelope(): void
    {
        Sanctum::actingAs($this->analyst());
        Dataset::factory()->count(3)->create();

        $response = $this->getJson(route('api.datasets.index'));

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'page', 'per_page', 'last_page']])
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.per_page', 20);
    }

    public function test_the_index_filters_by_dataset_type(): void
    {
        Sanctum::actingAs($this->analyst());
        Dataset::factory()->forType('sales')->create(['name' => 'Penjualan']);
        Dataset::factory()->forType('inventory')->create(['name' => 'Gudang']);

        $this->getJson(route('api.datasets.index', ['dataset_type' => 'inventory']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.dataset_type', 'inventory')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_the_index_filters_by_status(): void
    {
        Sanctum::actingAs($this->analyst());
        Dataset::factory()->committed()->create(['name' => 'Selesai']);
        Dataset::factory()->create(['name' => 'Baru']);

        $this->getJson(route('api.datasets.index', ['status' => 'committed']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'committed');
    }

    public function test_the_index_searches_by_name_using_the_q_filter(): void
    {
        Sanctum::actingAs($this->analyst());
        Dataset::factory()->create(['name' => 'Penjualan Retail Alpha']);
        Dataset::factory()->create(['name' => 'Stok Gudang Beta']);

        $this->getJson(route('api.datasets.index', ['q' => 'Alpha']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Penjualan Retail Alpha')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_the_index_sorts_by_created_at_descending(): void
    {
        Sanctum::actingAs($this->analyst());
        $older = Dataset::factory()->create(['created_at' => now()->subDays(3)]);
        $newer = Dataset::factory()->create(['created_at' => now()->subDay()]);

        $this->getJson(route('api.datasets.index', ['sort' => '-created_at']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->uuid)
            ->assertJsonPath('data.1.id', $older->uuid);
    }

    public function test_per_page_500_is_clamped_to_100(): void
    {
        Sanctum::actingAs($this->analyst());
        Dataset::factory()->count(3)->create();

        $this->getJson(route('api.datasets.index', ['per_page' => 500]))
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_an_unknown_dataset_id_returns_404(): void
    {
        Sanctum::actingAs($this->analyst());

        $this->getJson(route('api.datasets.show', '11111111-2222-3333-4444-555555555555'))
            ->assertNotFound();
    }

    public function test_show_returns_the_detailed_representation(): void
    {
        Sanctum::actingAs($this->analyst());
        $dataset = Dataset::factory()->committed()->create();

        $this->getJson(route('api.datasets.show', $dataset))
            ->assertOk()
            ->assertJsonPath('data.id', $dataset->uuid)
            ->assertJsonPath('data.quality_verdict', 'pass')
            ->assertJsonStructure(['data' => ['columns', 'mappings', 'metadata']]);
    }

    public function test_a_viewer_cannot_upload_a_dataset(): void
    {
        Sanctum::actingAs(User::factory()->viewer()->create());

        $this->postJson(route('api.datasets.store'), ['dataset_type' => 'sales'])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }
}
