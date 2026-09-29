<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\User;
use App\Services\AiEngineClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    protected User $analyst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyst = User::factory()->analyst()->create();
    }

    protected function dataset(): Dataset
    {
        return Dataset::create([
            'user_id' => $this->analyst->getKey(),
            'uuid' => (string) Str::uuid(),
            'name' => 'Penjualan',
            'dataset_type' => 'sales',
            'disk' => 'local',
            'path' => 'datasets/penjualan.csv',
            'status' => 'uploaded',
            'import_job_id' => 42,
        ]);
    }

    public function test_an_unreachable_engine_redirects_the_web_flow_back_with_an_error_flash(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));
        $dataset = $this->dataset();

        $this->actingAs($this->analyst)
            ->from(route('datasets.show', $dataset))
            ->post(route('datasets.preview', $dataset))
            ->assertRedirect(route('datasets.show', $dataset))
            ->assertSessionHas('error');
    }

    public function test_an_unreachable_engine_returns_a_json_error_for_api_callers(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));
        Sanctum::actingAs($this->analyst);

        $this->getJson(route('api.analytics.kpi'))
            ->assertStatus(503)
            ->assertJsonPath('code', 'ai_engine_error')
            ->assertJsonStructure(['message', 'code', 'operation']);
    }

    public function test_an_engine_http_500_surfaces_as_502(): void
    {
        Http::fake(['*/api/v1/*' => Http::response(['detail' => 'boom'], 500)]);
        Sanctum::actingAs($this->analyst);

        $this->getJson(route('api.analytics.kpi'))
            ->assertStatus(502)
            ->assertJsonPath('code', 'ai_engine_error');
    }

    public function test_an_unconfigured_engine_returns_503(): void
    {
        config(['ai_engine.service_key' => '']);
        $this->app->forgetInstance(AiEngineClient::class);
        Sanctum::actingAs($this->analyst);
        Http::fake();

        $this->getJson(route('api.analytics.kpi'))
            ->assertStatus(503)
            ->assertJsonPath('code', 'ai_engine_error');
    }

    public function test_an_engine_404_is_surfaced_as_404_for_import_jobs(): void
    {
        Http::fake(['*/api/v1/imports/jobs/*' => Http::response(['detail' => 'Not Found'], 404)]);
        Sanctum::actingAs($this->analyst);

        $this->getJson(route('api.import-jobs.show', 999))
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    }

    public function test_the_dataset_detail_page_still_renders_when_the_engine_is_down(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));
        $dataset = $this->dataset();

        $this->actingAs($this->analyst)
            ->get(route('datasets.show', $dataset))
            ->assertOk();
    }
}
