<?php

namespace Tests\Unit;

use App\Support\ApiResponse;
use App\Support\PlatformHealth;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The support layer is the shared vocabulary between the HTTP surface and the
 * console. Nothing here talks to the engine or the HTTP kernel: the only
 * outside edge is the HTTP client, faked the same way `AiEngineClientTest`
 * fakes it.
 */
class SupportLayerTest extends TestCase
{
    /**
     * Every component `PlatformHealth::report()` must answer for, in the order
     * `platform:doctor` prints them.
     *
     * @var list<string>
     */
    private const REPORT_COMPONENTS = [
        'app_key',
        'app_env',
        'app_debug',
        'engine_url',
        'service_key',
        'max_upload_mb',
        'quality_threshold',
        'engine_health',
        'engine_readiness',
        'engine_auth',
        'database',
        'database_extensions',
        'engine_tables',
        'laravel_tables',
        'records',
        'storage',
        'datasets_disk',
    ];

    // ------------------------------------------------------------------
    // ApiResponse::data()
    // ------------------------------------------------------------------

    public function test_data_wraps_any_payload_under_a_single_key(): void
    {
        $single = ApiResponse::data(['id' => 1, 'name' => 'Penjualan']);
        $list = ApiResponse::data([['id' => 1], ['id' => 2]]);
        $empty = ApiResponse::data([]);

        $this->assertSame(200, $single->getStatusCode());
        $this->assertSame(['data' => ['id' => 1, 'name' => 'Penjualan']], $single->getData(true));
        $this->assertSame([['id' => 1], ['id' => 2]], $list->getData(true)['data']);
        $this->assertSame(['data' => []], $empty->getData(true));
    }

    public function test_data_carries_the_requested_status_and_sibling_envelope_keys(): void
    {
        $response = ApiResponse::data(['id' => 1], 201, ['meta' => ['page' => 1]]);
        $body = $response->getData(true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(1, $body['data']['id']);
        $this->assertSame(['page' => 1], $body['meta']);
    }

    public function test_an_extra_envelope_key_cannot_overwrite_the_data_payload(): void
    {
        // `array_merge` puts $extra last, so a caller that passes a `data` key
        // replaces the payload. Pin that ordering rather than assuming it.
        $response = ApiResponse::data(['id' => 1], 200, ['data' => ['id' => 2]]);

        $this->assertSame(['id' => 2], $response->getData(true)['data']);
    }

    // ------------------------------------------------------------------
    // ApiResponse::message()
    // ------------------------------------------------------------------

    public function test_message_sends_only_a_message_by_default(): void
    {
        $response = ApiResponse::message('Percakapan dihapus.');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['message' => 'Percakapan dihapus.'], $response->getData(true));
    }

    public function test_message_honours_a_custom_status_and_meta(): void
    {
        $response = ApiResponse::message('Antrean.', 202, ['meta' => ['import_job_id' => 42]]);
        $body = $response->getData(true);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('Antrean.', $body['message']);
        $this->assertSame(42, $body['meta']['import_job_id']);
    }

    // ------------------------------------------------------------------
    // ApiResponse::error()
    // ------------------------------------------------------------------

    public function test_error_defaults_to_400_and_the_generic_error_code(): void
    {
        $response = ApiResponse::error('Boom.');
        $body = $response->getData(true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Boom.', $body['message']);
        $this->assertSame('error', $body['code']);
    }

    public function test_error_details_are_always_an_object_even_when_empty(): void
    {
        // A list-shaped `errors` would make a JS client iterate instead of
        // index, so the envelope casts it to an object in both cases.
        $empty = ApiResponse::error('Gagal.', 422, 'validation_failed')->getData(true);
        $filled = ApiResponse::error('Gagal.', 422, 'validation_failed', [
            'mappings' => ['required'],
            'file' => ['max:20'],
        ])->getData(true);

        $this->assertSame([], $empty['errors']);
        $this->assertSame('required', $filled['errors']['mappings'][0]);
        $this->assertSame('max:20', $filled['errors']['file'][0]);
    }

    public function test_error_never_invents_a_success_key(): void
    {
        $body = ApiResponse::error('Gagal.', 404, 'not_found')->getData(true);

        $this->assertArrayNotHasKey('data', $body);
        $this->assertSame(['message', 'code', 'errors'], array_keys($body));
    }

    // ------------------------------------------------------------------
    // ApiResponse::paginate()
    // ------------------------------------------------------------------

    public function test_paginate_emits_the_four_documented_meta_keys(): void
    {
        $response = ApiResponse::paginate(new LengthAwarePaginator(
            items: [['id' => 1], ['id' => 2]],
            total: 42,
            perPage: 20,
            currentPage: 2,
        ));
        $body = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $body['data']);
        $this->assertSame(
            ['total' => 42, 'page' => 2, 'per_page' => 20, 'last_page' => 3],
            $body['meta'],
        );
    }

