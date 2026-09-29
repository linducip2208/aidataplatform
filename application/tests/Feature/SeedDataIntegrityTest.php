<?php

namespace Tests\Feature;

use App\Enums\DatasetStatus;
use App\Enums\QualityVerdict;
use App\Models\AuditLog;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Dataset;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Guards the demo seeders against the two ways they rot:
 *
 *  1. a seeder writes a column the schema no longer has, or a key that is not in
 *     the model's `$fillable` -- the write is dropped silently and the demo page
 *     renders blanks/zeros;
 *  2. `db:seed` grows the database instead of updating it -- compose runs it on
 *     every deploy, so a second run must not duplicate the demo rows.
 *
 * The schema-drift check is snapshot-based on purpose: the seeders' own write set
 * is captured from the models as they are persisted, so this test does not have to
 * restate the seeder definitions and cannot drift in step with them.
 */
class SeedDataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Table => columns the seeders actually wrote, captured while seeding.
     *
     * @var array<string, array<string, true>>
     */
    private static array $written = [];

    private static bool $listenersRegistered = false;

    /**
     * @var array<class-string<Model>, string>
     */
    private const SEEDED_TABLES = [
        Dataset::class => 'datasets',
        ChatThread::class => 'chat_threads',
        ChatMessage::class => 'chat_messages',
        User::class => 'users',
        AuditLog::class => 'audit_logs',
    ];

    private const COUNTED_TABLES = [
        'users',
        'datasets',
        'chat_threads',
        'chat_messages',
        'audit_logs',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        self::$written = [];
        $this->recordWrittenColumns();

        $this->seedDatabase();
    }

    public function test_every_column_the_seeders_write_exists_in_the_schema(): void
    {
        $this->assertNotEmpty(self::$written, 'No seeder writes were captured; the test is not observing anything.');

        foreach (self::$written as $table => $columns) {
            $schema = array_map(strtolower(...), Schema::getColumnListing($table));

            foreach (array_keys($columns) as $column) {
                $this->assertContains(
                    strtolower($column),
                    $schema,
                    "The seeders write `{$table}.{$column}`, which does not exist in the schema.",
                );
            }
        }
    }

    public function test_the_dataset_fillable_list_covers_every_column_the_seeder_writes(): void
    {
        // `db:seed` runs the whole seeder inside `Model::unguarded()`
        // (Illuminate\Database\Console\Seeds\SeedCommand:70), so `$fillable`
        // cannot drop an attribute here -- a narrowed list fails silently in the
        // *seeders* rather than raising, which is exactly why the coverage has to
        // be asserted as a property instead of relied on at runtime.
        //
        // `Dataset::$fillable` is documented as "every column the application
        // manages"; this asserts that contract against what the seeder really
        // writes, so shrinking the list breaks CI instead of the demo.
        $fillable = (new Dataset)->getFillable();

        $missing = array_diff(array_keys(self::$written['datasets'] ?? []), $fillable);

        $this->assertSame(
            [],
            array_values($missing),
            'Dataset::$fillable no longer covers: '.implode(', ', $missing)
        );
    }

    public function test_the_seeded_datasets_match_the_ownership_split(): void
    {
        // Laravel owns users/datasets/chat_threads/chat_messages/audit_logs and
        // nothing else. If a seeder started writing an engine-owned table, the two
        // migration sets would collide on the same Postgres.
        $engineTables = [
            'raw_uploads', 'import_jobs', 'staging_tables', 'mapping_templates',
            'dim_customer', 'dim_product', 'dim_branch', 'dim_supplier', 'dim_warehouse',
            'dim_date', 'dim_department', 'fact_sales', 'fact_inventory', 'fact_purchases',
            'fact_expenses', 'ml_models', 'model_versions', 'training_runs', 'prediction_runs',
            'ai_conversations', 'ai_messages', 'rag_documents', 'rag_chunks',
            'alert_rules', 'alerts', 'alert_events',
        ];

        foreach ($engineTables as $table) {
            $this->assertArrayNotHasKey(
                $table,
                self::$written,
                "The Laravel seeders must not write `{$table}`; Alembic owns that table.",
            );
        }
    }

    public function test_every_seeded_dataset_declares_a_column_count_that_matches_its_columns(): void
    {
        $datasets = Dataset::orderBy('id')->get();

        $this->assertCount(6, $datasets, 'The demo is documented as six datasets.');

        foreach ($datasets as $dataset) {
            $columns = $dataset->columns;

            $this->assertIsArray($columns, "[{$dataset->uuid}] columns did not survive the array cast.");
            $this->assertNotEmpty($columns, "[{$dataset->uuid}] has no columns, so the dataset page renders an empty preview.");
            $this->assertSame(
                count($columns),
                $dataset->column_count,
                "[{$dataset->uuid}] column_count does not match the number of columns.",
            );

            $names = $dataset->columnNames();
            $this->assertSame($names, array_values(array_unique($names)), "[{$dataset->uuid}] has duplicate column names.");
            $this->assertGreaterThan(0, $dataset->row_count, "[{$dataset->uuid}] renders 0 rows.");
            $this->assertGreaterThan(0, $dataset->size_bytes, "[{$dataset->uuid}] renders a 0 B file size.");
        }
    }

    public function test_seeded_mappings_only_reference_declared_column_names(): void
    {
        foreach (Dataset::orderBy('id')->get() as $dataset) {
            $mappings = $dataset->mappings;

            $this->assertIsArray($mappings, "[{$dataset->uuid}] mappings did not survive the array cast.");
            $this->assertNotEmpty($mappings, "[{$dataset->uuid}] has no mappings, so the mapping editor renders empty.");

            $unknown = array_diff(array_keys($mappings), $dataset->columnNames());

            $this->assertSame(
                [],
                array_values($unknown),
                "[{$dataset->uuid}] maps columns that do not exist: ".implode(', ', $unknown),
            );

            foreach ($mappings as $source => $target) {
                $this->assertIsString($target, "[{$dataset->uuid}] maps `{$source}` to a non-string target.");
                $this->assertNotSame('', $target, "[{$dataset->uuid}] maps `{$source}` to an empty target.");
            }
        }
    }

    public function test_the_quality_verdict_agrees_with_the_score_against_the_configured_threshold(): void
    {
        $threshold = (float) config('ai_engine.quality_threshold');
        $this->assertGreaterThan(0.0, $threshold, 'A quality threshold of 0 would make the verdict meaningless.');

        $verdicts = [];

        foreach (Dataset::orderBy('id')->get() as $dataset) {
            $score = $dataset->quality_score;

            if ($score === null) {
                $this->assertNull(
                    $dataset->quality_verdict,
                    "[{$dataset->uuid}] has no quality score but carries the verdict `{$dataset->quality_verdict}`.",
                );
                $this->assertNull($dataset->quality_checked_at, "[{$dataset->uuid}] has no score but was quality checked.");

                continue;
            }

            $expected = $score >= $threshold ? QualityVerdict::Pass : QualityVerdict::Quarantine;

            $this->assertSame(
                $expected->value,
                $dataset->quality_verdict,
                "[{$dataset->uuid}] scores {$score} against a threshold of {$threshold}, so the verdict must be `{$expected->value}`, not `{$dataset->quality_verdict}`.",
            );
            $this->assertNotNull($dataset->quality_checked_at, "[{$dataset->uuid}] is scored but has no quality_checked_at.");

            $verdicts[] = $dataset->quality_verdict;
        }

        // Otherwise the demo never exercises the failing branch of the quality page.
        $this->assertContains(QualityVerdict::Pass->value, $verdicts, 'No seeded dataset passes the quality gate.');
        $this->assertContains(QualityVerdict::Quarantine->value, $verdicts, 'No seeded dataset is quarantined.');
    }

    public function test_seeded_import_job_ids_are_plausible_engine_identifiers(): void
    {
        $ids = [];

        foreach (Dataset::orderBy('id')->get() as $dataset) {
            $jobId = $dataset->import_job_id;

            $this->assertIsInt($jobId, "[{$dataset->uuid}] import_job_id must cast to an integer.");
            $this->assertGreaterThan(0, $jobId, "[{$dataset->uuid}] import_job_id must be a positive engine id.");
            $this->assertLessThanOrEqual(PHP_INT_MAX, $jobId, "[{$dataset->uuid}] import_job_id overflows the column.");
            $this->assertNotContains($jobId, $ids, "[{$dataset->uuid}] reuses import_job_id {$jobId}.");

            $ids[] = $jobId;
        }

        // The engine reference must be a soft column: Laravel does not own
        // `import_jobs`, so there is deliberately no foreign key to satisfy.
        $this->assertCount(count($ids), array_unique($ids));
    }

    public function test_dataset_status_metadata_is_internally_consistent(): void
    {
        foreach (Dataset::orderBy('id')->get() as $dataset) {
            $status = $dataset->status();

            $raw = $dataset->getRawOriginal('status');

            $this->assertContains($raw, DatasetStatus::values(), "[{$dataset->uuid}] stores the unknown status `{$raw}`.");
            $this->assertSame($raw, $status->value, "[{$dataset->uuid}] status did not survive the enum cast.");

            $committed = $status === DatasetStatus::Committed;
            $this->assertSame(
                $committed,
                $dataset->committed_at !== null,
                "[{$dataset->uuid}] is {$status->value} but its committed_at is ".($dataset->committed_at === null ? 'null' : 'set').'.',
            );
            $this->assertSame(
                $committed,
                $status->isTerminal() && $status !== DatasetStatus::Quarantined && $status !== DatasetStatus::Failed,
                "[{$dataset->uuid}] is {$status->value} but scored as uncommitted.",
            );

            $metadata = $dataset->metadata;

            $this->assertIsArray($metadata, "[{$dataset->uuid}] metadata did not survive the array cast.");

            $sampleRows = $metadata['preview']['sample_rows'] ?? null;
            $this->assertIsArray($sampleRows, "[{$dataset->uuid}] has no metadata.preview.sample_rows.");
            $this->assertNotEmpty($sampleRows, "[{$dataset->uuid}] has an empty preview, so the dataset page renders no rows.");

            $names = $dataset->columnNames();

            foreach ($sampleRows as $index => $row) {
                $this->assertIsArray($row, "[{$dataset->uuid}] preview row {$index} is not an array.");
                $this->assertSame(
                    [],
                    array_values(array_diff(array_keys($row), $names)),
                    "[{$dataset->uuid}] preview row {$index} has keys that are not declared columns.",
                );
            }

            $report = $metadata['quality'] ?? null;

            if ($dataset->quality_score !== null) {
                $this->assertIsArray($report, "[{$dataset->uuid}] is scored but has no metadata.quality report.");
                $this->assertEquals($dataset->quality_score, $report['score'], "[{$dataset->uuid}] metadata.quality.score disagrees with quality_score.");
                $this->assertSame(
                    $dataset->quality_verdict === QualityVerdict::Pass->value,
                    (bool) $report['passed'],
                    "[{$dataset->uuid}] metadata.quality.passed disagrees with the verdict.",
                );

                foreach (['completeness', 'uniqueness', 'validity', 'consistency'] as $dimension) {
                    $this->assertArrayHasKey($dimension, $report['breakdown'] ?? [], "[{$dataset->uuid}] quality breakdown is missing `{$dimension}`.");
                    $this->assertGreaterThanOrEqual(0.0, $report['breakdown'][$dimension], "[{$dataset->uuid}] `{$dimension}` is negative.");
                    $this->assertLessThanOrEqual(1.0, $report['breakdown'][$dimension], "[{$dataset->uuid}] `{$dimension}` is above 1.");
                }
            }

            if ($status === DatasetStatus::Failed) {
                $this->assertIsString($metadata['error'] ?? null, "[{$dataset->uuid}] is failed but has no metadata.error to render.");
            }
        }
    }

    public function test_seeded_chat_threads_render_their_message_count_and_last_activity(): void
    {
        $threads = ChatThread::orderBy('id')->get();

        $this->assertCount(3, $threads, 'The demo is documented as three chat threads.');

        foreach ($threads as $thread) {
            $messages = $thread->messages()->orderBy('id')->get();

            $this->assertSame(
                $messages->count(),
                $thread->message_count,
                "Thread `{$thread->title}` shows a message_count of {$thread->message_count} but has {$messages->count()} messages.",
            );
            $this->assertNotEmpty($thread->title, 'A thread with an empty title renders a blank list row.');

            foreach ($messages as $index => $message) {
                $this->assertContains(
                    $message->role,
                    ['user', 'assistant'],
                    "Thread `{$thread->title}` message {$index} has the unknown role `{$message->role}`.",
                );
                $this->assertNotSame('', trim((string) $message->content), "Thread `{$thread->title}` message {$index} is empty.");

                if ($message->role === 'assistant') {
                    $this->assertIsArray($message->evidence, "Thread `{$thread->title}` message {$index} lost its evidence array.");
                    $this->assertNotEmpty($message->evidence, "Thread `{$thread->title}` message {$index} has no citations, so the answer renders without sources.");
                }
            }

            $this->assertSame(
                'assistant',
                $messages->last()?->role,
                "Thread `{$thread->title}` does not end on an assistant reply.",
            );

            $this->assertTrue(
                $thread->last_message_at->equalTo($messages->last()->created_at),
                "Thread `{$thread->title}` last_message_at does not match its newest message.",
            );

            $this->assertTrue(
                $thread->user->isAnalyst(),
                "Thread `{$thread->title}` belongs to a user who cannot open the assistant.",
            );

            // A soft reference to the engine's `ai_conversations` table, no FK.
            $this->assertIsInt($thread->ai_conversation_id);
            $this->assertGreaterThan(0, $thread->ai_conversation_id);
        }
    }

    public function test_seeded_audit_rows_are_attributed_to_the_demo_accounts(): void
    {
        $logs = AuditLog::orderBy('id')->get();

        $this->assertNotEmpty($logs, 'The audit page renders empty on a fresh install.');

        $emails = User::orderBy('id')->pluck('email');

        foreach ($logs as $log) {
            $this->assertNotSame('', trim($log->actor), 'An audit row with an empty actor cannot be rendered.');
            $this->assertContains($log->actor, $emails, "Audit row `{$log->action}` has the unknown actor `{$log->actor}`.");
            $this->assertNotNull($log->created_at, "Audit row `{$log->action}` has no created_at, so it sorts as the epoch.");
            $this->assertIsArray($log->detail, "Audit row `{$log->action}` lost its detail array.");
        }
    }

    public function test_the_demo_accounts_are_seeded_with_the_documented_credentials(): void
    {
        $users = User::orderBy('id')->get();

        $this->assertCount(3, $users);

        foreach ($users as $user) {
            $this->assertTrue($user->is_active, "{$user->email} is seeded inactive.");
            $this->assertNotNull($user->email_verified_at, "{$user->email} cannot log in while unverified.");
            $this->assertNotNull($user->last_login_at);
        }

        $this->assertSame('admin@example.com', $users[0]->email);
        $this->assertTrue($users[0]->isAdmin());
        $this->assertTrue($this->app->make('hash')->check('Admin123!', $users[0]->password));

        $this->assertSame('analyst@example.com', $users[1]->email);
        $this->assertTrue($users[1]->isAnalyst());

        $this->assertSame('viewer@example.com', $users[2]->email);
        $this->assertFalse($users[2]->canWrite());
    }

    public function test_running_db_seed_a_second_time_does_not_change_the_row_counts(): void
    {
        $before = $this->counts();
        $uuids = Dataset::orderBy('id')->pluck('uuid')->all();

        // A unique-constraint violation on any seeded column surfaces here as a
        // QueryException, so reaching the assertions is half of this test.
        $this->seedDatabase();

        $this->assertSame($before, $this->counts(), 'db:seed is not idempotent; compose runs it on every deploy.');
        $this->assertSame($uuids, Dataset::orderBy('id')->pluck('uuid')->all(), 'A second db:seed duplicated the demo datasets.');
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = [];

        foreach (self::COUNTED_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    private function seedDatabase(): void
    {
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * Snapshot the columns the seeders persist, straight off the models, so this
     * test never has to restate (and drift along with) the seeder definitions.
     */
    private function recordWrittenColumns(): void
    {
        if (self::$listenersRegistered) {
            return;
        }

        self::$listenersRegistered = true;

        foreach (self::SEEDED_TABLES as $model => $table) {
            $model::saving(function (Model $instance) use ($table): void {
                foreach (array_keys($instance->getAttributes()) as $column) {
                    self::$written[$table][$column] = true;
                }
            });
        }
    }
}
