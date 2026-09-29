<?php

namespace Tests\Unit;

use App\Enums\DatasetStatus;
use App\Enums\QualityVerdict;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Dataset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The `Dataset` model is a value object for half of its life (sizes, columns,
 * status) and a row for the other half (uuid generation, relations, cascade).
 * Both halves live here; `RefreshDatabase` is on the class because the second
 * half cannot be exercised without it.
 */
class DatasetModelTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // sizeForHumans()
    // ------------------------------------------------------------------

    public function test_size_for_humans_reports_zero_and_sub_kilobyte_files_in_bytes(): void
    {
        $this->assertSame('0 B', $this->sized(0)->sizeForHumans());
        $this->assertSame('1 B', $this->sized(1)->sizeForHumans());
        $this->assertSame('512 B', $this->sized(512)->sizeForHumans());
        $this->assertSame('1023 B', $this->sized(1023)->sizeForHumans());
    }

    public function test_size_for_humans_steps_up_exactly_at_each_1024_boundary(): void
    {
        $this->assertSame('1 KB', $this->sized(1024)->sizeForHumans());
        $this->assertSame('1 MB', $this->sized(1024 ** 2)->sizeForHumans());
        $this->assertSame('1 GB', $this->sized(1024 ** 3)->sizeForHumans());
        $this->assertSame('1 TB', $this->sized(1024 ** 4)->sizeForHumans());
        $this->assertSame('1 PB', $this->sized(1024 ** 5)->sizeForHumans());
    }

    public function test_size_for_humans_rounds_to_one_decimal_and_keeps_the_unit(): void
    {
        $this->assertSame('1.5 KB', $this->sized(1536)->sizeForHumans());
        $this->assertSame('2.5 MB', $this->sized((int) (2.5 * 1024 ** 2))->sizeForHumans());
        $this->assertSame('1.5 GB', $this->sized((int) (1.5 * 1024 ** 3))->sizeForHumans());
    }

    public function test_size_for_humans_treats_a_null_size_as_zero_and_a_numeric_string_as_bytes(): void
    {
        // A dataset row that has never been sized reads as "0 B", not "- B".
        $this->assertSame('0 B', (new Dataset)->sizeForHumans());
        $this->assertSame('2 KB', $this->sized('2048')->sizeForHumans());
    }

    public function test_size_for_humans_keeps_counting_in_petabytes_past_the_last_declared_unit(): void
    {
        // The unit loop ends at TB, so anything larger falls through to the PB
        // branch and is reported in petabytes, not in exabytes.
        $this->assertSame('1024 PB', $this->sized(1024 ** 6)->sizeForHumans());
    }

    // ------------------------------------------------------------------
    // columnNames()
    // ------------------------------------------------------------------

    public function test_column_names_reads_the_array_of_objects_shape_written_by_the_previewer(): void
    {
        $dataset = new Dataset(['columns' => [
            ['name' => 'tanggal', 'dtype' => 'date'],
            ['name' => 'kode_pelanggan', 'dtype' => 'string'],
        ]]);

        $this->assertSame(['tanggal', 'kode_pelanggan'], $dataset->columnNames());
    }

    public function test_column_names_reads_the_flat_strings_shape(): void
    {
        $dataset = new Dataset(['columns' => ['id', 'label', 'nilai']]);

        $this->assertSame(['id', 'label', 'nilai'], $dataset->columnNames());
    }

    public function test_column_names_keeps_the_usable_entries_of_a_mixed_array_and_skips_the_rest(): void
    {
        $dataset = new Dataset(['columns' => [
            ['name' => 'tanggal'],
            'kode_cabang',
            ['dtype' => 'float'],   // no `name` key: not a column descriptor
            42,                     // neither array nor string
            null,
            ['name' => 7],          // a numeric name is still a name
        ]]);

        $this->assertSame(['tanggal', 'kode_cabang', '7'], $dataset->columnNames());
    }

    public function test_column_names_are_empty_when_the_column_profile_is_absent(): void
    {
        $this->assertSame([], (new Dataset)->columnNames());
        $this->assertSame([], (new Dataset(['columns' => null]))->columnNames());
        $this->assertSame([], (new Dataset(['columns' => []]))->columnNames());
    }

    // ------------------------------------------------------------------
    // route key
    // ------------------------------------------------------------------

    public function test_datasets_are_addressed_by_uuid(): void
    {
        $this->assertSame('uuid', (new Dataset)->getRouteKeyName());

        $dataset = Dataset::factory()->create();
        $this->assertSame($dataset->uuid, $dataset->getRouteKey());
    }

    public function test_uuid_auto_fills_on_create_and_never_collides(): void
    {
        $first = Dataset::factory()->create(['uuid' => null]);
        $second = Dataset::factory()->create(['uuid' => null]);

        $this->assertNotNull($first->uuid);
        $this->assertNotNull($second->uuid);
        $this->assertNotSame($first->uuid, $second->uuid);
        $this->assertTrue(Str::isUuid((string) $first->uuid));

        // The generated value is persisted, not just set on the instance.
        $this->assertSame($first->uuid, Dataset::query()->findOrFail($first->getKey())->uuid);
    }

    public function test_an_explicitly_supplied_uuid_is_not_overwritten(): void
    {
        $uuid = (string) Str::uuid();

        $dataset = Dataset::factory()->create(['uuid' => $uuid]);

        $this->assertSame($uuid, $dataset->uuid);
    }

    // ------------------------------------------------------------------
    // status() / qualityVerdict()
    // ------------------------------------------------------------------

    public function test_status_resolves_a_stored_value_to_its_enum_case(): void
    {
        $this->assertSame(DatasetStatus::Committed, (new Dataset(['status' => 'committed']))->status());
        $this->assertSame(DatasetStatus::Quarantined, (new Dataset(['status' => 'quarantined']))->status());
    }

    public function test_status_falls_back_to_uploaded_when_nothing_is_stored(): void
    {
        $this->assertSame(DatasetStatus::Uploaded, (new Dataset)->status());
        $this->assertSame(DatasetStatus::Uploaded, DatasetStatus::tryFromName(null));
    }

    public function test_status_does_not_actually_fall_back_for_an_unknown_stored_value(): void
    {
        // Known defect, not a contract: `casts()` at app/Models/Dataset.php:62
        // casts `status` to DatasetStatus, so reading an unknown stored value
        // raises a ValueError inside the attribute cast and the
        // `DatasetStatus::tryFromName()` fallback at app/Models/Dataset.php:98
        // is never reached. This assertion pins the current behaviour; it will
        // start failing the moment the cast is fixed.
        $this->expectException(\ValueError::class);

        (new Dataset(['status' => 'archived']))->status();
    }

    public function test_quality_verdict_is_null_when_nothing_was_stored(): void
    {
        $this->assertNull((new Dataset)->qualityVerdict());
        $this->assertNull((new Dataset(['quality_verdict' => null]))->qualityVerdict());
    }

    public function test_quality_verdict_resolves_every_known_value(): void
    {
        $this->assertSame(QualityVerdict::Pass, (new Dataset(['quality_verdict' => 'pass']))->qualityVerdict());
        $this->assertSame(QualityVerdict::Quarantine, (new Dataset(['quality_verdict' => 'quarantine']))->qualityVerdict());
    }

    public function test_quality_verdict_is_null_for_an_unknown_stored_value(): void
    {
        // `quality_verdict` is not cast, so unlike `status` it really does
        // degrade to null instead of raising.
        $this->assertNull((new Dataset(['quality_verdict' => 'inconclusive']))->qualityVerdict());
    }

    // ------------------------------------------------------------------
    // quality_score: 0 is a score, not a missing score
    // ------------------------------------------------------------------

    public function test_a_quality_score_of_zero_is_not_read_back_as_null(): void
    {
        $zero = new Dataset(['quality_score' => 0]);
        $missing = new Dataset(['quality_score' => null]);

        $this->assertSame(0.0, $zero->quality_score);
        $this->assertNotNull($zero->quality_score);
        $this->assertNull($missing->quality_score);
    }

    public function test_a_stored_quality_score_of_zero_survives_a_database_round_trip(): void
    {
        $scored = Dataset::factory()->create(['quality_score' => 0]);
        $unscored = Dataset::factory()->create(['quality_score' => null]);

        $this->assertNotNull($scored->fresh()?->quality_score);
        $this->assertSame(0.0, $scored->fresh()?->quality_score);
        $this->assertNull($unscored->fresh()?->quality_score);
    }

    // ------------------------------------------------------------------
    // relations and cascade behaviour
    // ------------------------------------------------------------------

    public function test_the_model_relations_resolve_both_ways(): void
    {
        $user = User::factory()->create();
        $dataset = Dataset::factory()->for($user)->create();
        $thread = ChatThread::factory()->for($user)->create();
        $message = ChatMessage::factory()->for($thread, 'thread')->fromAssistant()->create();

        $this->assertTrue($user->datasets->contains($dataset));
        $this->assertTrue($user->chatThreads->contains($thread));
        $this->assertTrue($dataset->user->is($user));
        $this->assertTrue($thread->user->is($user));
        $this->assertTrue($thread->messages->contains($message));
        $this->assertTrue($message->thread->is($thread));
    }

    public function test_deleting_a_user_keeps_their_datasets_and_orphans_them(): void
    {
        $user = User::factory()->create();
        $dataset = Dataset::factory()->for($user)->create();

        $user->delete();

        // datasets.user_id is `nullOnDelete`: the record of what was imported
        // must outlive the analyst who imported it, so the row survives and is
        // orphaned rather than cascading away.
        $survivor = Dataset::query()->find($dataset->getKey());

        $this->assertNotNull($survivor);
        $this->assertSame($dataset->uuid, $survivor->uuid);
        $this->assertNull($survivor->user_id);
        $this->assertNull($survivor->user);
    }

    public function test_deleting_a_user_cascades_to_their_chat_threads_and_messages(): void
    {
        $user = User::factory()->create();
        $thread = ChatThread::factory()->for($user)->create();
        $message = ChatMessage::factory()->for($thread, 'thread')->fromAssistant()->create();

        $user->delete();

        $this->assertNull(ChatThread::query()->find($thread->getKey()));
        $this->assertNull(ChatMessage::query()->find($message->getKey()));
    }

    public function test_deleting_a_chat_thread_cascades_to_its_messages(): void
    {
        $thread = ChatThread::factory()->create();
        ChatMessage::factory()->count(2)->for($thread, 'thread')->create();

        $this->assertSame(2, ChatMessage::query()->count());

        $thread->delete();

        $this->assertNull(ChatThread::query()->find($thread->getKey()));
        $this->assertSame(0, ChatMessage::query()->count());
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function sized(int|string|null $bytes): Dataset
    {
        return new Dataset(['size_bytes' => $bytes]);
    }
}