    public function test_paginate_reports_a_zero_total_without_dividing_by_zero(): void
    {
        $body = ApiResponse::paginate(
            new LengthAwarePaginator(items: [], total: 0, perPage: 20, currentPage: 1),
        )->getData(true);

        $this->assertSame([], $body['data']);
        $this->assertSame(0, $body['meta']['total']);
        $this->assertSame(1, $body['meta']['last_page']);
    }

    public function test_paginate_drops_only_null_and_empty_string_filters(): void
    {
        $body = ApiResponse::paginate(
            new LengthAwarePaginator(items: [], total: 0, perPage: 20, currentPage: 1),
            [
                'q' => 'retail',
                'status' => null,
                'dataset_type' => '',
                // `array_filter` without a callback would drop both of these;
                // `page` and `per_page` are legitimate 0-valued filters.
                'page' => 0,
                'per_page' => '0',
            ],
        )->getData(true);

        $this->assertSame(
            ['q' => 'retail', 'page' => 0, 'per_page' => '0'],
            $body['query'],
        );
    }

    public function test_paginate_keeps_a_falsy_but_meaningful_filter_value(): void
    {
        $body = ApiResponse::paginate(
            new LengthAwarePaginator(items: [], total: 0, perPage: 20, currentPage: 1),
            ['page' => 0],
        )->getData(true);

        $this->assertArrayHasKey('page', $body['query']);
        $this->assertSame(0, $body['query']['page']);
    }

    // ------------------------------------------------------------------
    // ApiResponse::perPage()
    // ------------------------------------------------------------------

    public function test_per_page_defaults_when_the_query_string_is_empty(): void
    {
        $this->bindRequest();

        $this->assertSame(20, ApiResponse::perPage());
    }

    public function test_per_page_clamps_to_the_documented_maximum_of_100(): void
    {
        $this->bindRequest(['per_page' => 1000]);
        $this->assertSame(100, ApiResponse::perPage());

        $this->bindRequest(['per_page' => 101]);
        $this->assertSame(100, ApiResponse::perPage());
    }

    public function test_per_page_allows_exactly_the_maximum(): void
    {
        $this->bindRequest(['per_page' => 100]);

        $this->assertSame(100, ApiResponse::perPage());
    }

    public function test_per_page_falls_back_to_the_default_for_zero_negative_and_junk_values(): void
    {
        foreach ([0, -5, 'abc', 'null'] as $value) {
            $this->bindRequest(['per_page' => $value]);

            $this->assertSame(20, ApiResponse::perPage(), 'per_page='.var_export($value, true).' was not rejected.');
        }
    }

    public function test_per_page_honours_a_value_inside_the_bounds(): void
    {
        $this->bindRequest(['per_page' => 25]);
        $this->assertSame(25, ApiResponse::perPage());

        $this->bindRequest(['per_page' => 1]);
        $this->assertSame(1, ApiResponse::perPage());
    }

    public function test_per_page_honours_a_custom_default_and_a_custom_maximum(): void
    {
        $this->bindRequest();
        $this->assertSame(50, ApiResponse::perPage(default: 50));

        $this->bindRequest(['per_page' => 500]);
        $this->assertSame(200, ApiResponse::perPage(max: 200));
        $this->assertSame(100, ApiResponse::perPage(), 'The default max of 100 was not applied.');
    }

    // ------------------------------------------------------------------
    // PlatformHealth: report shape
    // ------------------------------------------------------------------

    public function test_the_report_answers_for_every_documented_component_in_order(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        $report = app(PlatformHealth::class)->report();

        $this->assertSame(self::REPORT_COMPONENTS, array_keys($report));
    }

