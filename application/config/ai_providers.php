<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OpenCode Go defaults
    |--------------------------------------------------------------------------
    |
    | Single source of truth for the OpenCode Go integration. The base URL is
    | configurable per provider row; this is only the default suggested in
    | the admin form and used when a row leaves it blank.
    |
    */
    'opencode_go' => [
        'default_base_url' => env('OPENCODE_GO_BASE_URL', 'https://opencode.ai/zen/go/v1'),
        'models_path' => '/models',
        'responses_path' => '/responses',
        'timeout_seconds' => 30,
        'max_retries' => 3,
        // Statuses worth one more attempt. Auth/validation errors
        // (400/401/403/404/422) are never retried.
        'retry_statuses' => [408, 429, 500, 502, 503, 504],
        // Upper bound (seconds) honored from a Retry-After response header.
        'max_retry_after_seconds' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Generic per-request defaults for provider HTTP calls
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'timeout_seconds' => 30,
        'max_retries' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Live integration tests
    |--------------------------------------------------------------------------
    |
    | When OPENCODE_GO_API_KEY is present in the environment, live tests hit
    | the real API. Otherwise they report SKIPPED — never faked.
    |
    */
    'live' => [
        'api_key' => env('OPENCODE_GO_API_KEY'),
        'base_url' => env('OPENCODE_GO_BASE_URL', 'https://opencode.ai/zen/go/v1'),
        'model' => env('OPENCODE_GO_MODEL', 'muse-spark-1.3-contributor'),
    ],
];
