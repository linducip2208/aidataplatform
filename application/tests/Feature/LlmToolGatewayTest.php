<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\User;
use App\Services\LlmToolGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tool gateway: allowlisted data tools execute against real local reads;
 * everything else — shell most of all — is refused with a structured
 * error, never executed, never faked.
 */
class LlmToolGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_datasets_returns_real_rows(): void
    {
        Dataset::factory()->committed()->create(['name' => 'Penjualan Retail 2026']);

        $result = (new LlmToolGateway)->execute('list_datasets', ['limit' => 5], User::factory()->admin()->create());

        $this->assertTrue($result['ok']);
        $this->assertSame('Penjualan Retail 2026', $result['result']['datasets'][0]['name']);
    }

    public function test_schema_and_columns_come_from_the_stored_metadata(): void
    {
        $dataset = Dataset::factory()->committed()->create();

        $schema = (new LlmToolGateway)->execute('get_dataset_schema', ['dataset' => $dataset->getKey()]);
        $described = (new LlmToolGateway)->execute('describe_columns', ['dataset' => $dataset->uuid]);

        $this->assertTrue($schema['ok']);
        $this->assertSame($dataset->columns, $schema['result']['columns']);
        $this->assertTrue($described['ok']);
        $this->assertArrayHasKey('dtype_summary', $described['result']);
    }

    public function test_unknown_dataset_is_an_explicit_error(): void
    {
        $result = (new LlmToolGateway)->execute('get_dataset_schema', ['dataset' => 999999]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function test_shell_and_unknown_tools_are_refused(): void
    {
        $gateway = new LlmToolGateway;

        foreach (['exec', 'shell', 'eval', 'curl_exec', 'drop_table', 'list_tables'] as $tool) {
            $result = $gateway->execute($tool);

            $this->assertFalse($result['ok'], "[{$tool}] must be refused.");
        }
    }

    public function test_engine_owned_tools_report_unsupported_without_faking(): void
    {
        foreach (['run_sql', 'run_python', 'get_statistics', 'create_chart', 'save_analysis'] as $tool) {
            $result = (new LlmToolGateway)->execute($tool, []);

            $this->assertFalse($result['ok'], "[{$tool}] must not fake a result.");
            $this->assertStringContainsString('unsupported', strtolower($result['error']));
        }
    }

    public function test_definitions_only_advertise_executable_tools(): void
    {
        $names = collect(LlmToolGateway::definitions())->map(fn ($def) => $def['function']['name'])->all();

        $this->assertSame(['list_datasets', 'get_dataset_schema', 'describe_columns', 'preview_dataset'], $names);
    }
}