    public function test_every_component_carries_a_status_a_detail_and_a_known_group(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        $groups = ['configuration', 'ai engine', 'database', 'filesystem'];

        foreach (app(PlatformHealth::class)->report() as $name => $check) {
            $this->assertContains(
                $check['status'],
                [PlatformHealth::OK, PlatformHealth::WARN, PlatformHealth::DOWN],
                "The \"{$name}\" check reported an unknown status.",
            );
            $this->assertIsString($check['detail']);
            $this->assertNotSame('', $check['detail'], "The \"{$name}\" check has an empty detail.");
            $this->assertContains($check['group'], $groups, "The \"{$name}\" check has an unknown group.");
        }
    }

    public function test_preflight_is_the_five_call_subset_of_the_report(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        $health = app(PlatformHealth::class);

        $this->assertSame(
            ['engine_url', 'service_key', 'engine_health', 'engine_readiness', 'engine_auth'],
            array_keys($health->preflight()),
        );
        $this->assertSame(
            $health->preflight(),
            array_intersect_key($health->report(), $health->preflight()),
        );
    }

    // ------------------------------------------------------------------
    // PlatformHealth: aggregation
    // ------------------------------------------------------------------

    public function test_overall_status_reports_the_worst_status_in_the_report(): void
    {
        $health = app(PlatformHealth::class);

        $this->assertSame(PlatformHealth::OK, $health->overallStatus(['a' => ['status' => 'ok']]));
        $this->assertSame(
            PlatformHealth::WARN,
            $health->overallStatus(['a' => ['status' => 'ok'], 'b' => ['status' => 'warn']]),
        );
        $this->assertSame(
            PlatformHealth::DOWN,
            $health->overallStatus(['a' => ['status' => 'warn'], 'b' => ['status' => 'down']]),
            'A down component was masked by a warn one.',
        );
    }

    public function test_counts_tallies_every_severity(): void
    {
        $health = app(PlatformHealth::class);

        $this->assertSame(['ok' => 0, 'warn' => 0, 'down' => 0], $health->counts([]));
        $this->assertSame(
            ['ok' => 2, 'warn' => 1, 'down' => 1],
            $health->counts([
                'a' => ['status' => 'ok'],
                'b' => ['status' => 'ok'],
                'c' => ['status' => 'warn'],
                'd' => ['status' => 'down'],
            ]),
        );
    }

    public function test_to_array_wraps_the_report_with_its_status_summary_and_a_timestamp(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        $health = app(PlatformHealth::class);
        $report = $health->report();
        $payload = $health->toArray($report);

        $this->assertSame($health->overallStatus($report), $payload['status']);
        $this->assertSame($health->counts($report), $payload['summary']);
        $this->assertSame($report, $payload['components']);
        $this->assertNotFalse(strtotime((string) $payload['checked_at']));
    }

    public function test_a_rejected_service_key_is_reported_as_a_key_mismatch_not_a_generic_failure(): void
    {
        Http::fake(['*/api/v1/*' => Http::response(['detail' => 'invalid service key'], 401)]);

        $report = app(PlatformHealth::class)->report();

        $this->assertSame(PlatformHealth::DOWN, $report['engine_auth']['status']);
        $this->assertStringContainsString('SERVICE_API_KEY does not match', $report['engine_auth']['detail']);
        $this->assertStringContainsString('SERVICE_API_KEY_HEADER', (string) $report['engine_auth']['remedy']);
    }

    public function test_a_missing_service_key_is_a_configuration_failure_naming_the_fix(): void
    {
        config(['ai_engine.service_key' => '']);
        Http::fake();

        $check = app(PlatformHealth::class)->report()['service_key'];

        $this->assertSame(PlatformHealth::DOWN, $check['status']);
        $this->assertStringContainsString('SERVICE_API_KEY is empty', $check['detail']);
        $this->assertStringContainsString('SERVICE_API_KEY', (string) $check['remedy']);
    }

    public function test_a_non_base64_app_key_warns_instead_of_passing_silently(): void
    {
        config(['app.key' => 'a-plain-not-base64-key']);

        $check = app(PlatformHealth::class)->report()['app_key'];

        $this->assertSame(PlatformHealth::WARN, $check['status']);
        $this->assertStringContainsString('not base64 encoded', $check['detail']);
    }

    public function test_debug_enabled_in_production_is_a_hard_failure(): void
    {
        config(['app.env' => 'production', 'app.debug' => true]);

        $check = app(PlatformHealth::class)->report()['app_debug'];

        $this->assertSame(PlatformHealth::DOWN, $check['status']);
        $this->assertStringContainsString('APP_DEBUG is true', $check['detail']);
    }

