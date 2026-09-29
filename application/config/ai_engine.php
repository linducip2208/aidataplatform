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
    | Monthly AI spend budget (USD)
    |--------------------------------------------------------------------------
    |
    | Fail-closed ceiling for the expensive endpoints (agent chat, RAG query,
    | model training). 0 or negative disables enforcement. When the ledgered
    | month-to-date estimated cost reaches the budget, those endpoints answer
    | 429 `budget_exceeded` until the next month. Checked against a 5-minute
    | cached engine summary; an unreachable engine fails open (the incident
    | must not cascade into a full AI outage) and is logged.
    |
    */

    'ai_monthly_budget_usd' => (float) env('AI_MONTHLY_BUDGET_USD', 0),

    /*
    |--------------------------------------------------------------------------
    | API token lifetime (days)
    |--------------------------------------------------------------------------
    */

    'token_ttl_days' => (int) env('API_TOKEN_TTL_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Accepted upload extensions and dataset types
    |--------------------------------------------------------------------------
    */

    'allowed_extensions' => ['csv', 'xlsx', 'xls', 'json', 'parquet', 'zip', 'txt'],
    // Mirrors the branches `ai-engine/app/ingestion/etl.py` actually dispatches.
    // `generic` was offered here but has no ETL branch — the engine raises on
    // it — and `products` was supported by the engine but missing here, so both
    // lists disagreed with the other side.
    'dataset_types' => ['sales', 'inventory', 'purchases', 'expenses', 'customers', 'products'],

    /*
    |--------------------------------------------------------------------------
    | Wording for the engine vocabulary
    |--------------------------------------------------------------------------
    |
    | The engine answers in machine keys: `sales`, `total_cogs`, `PRODUCTION`.
    | These maps are the single place where a key becomes something a user
    | reads, so no Blade template carries its own copy of the dictionary.
    | Every key here must exist in the vocabulary it labels; a missing entry
    | falls back to the raw key rather than rendering an empty label.
    |
    */

    'dataset_type_labels' => [
        'sales' => 'Penjualan',
        'inventory' => 'Inventori',
        'purchases' => 'Pembelian',
        'expenses' => 'Beban',
        'customers' => 'Pelanggan',
        'products' => 'Produk',
    ],

    'model_type_labels' => [
        'forecast' => 'Prakiraan',
        'churn' => 'Perpindahan pelanggan',
        'segmentation' => 'Segmentasi',
        'anomaly' => 'Anomali',
        'recommend' => 'Rekomendasi',
    ],

    // Mirrors the statuses in `ai-engine/app/ml/registry.py` plus the STAGED
    // target that `MlController::promote()` accepts.
    'model_version_statuses' => [
        'DRAFT' => ['label' => 'Draf', 'badge' => 'badge-neutral'],
        'TRAINING' => ['label' => 'Sedang dilatih', 'badge' => 'badge-warning'],
        'VALIDATED' => ['label' => 'Tervalidasi', 'badge' => 'badge-info'],
        'STAGED' => ['label' => 'Disiapkan', 'badge' => 'badge-info'],
        'PRODUCTION' => ['label' => 'Produksi', 'badge' => 'badge-success'],
        'ARCHIVED' => ['label' => 'Diarsipkan', 'badge' => 'badge-neutral'],
        'FAILED' => ['label' => 'Gagal', 'badge' => 'badge-danger'],
    ],

    // The report period and the analytics granularity are the same vocabulary.
    'period_labels' => [
        'daily' => 'Harian',
        'weekly' => 'Mingguan',
        'monthly' => 'Bulanan',
    ],

    // The finance envelope of `ai-engine/app/analytics/finance.py`.
    'finance_labels' => [
        'total_revenue' => 'Total pendapatan',
        'total_cogs' => 'Total harga pokok penjualan',
        'total_expenses' => 'Total beban',
        'gross_profit' => 'Laba kotor',
        'net_profit' => 'Laba bersih',
        'margin_pct' => 'Margin',
    ],

    /*
    | The import job endpoint answers with a free-form status string, so the
    | spellings it actually uses are listed here. Anything unlisted falls back
    | to a neutral badge instead of printing the raw token to the user.
    */

    'import_job_statuses' => [
        'succeeded' => ['label' => 'Selesai', 'badge' => 'badge-success'],
        'success' => ['label' => 'Selesai', 'badge' => 'badge-success'],
        'completed' => ['label' => 'Selesai', 'badge' => 'badge-success'],
        'done' => ['label' => 'Selesai', 'badge' => 'badge-success'],
        'failed' => ['label' => 'Gagal', 'badge' => 'badge-danger'],
        'error' => ['label' => 'Gagal', 'badge' => 'badge-danger'],
        'queued' => ['label' => 'Antre', 'badge' => 'badge-info'],
        'pending' => ['label' => 'Antre', 'badge' => 'badge-info'],
        'running' => ['label' => 'Diproses', 'badge' => 'badge-warning'],
        'processing' => ['label' => 'Diproses', 'badge' => 'badge-warning'],
    ],

    // The report block of an import job, per `ai-engine/app/ingestion/etl.py`.
    'import_job_report_labels' => [
        'import_job_id' => 'ID job impor',
        'dataset_type' => 'Tipe dataset',
        'total_rows' => 'Total baris',
        'processed_rows' => 'Baris diproses',
        'error_rows' => 'Baris bermasalah',
        'quality' => 'Kualitas',
        'error_log' => 'Catatan galat',
        'stage' => 'Tahap',
        'status' => 'Status',
        'progress' => 'Progres',
        'error' => 'Galat',
    ],

];
