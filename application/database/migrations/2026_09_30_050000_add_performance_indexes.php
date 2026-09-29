<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index name => columns, each one tied to a query that exists in the code today.
     *
     * All seven are ordinary btrees over core tables, so they are valid DDL on
     * both the Postgres production database and the sqlite database the test
     * suite and CI run against. Nothing Postgres-only belongs here -- the
     * engine's own tables are owned by Alembic, and anything pgvector- or
     * Postgres-specific belongs in ai-engine/alembic/versions/.
     *
     * Coverage that already exists and is NOT repeated here:
     *   datasets.uuid (unique, 000200) -- RunImportJob:42,96, RefreshQualityScoreJob:35,
     *     SyncQualityCommand:226, SyncImportStatusCommand:214, QualityService:108
     *     (all `where('uuid', ...)` route-model binding lookups).
     *   datasets.status single-column (000200) -- DashboardController:19-21 counts.
     *   datasets.import_job_id single-column (000200) -- SyncImportStatusCommand:242
     *     `whereNotNull('import_job_id')` (a NOT NULL predicate, not an equality,
     *     so it cannot lead a composite; the pending-query ordering is covered
     *     by datasets_status_updated_at_index below).
     *   datasets(user_id, created_at) (000200) -- AssistantController thread scoping
     *     is on chat_threads, not datasets; the datasets user scoping is covered.
     *   audit_logs(action) + audit_logs(resource, resource_id) + audit_logs(created_at)
     *     (000400) -- single-column filters; the filter+sort combinations below
     *     are what is missing.
     *   chat_threads(user_id, last_message_at) + chat_threads(ai_conversation_id)
     *     (000300) -- AssistantController:22-24 sidebar, AgentController ownership
     *     single-column side.
     *   chat_messages(chat_thread_id, created_at) (000300) -- AssistantController:39-41
     *     transcript ordering without the id tiebreaker.
     *
     * @var array<string, array{table: string, columns: array<int, string}}>
     */
    private const INDEXES = [
        // DatasetController:21-31 + Api\DatasetController:26-41 filter by
        // `status` and/or `dataset_type` (`scopeOfType`/`scopeWithStatus`,
        // Dataset:118,124) then `ORDER BY created_at DESC` with pagination.
        // The single-column status/dataset_type indexes from 000200 filter but
        // cannot serve the sort; the composite serves filter + sort together.
        // QueryCountTest 'datasets.index'/'api.datasets.index' pin the page at
        // COUNT + SELECT, so an index-backed sort is what keeps that budget.
        'datasets_status_created_at_index' => [
            'table' => 'datasets',
            'columns' => ['status', 'created_at'],
        ],
        'datasets_dataset_type_created_at_index' => [
            'table' => 'datasets',
            'columns' => ['dataset_type', 'created_at'],
        ],

        // SyncImportStatusCommand:241-244 `whereNotNull(import_job_id)`
        // + `whereIn(status, open)` + `ORDER BY updated_at, id LIMIT`.
        // `whereNotNull` is not an equality so import_job_id cannot lead; status
        // leads (the IN list), updated_at second serves the oldest-first order.
        // The single-column updated_at index from 2026_09_29 cannot serve the
        // filtered order.
        'datasets_status_updated_at_index' => [
            'table' => 'datasets',
            'columns' => ['status', 'updated_at'],
        ],

        // SyncQualityCommand:254 `where(status, committed)` + stale window
        // (`whereNull quality_score / quality_checked_at OR checked_at < cutoff`,
        // :262-266) + `ORDER BY quality_checked_at, id`. The OR across two
        // nullable columns cannot all lead one btree, so status leads and
        // quality_checked_at second serves the oldest-first sweep. Complements
        // (not replaces) the verdict+checked_at composite from 2026_09_29,
        // which serves the quality-index page filter instead.
        'datasets_status_quality_checked_at_index' => [
            'table' => 'datasets',
            'columns' => ['status', 'quality_checked_at'],
        ],

        // Admin\AuditLogController:15-27 `where(action, ?)` (optional) +
        // `ORDER BY created_at DESC, id DESC` paginate, plus the
        // `->where('action', ...)` assertions in DatasetLifecycleTest:930,940.
        // Single-column action (000400) filters but leaves the sort over the
        // matching set; the composite serves filter + sort. QueryCountTest
        // 'audit.index' (paginated log + DISTINCT catalogue) pins this page.
        'audit_logs_action_created_at_index' => [
            'table' => 'audit_logs',
            'columns' => ['action', 'created_at'],
        ],

        // Api\AgentController:35-37 ownership check
        // `where(ai_conversation_id, ?) + where(user_id, ?)` on chat_threads.
        // Each side has a single-column index (000300 + FK), but the two-column
        // equality needs the composite to avoid a bitmap/intersect scan.
        'chat_threads_user_ai_conversation_index' => [
            'table' => 'chat_threads',
            'columns' => ['user_id', 'ai_conversation_id'],
        ],

        // AssistantController:39-44 transcript
        // `$thread->messages()->orderByDesc(created_at)->orderByDesc(id)->limit(100)`.
        // The existing (chat_thread_id, created_at) from 000300 orders by time
        // but not by the id tiebreaker; the three-column form serves the exact
        // ORDER BY pair. QueryCountTest 'assistant.index' (sidebar + one
        // transcript, bounded LIMITs) pins this page.
        'chat_messages_thread_created_id_index' => [
            'table' => 'chat_messages',
            'columns' => ['chat_thread_id', 'created_at', 'id'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $spec) {
            if (! Schema::hasTable($spec['table'])) {
                continue;
            }

            if (Schema::hasIndex($spec['table'], $name)) {
                continue;
            }

            Schema::table($spec['table'], function (Blueprint $table) use ($spec, $name) {
                $table->index($spec['columns'], $name);
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $name => $spec) {
            if (! Schema::hasTable($spec['table'])) {
                continue;
            }

            if (! Schema::hasIndex($spec['table'], $name)) {
                continue;
            }

            Schema::table($spec['table'], function (Blueprint $table) use ($name) {
                $table->dropIndex($name);
            });
        }
    }
};