    public function test_a_quality_threshold_outside_zero_to_one_is_rejected(): void
    {
        config(['ai_engine.quality_threshold' => 75]);

        $check = app(PlatformHealth::class)->report()['quality_threshold'];

        $this->assertSame(PlatformHealth::DOWN, $check['status']);
        $this->assertStringContainsString('outside 0..1', $check['detail']);
    }

    public function test_a_non_http_engine_url_is_rejected_before_any_call_is_made(): void
    {
        config(['ai_engine.base_url' => 'ftp://fastapi.test']);
        Http::fake();

        $check = app(PlatformHealth::class)->report()['engine_url'];

        $this->assertSame(PlatformHealth::DOWN, $check['status']);
        $this->assertStringContainsString('ftp', $check['detail']);
    }

    public function test_the_service_key_check_reports_the_length_and_never_the_secret(): void
    {
        $key = (string) config('ai_engine.service_key');
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        $check = app(PlatformHealth::class)->report()['service_key'];

        $this->assertSame(PlatformHealth::OK, $check['status']);
        $this->assertStringNotContainsString($key, $check['detail']);
        $this->assertStringContainsString((string) strlen($key).' chars', $check['detail']);
    }

    // ------------------------------------------------------------------
    // PlatformHealth: redaction
    // ------------------------------------------------------------------

    public function test_the_service_key_is_redacted_from_an_engine_error_message(): void
    {
        $key = 'sk-live-9f2c1d0b4a7e-super-secret-value';
        config(['ai_engine.service_key' => $key]);

        Http::fake(['*' => Http::response([
            'detail' => 'upstream rejected the call using '.$key.' against the warehouse',
        ], 503)]);

        $report = app(PlatformHealth::class)->report();
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($key, $encoded);
        $this->assertStringContainsString('[redacted]', $report['engine_health']['detail']);
    }

    public function test_a_dsn_in_the_same_message_is_redacted(): void
    {
        // A driver or DSN error embeds the whole connection string, password
        // included. `redact()` used to mask only the configured SERVICE_API_KEY,
        // so a readiness failure printed the database password into
        // `platform:doctor` and into its `--json` body — a secret into a
        // terminal, a CI log and a ticket.
        $dsn = 'postgresql://aida_app:Hunter2@db.internal:5432/aida_production';

        config(['ai_engine.service_key' => 'sk-live-9f2c1d0b4a7e-super-secret-value']);

        Http::fake(['*' => Http::response([
            'detail' => 'could not reach '.$dsn,
        ], 503)]);

        $report = app(PlatformHealth::class)->report();
        $detail = $report['engine_health']['detail'];

        $this->assertStringContainsString('could not reach', $detail);
        $this->assertStringNotContainsString('Hunter2', $detail, 'The DSN password must not survive redaction.');
        $this->assertStringNotContainsString('sk-live-9f2c1d0b4a7e', $detail);
        $this->assertStringContainsString('aida_app:[redacted]@', $detail);
    }

    public function test_a_key_value_secret_in_a_message_is_redacted(): void
    {
        config(['ai_engine.service_key' => 'k-1']);

        Http::fake(['*' => Http::response([
            'detail' => 'upstream rejected password=Hunter2 api_key=abc123 and db=analytics',
        ], 503)]);

        $detail = app(PlatformHealth::class)->report()['engine_health']['detail'];

        $this->assertStringNotContainsString('Hunter2', $detail);
        $this->assertStringNotContainsString('abc123', $detail);
        $this->assertStringContainsString('db=analytics', $detail, 'A non-secret key=value pair is not redacted.');
    }

    public function test_redacted_output_is_flattened_and_truncated_rather_than_spilling_whole_payloads(): void
    {
        $upstream = str_repeat('very long upstream failure detail ', 40);

        Http::fake(['*' => Http::response(['detail' => $upstream], 503)]);

        $detail = app(PlatformHealth::class)->report()['engine_health']['detail'];

        // `redact()` caps the upstream message at 200 characters; the
        // `platform:doctor` wording is prepended on top of that cap.
        $this->assertLessThanOrEqual(250, strlen($detail));
        $this->assertStringNotContainsString("\n", $detail);
        $this->assertStringNotContainsString($upstream, $detail);
        $this->assertStringContainsString('...', $detail, 'The upstream message was not truncated.');
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function bindRequest(array $query = []): void
    {
        $this->app->instance('request', Request::create('/api/datasets', 'GET', $query));
    }
}
