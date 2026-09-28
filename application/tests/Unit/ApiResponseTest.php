<?php

namespace Tests\Unit;

use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class ApiResponseTest extends TestCase
{
    protected function bindRequest(array $query = []): void
    {
        $this->app->instance('request', Request::create('/datasets', 'GET', $query));
    }

    public function test_data_wraps_the_payload_and_defaults_to_200(): void
    {
        $response = ApiResponse::data(['id' => 1]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['data' => ['id' => 1]], $response->getData(true));
    }

    public function test_data_accepts_a_custom_status_and_extra_envelope_keys(): void
    {
        $response = ApiResponse::data(['id' => 1], 201, ['meta' => ['page' => 1]]);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(1, $response->getData(true)['data']['id']);
        $this->assertSame(1, $response->getData(true)['meta']['page']);
    }

    public function test_message_envelope_carries_only_the_message(): void
    {
        $response = ApiResponse::message('Token revoked.');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['message' => 'Token revoked.'], $response->getData(true));
    }

    public function test_message_envelope_honours_a_custom_status_and_meta(): void
    {
        $response = ApiResponse::message('Queued.', 202, ['meta' => ['job' => 7]]);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame(7, $response->getData(true)['meta']['job']);
    }

    public function test_error_envelope_carries_message_code_and_details(): void
    {
        $response = ApiResponse::error('Minimal satu kolom harus dipetakan.', 422, 'validation_failed', [
            'mappings' => ['required'],
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $body = $response->getData(true);
        $this->assertSame('Minimal satu kolom harus dipetakan.', $body['message']);
        $this->assertSame('validation_failed', $body['code']);
        $this->assertSame('required', $body['errors']['mappings'][0]);
    }

    public function test_error_envelope_defaults_to_400_and_the_error_code(): void
    {
        $response = ApiResponse::error('Boom.');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('error', $response->getData(true)['code']);
    }

    public function test_per_page_defaults_to_20_when_the_query_is_empty(): void
    {
        $this->bindRequest();

        $this->assertSame(20, ApiResponse::perPage());
    }

    public function test_per_page_clamps_an_oversized_value_to_100(): void
    {
        $this->bindRequest(['per_page' => 1000]);

        $this->assertSame(100, ApiResponse::perPage());
    }

    public function test_per_page_falls_back_to_the_default_for_zero_and_negative_values(): void
    {
        $this->bindRequest(['per_page' => 0]);
        $this->assertSame(20, ApiResponse::perPage());

        $this->bindRequest(['per_page' => -5]);
        $this->assertSame(20, ApiResponse::perPage());
    }

    public function test_per_page_honours_a_value_inside_the_bounds(): void
    {
        $this->bindRequest(['per_page' => 25]);

        $this->assertSame(25, ApiResponse::perPage());
    }

    public function test_per_page_respects_a_custom_default_and_maximum(): void
    {
        $this->bindRequest();

        $this->assertSame(50, ApiResponse::perPage(default: 50));

        $this->bindRequest(['per_page' => 500]);

        $this->assertSame(100, ApiResponse::perPage(default: 50));
        $this->assertSame(200, ApiResponse::perPage(max: 200));
    }

    public function test_paginate_emits_the_documented_meta_keys(): void
    {
        $paginator = new LengthAwarePaginator(
            items: [['id' => 1], ['id' => 2]],
            total: 42,
            perPage: 20,
            currentPage: 2,
        );

        $response = ApiResponse::paginate($paginator);
        $body = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $body['data']);
        $this->assertSame(42, $body['meta']['total']);
        $this->assertSame(2, $body['meta']['page']);
        $this->assertSame(20, $body['meta']['per_page']);
        $this->assertSame(3, $body['meta']['last_page']);
    }

    public function test_paginate_echoes_only_the_non_empty_query_filters(): void
    {
        $paginator = new LengthAwarePaginator(items: [], total: 0, perPage: 20, currentPage: 1);

        $response = ApiResponse::paginate($paginator, [
            'q' => 'retail',
            'dataset_type' => '',
            'status' => null,
            'sort' => '-created_at',
        ]);
        $body = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('retail', $body['query']['q']);
        $this->assertSame('-created_at', $body['query']['sort']);
        $this->assertArrayNotHasKey('dataset_type', $body['query']);
        $this->assertArrayNotHasKey('status', $body['query']);
    }
}
