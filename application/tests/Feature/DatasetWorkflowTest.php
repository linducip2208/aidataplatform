<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DatasetWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $analyst;

    /**
     * Read at request time, not at fake-registration time: a later `Http::fake()`
     * call cannot override an earlier stub (stub callbacks are matched
     * first-registered-wins), so per-test variation has to go through state.
     */
    protected float $qualityScore = 0.91;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyst = User::factory()->analyst()->create();
        $this->fakeEngine();
    }

    /**
     * A single closure stub rather than a URL map: `Http::response()` returns a
     * promise, and stub callbacks are resolved first-registered-wins, so a
     * second `Http::fake()` in a test could never override a map registered in
     * `setUp()`. Reading `$this->qualityScore` at request time is what makes
     * per-test variation possible.
     */
    protected function fakeEngine(): void
    {
        Http::fake(function (ClientRequest $request) {
            $url = $request->url();

            $payload = match (true) {
                str_contains($url, '/imports/preview/') => [
                    'row_count' => 128,
                    'column_count' => 2,
                    'columns' => [
                        ['name' => 'tanggal', 'dtype' => 'date', 'missing' => 0, 'sample' => ['2026-01-05']],
                        ['name' => 'qty', 'dtype' => 'int64', 'missing' => 3, 'sample' => ['4']],
                    ],
                    'sample_rows' => [['tanggal' => '2026-01-05', 'qty' => 4]],
                    'warnings' => [],
                    'errors' => [],
                ],
                str_ends_with($url, '/imports/mapping/suggest') => [
                    'tanggal' => 'transaction_date',
                    'qty' => 'quantity',
                ],
                str_ends_with($url, '/imports/mapping') => [
                    'mappings' => ['tanggal' => 'transaction_date', 'qty' => 'quantity'],
                ],
                str_contains($url, '/imports/quality/') => $this->qualityReport($this->qualityScore),
                str_ends_with($url, '/imports/commit') => ['import_job_id' => 42, 'status' => 'queued'],
                default => [],
            };

            return Http::response(['success' => true, 'data' => $payload], 200);
        });
    }

    /** @return array<string, mixed> */
    protected function qualityReport(float $score = 0.91): array
    {
        return [
            'score' => $score,
            'breakdown' => [
                'completeness' => 0.97, 'uniqueness' => 0.88, 'validity' => 0.92, 'consistency' => 0.87,
            ],
            'issues' => [],
            'passed' => $score >= 0.75,
        ];
    }

    protected function dataset(array $attributes = []): Dataset
    {
        return Dataset::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Penjualan Retail 2026',
            'dataset_type' => 'sales',
            'source_filename' => 'penjualan.csv',
            'disk' => 'local',
            'path' => 'datasets/2026/09/penjualan.csv',
            'size_bytes' => 2048,
            'status' => 'uploaded',
            'import_job_id' => 42,
            'user_id' => $this->analyst->getKey(),
            ...$attributes,
        ]);
    }

    public function test_preview_stores_the_columns_and_row_count_on_the_row(): void
    {
        $dataset = $this->dataset();

        $this->actingAs($this->analyst)
            ->from(route('datasets.show', $dataset))
            ->post(route('datasets.preview', $dataset))
            ->assertRedirect(route('datasets.show', $dataset))
            ->assertSessionHas('status');

        $fresh = $dataset->fresh();

        $this->assertSame(128, (int) $fresh->row_count);
        $this->assertSame(2, (int) $fresh->column_count);
        $this->assertSame(['tanggal', 'qty'], $fresh->columnNames());
        $this->assertSame('uploaded', $fresh->status->value);

        Http::assertSent(fn (ClientRequest $r): bool => str_ends_with($r->url(), '/api/v1/imports/preview/42'));
    }

    public function test_mapping_persists_the_mappings_and_flips_the_status_to_mapped(): void
    {
        $dataset = $this->dataset();

        $this->actingAs($this->analyst)
            ->from(route('datasets.show', $dataset))
            ->post(route('datasets.mapping', $dataset), [
                'mappings' => ['tanggal' => 'transaction_date', 'qty' => 'quantity'],
            ])
            ->assertRedirect(route('datasets.show', $dataset));

        $fresh = $dataset->fresh();

        $this->assertSame('mapped', $fresh->status->value);
        $this->assertSame([
            'tanggal' => 'transaction_date',
            'qty' => 'quantity',
        ], $fresh->mappings);

        Http::assertSent(fn (ClientRequest $r): bool => str_ends_with($r->url(), '/api/v1/imports/mapping')
            && $r['import_job_id'] === 42
            && $r['mappings'] === ['tanggal' => 'transaction_date', 'qty' => 'quantity']);
    }

    public function test_mapping_rejects_an_empty_mapping_payload(): void
    {
        $dataset = $this->dataset();

        $this->actingAs($this->analyst)
            ->from(route('datasets.show', $dataset))
            ->post(route('datasets.mapping', $dataset), ['mappings' => []])
            ->assertSessionHasErrors('mappings');
    }

    public function test_quality_passes_when_the_score_clears_the_threshold(): void
    {
        config(['ai_engine.quality_threshold' => 0.75]);
        $dataset = $this->dataset();

        $this->actingAs($this->analyst)
            ->from(route('datasets.show', $dataset))
            ->post(route('datasets.quality', $dataset))
            ->assertRedirect(route('datasets.show', $dataset))
            ->assertSessionHas('status');

        $fresh = $dataset->fresh();

        $this->assertSame(0.91, (float) $fresh->quality_score);
        $this->assertSame('pass', $fresh->quality_verdict);
        $this->assertNotNull($fresh->quality_checked_at);
    }

    public function test_quality_quarantines_when_the_score_is_below_the_threshold(): void
    {
        config(['ai_engine.quality_threshold' => 0.75]);
        $this->qualityScore = 0.42;

        $dataset = $this->dataset();

        $this->actingAs($this->analyst)
            ->from(route('datasets.show', $dataset))
            ->post(route('datasets.quality', $dataset))
            ->assertRedirect(route('datasets.show', $dataset));

        $fresh = $dataset->fresh();

        $this->assertSame(0.42, (float) $fresh->quality_score);
        $this->assertSame('quarantine', $fresh->quality_verdict);
        $this->assertSame('quarantined', $fresh->status->value);
    }

    public function test_commit_sets_the_status_to_importing_and_sends_run_async(): void
    {
        $dataset = $this->dataset([
            'status' => 'mapped',
            'mappings' => ['qty' => 'quantity'],
        ]);

        $this->actingAs($this->analyst)
            ->post(route('datasets.commit', $dataset), ['run_async' => true])
            ->assertRedirect(route('imports.show', $dataset));

        $this->assertSame('importing', $dataset->fresh()->status->value);

        Http::assertSent(fn (ClientRequest $r): bool => str_ends_with($r->url(), '/api/v1/imports/commit')
            && $r['import_job_id'] === 42
            && $r['run_async'] === true
            && $r['mappings'] === ['qty' => 'quantity']);
    }

    public function test_audit_logs_are_written_for_the_workflow_steps(): void
    {
        $dataset = $this->dataset();

        $this->actingAs($this->analyst)->from(route('datasets.show', $dataset))
            ->post(route('datasets.preview', $dataset));
        $this->actingAs($this->analyst)->from(route('datasets.show', $dataset))
            ->post(route('datasets.mapping', $dataset), ['mappings' => ['qty' => 'quantity']]);
        $this->actingAs($this->analyst)->from(route('datasets.show', $dataset))
            ->post(route('datasets.quality', $dataset));
        $this->actingAs($this->analyst)
            ->post(route('datasets.commit', $dataset), ['run_async' => true]);

        foreach (['dataset.mapping_applied', 'dataset.quality_checked', 'dataset.committed'] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'action' => $action,
                'resource' => 'dataset',
                'resource_id' => $dataset->getKey(),
                'user_id' => $this->analyst->getKey(),
            ]);
        }
    }

    public function test_the_workflow_is_forbidden_for_a_viewer(): void
    {
        $viewer = User::factory()->viewer()->create();
        $dataset = $this->dataset();

        $this->actingAs($viewer)
            ->post(route('datasets.preview', $dataset))
            ->assertForbidden();
    }
}
