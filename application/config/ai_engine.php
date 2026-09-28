<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Engine (FastAPI) base URL
    |--------------------------------------------------------------------------
    |
    | In docker compose this is the internal service name (http://fastapi:8000).
    | For local development without Docker use http://127.0.0.1:8001. All
    | requests are server-to-server and always carry the service key header.
    |
    */

    'base_url' => env('AI_ENGINE_URL', 'http://fastapi:8000'),

    /*
    |--------------------------------------------------------------------------
    | Service key
    |--------------------------------------------------------------------------
    |
    | Shared secret between Laravel and the FastAPI engine. The header name
    | must match SERVICE_API_KEY_HEADER in the ai-engine .env.
    |
    */

    'service_key' => env('SERVICE_API_KEY', ''),
    'service_key_header' => env('SERVICE_API_KEY_HEADER', 'X-Service-Key'),

    /*
    |--------------------------------------------------------------------------
    | Timeouts (seconds)
    |--------------------------------------------------------------------------
    |
    | Uploads and LLM-backed chat/report calls are slow, so they get their own
    | longer budget than plain JSON calls.
    |
    */

    'connect_timeout' => (int) env('AI_ENGINE_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('AI_ENGINE_TIMEOUT', 60),
    'llm_timeout' => (int) env('AI_ENGINE_LLM_TIMEOUT', 120),
    'upload_timeout' => (int) env('AI_ENGINE_UPLOAD_TIMEOUT', 300),

    /*
    |--------------------------------------------------------------------------
    | Platform defaults
    |--------------------------------------------------------------------------
    */

    'quality_threshold' => (float) env('QUALITY_THRESHOLD', 0.75),
    'max_upload_mb' => (int) env('MAX_UPLOAD_MB', 500),

    /*
    |--------------------------------------------------------------------------
    | Accepted upload extensions and dataset types
    |--------------------------------------------------------------------------
    */

    'allowed_extensions' => ['csv', 'xlsx', 'xls', 'json', 'parquet', 'zip', 'txt'],
    'dataset_types' => ['sales', 'inventory', 'purchases', 'expenses', 'customers', 'generic'],

];
