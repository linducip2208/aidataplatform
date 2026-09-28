<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index name => columns, each one tied to a query that exists in the code today.
     *
     * These three are ordinary btrees over `datasets`, so they are valid DDL on
     * both the Postgres production database and the sqlite database the test
     * suite and CI run against. Nothing Postgres-only belongs here -- the
     * engine's own tables are owned by Alembic, and anything pgvector- or
     * Postgres-specific belongs in ai-engine/alembic/versions/0002.
     *
     * @var array<string, array<int, string>>
     */
    private const INDEXES = [
        // QualityController:20 + :22 -- `WHERE quality_verdict = ?` then
        // `ORDER BY quality_checked_at DESC`, plus the two `->count()` calls on
        // `quality_verdict` at QualityController:27-28. One composite serves
        // the filter and the sort; two single-column indexes would still leave
        // the sort over the whole matching set. The leading column is nullable
        // in practice, and a btree indexes NULLs like any other value, so the
        // "not yet scored" rows are indexed too rather than excluded.
        'datasets_quality_verdict_quality_checked_at_index' => ['quality_verdict', 'quality_checked_at'],

        // DatasetController:30, Api\DatasetController:37 and DashboardController:42
        // all run `ORDER BY created_at DESC LIMIT ...` with no other predicate,
        // which sorts the whole table on every page. (user_id, created_at) from
        // 2026_09_28_000200 cannot serve these: user_id is not in the predicate,
        // so only its second column is usable and the sort is still unindexed.
        'datasets_created_at_index' => ['created_at'],

        // ImportController:19 and SyncImportStatusCommand:221 run
        // `ORDER BY updated_at`. Same reasoning: `whereNotNull('import_job_id')`
        // is not an equality, so the import_job_id index from 000200 does not
        // order the result.
        'datasets_updated_at_index' => ['updated_at'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('datasets')) {
            return;
        }

        foreach (self::INDEXES as $name => $columns) {
            if (Schema::hasIndex('datasets', $name)) {
                continue;
            }

            Schema::table('datasets', function (Blueprint $table) use ($columns, $name) {
                $table->index($columns, $name);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('datasets')) {
            return;
        }

        foreach (array_keys(self::INDEXES) as $name) {
            if (! Schema::hasIndex('datasets', $name)) {
                continue;
            }

            Schema::table('datasets', function (Blueprint $table) use ($name) {
                $table->dropIndex($name);
            });
        }
    }
};
