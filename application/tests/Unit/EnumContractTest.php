<?php

namespace Tests\Unit;

use App\Enums\DatasetStatus;
use App\Enums\QualityVerdict;
use App\Enums\UserRole;
use Tests\TestCase;

/**
 * The enums are the vocabulary shared by the database, the API and the views,
 * so they are tested as a contract: every case must be reachable, labelled,
 * terminal where it should be, and — for the badge classes — backed by a rule
 * that actually exists in the stylesheet.
 */
class EnumContractTest extends TestCase
{
    /**
     * The expected terminal flags are written out by hand on purpose. Deriving
     * them from `isTerminal()` itself would assert nothing.
     */
    private const TERMINAL = [
        'uploaded' => false,
        'previewing' => false,
        'mapped' => false,
        'importing' => false,
        'committed' => true,
        'quarantined' => true,
        'failed' => true,
    ];

    public function test_is_terminal_is_true_exactly_for_the_finished_states(): void
    {
        $this->assertCount(
            count(self::TERMINAL),
            DatasetStatus::cases(),
            'A DatasetStatus case was added without a terminal expectation here.',
        );

        foreach (DatasetStatus::cases() as $case) {
            $this->assertSame(
                self::TERMINAL[$case->value],
                $case->isTerminal(),
                "DatasetStatus::{$case->value} has the wrong isTerminal() result.",
            );
        }
    }

    public function test_every_status_badge_class_is_declared_in_the_stylesheet(): void
    {
        $this->assertBadgeClassIsStyled(DatasetStatus::Committed->badgeClass());
        $this->assertBadgeClassIsStyled(DatasetStatus::Quarantined->badgeClass());
        $this->assertBadgeClassIsStyled(DatasetStatus::Failed->badgeClass());
        $this->assertBadgeClassIsStyled(DatasetStatus::Importing->badgeClass());
        $this->assertBadgeClassIsStyled(DatasetStatus::Previewing->badgeClass());
        $this->assertBadgeClassIsStyled(DatasetStatus::Uploaded->badgeClass());
        $this->assertBadgeClassIsStyled(DatasetStatus::Mapped->badgeClass());
    }

    public function test_every_quality_verdict_badge_class_is_declared_in_the_stylesheet(): void
    {
        foreach (QualityVerdict::cases() as $case) {
            $this->assertBadgeClassIsStyled($case->badgeClass());
        }
    }

    public function test_the_stylesheet_lookup_is_not_vacuous(): void
    {
        // Guards the guard: if `cssDeclares()` matched anything, a badge
        // class that was deleted from app.css would still pass the tests above.
        $this->assertFalse($this->cssDeclares('badge-teal'));
        $this->assertFalse($this->cssDeclares('badge-successful'));
        $this->assertTrue($this->cssDeclares('badge-success'));
    }

    public function test_a_status_badge_only_uses_one_of_the_five_declared_badges(): void
    {
        foreach (DatasetStatus::cases() as $case) {
            $this->assertStringStartsWith('badge-', $case->badgeClass());
            $this->assertMatchesRegularExpression(
                '/^badge-(success|danger|warning|info|neutral)$/',
                $case->badgeClass(),
                "DatasetStatus::{$case->value} maps to a badge outside the five the stylesheet defines.",
            );
        }
    }

    public function test_every_status_case_has_a_non_empty_label_and_a_unique_value(): void
    {
        $labels = [];
        $values = [];

        foreach (DatasetStatus::cases() as $case) {
            $this->assertNotSame('', $case->label());
            $this->assertSame(ucfirst($case->value), $case->label());

            $labels[] = $case->label();
            $values[] = $case->value;
        }

        $this->assertSame($values, array_unique($values));
        $this->assertSame($labels, array_unique($labels));
    }

    public function test_dataset_status_values_lists_every_case_in_declaration_order(): void
    {
        $this->assertSame(
            ['uploaded', 'previewing', 'mapped', 'importing', 'committed', 'quarantined', 'failed'],
            DatasetStatus::values(),
        );
    }

    public function test_dataset_status_try_from_name_falls_back_to_uploaded_for_junk_values(): void
    {
        $this->assertSame(DatasetStatus::Uploaded, DatasetStatus::tryFromName(null));
        $this->assertSame(DatasetStatus::Uploaded, DatasetStatus::tryFromName(''));
        $this->assertSame(DatasetStatus::Uploaded, DatasetStatus::tryFromName('archived'));
        $this->assertSame(DatasetStatus::Uploaded, DatasetStatus::tryFromName('Committed'));
    }

    public function test_dataset_status_try_from_name_resolves_every_known_value(): void
    {
        foreach (DatasetStatus::cases() as $case) {
            $this->assertSame($case, DatasetStatus::tryFromName($case->value));
        }
    }

    public function test_user_role_try_from_name_falls_back_to_viewer_for_a_null_value(): void
    {
        $this->assertSame(UserRole::Viewer, UserRole::tryFromName(null));
    }

    public function test_user_role_try_from_name_falls_back_to_viewer_for_an_empty_value(): void
    {
        $this->assertSame(UserRole::Viewer, UserRole::tryFromName(''));
    }

    public function test_user_role_try_from_name_is_case_sensitive_and_never_promotes_anyone(): void
    {
        // A capitalised or padded role string is a mistake, not an admin, and
        // the safe-reading (viewer) is what the fallback must produce.
        $this->assertSame(UserRole::Viewer, UserRole::tryFromName('Admin'));
        $this->assertSame(UserRole::Viewer, UserRole::tryFromName('ADMIN'));
        $this->assertSame(UserRole::Viewer, UserRole::tryFromName(' admin '));
        $this->assertSame(UserRole::Viewer, UserRole::tryFromName('administrator'));
    }

    public function test_user_role_try_from_name_resolves_every_valid_value(): void
    {
        $this->assertSame(UserRole::Admin, UserRole::tryFromName('admin'));
        $this->assertSame(UserRole::Analyst, UserRole::tryFromName('analyst'));
        $this->assertSame(UserRole::Viewer, UserRole::tryFromName('viewer'));

        foreach (UserRole::cases() as $case) {
            $this->assertSame($case, UserRole::tryFromName($case->value));
        }
    }

    public function test_every_role_case_has_a_non_empty_label_and_description(): void
    {
        $descriptions = [];

        foreach (UserRole::cases() as $case) {
            $this->assertNotSame('', $case->label());
            $this->assertNotSame('', $case->description());
            $this->assertGreaterThan(20, strlen($case->description()));

            $descriptions[] = $case->description();
        }

        $this->assertSame($descriptions, array_unique($descriptions));
    }

    public function test_user_role_values_lists_every_case_in_declaration_order(): void
    {
        $this->assertSame(['admin', 'analyst', 'viewer'], UserRole::values());
    }

    public function test_the_three_enums_agree_with_the_user_table_defaults(): void
    {
        // The users table defaults to 'viewer' and datasets to 'uploaded'; if
        // either enum drops that case, an unlabelled row can no longer be
        // resolved by the fallbacks above.
        $this->assertContains('viewer', UserRole::values());
        $this->assertContains('uploaded', DatasetStatus::values());
        $this->assertContains('pass', array_map(static fn (QualityVerdict $v): string => $v->value, QualityVerdict::cases()));
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * A badge class that is not declared in `resources/css/app.css` renders as
     * unstyled text, and no view test would notice: the markup is identical
     * either way. So assert the rule really exists.
     */
    private function assertBadgeClassIsStyled(string $class): void
    {
        $this->assertTrue(
            $this->cssDeclares($class),
            "The badge class \"{$class}\" is not declared in resources/css/app.css; it would render unstyled.",
        );
    }

    private function cssDeclares(string $class): bool
    {
        $path = resource_path('css/app.css');

        $this->assertFileExists($path);

        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($path));

        // Anchored on a selector boundary so `.badge-success` matches but
        // `.badge-successful` does not.
        return preg_match(
            '/(?:^|[\s,>+~:])\.'.preg_quote($class, '/').'(?![A-Za-z0-9_-])/',
            $css,
        ) === 1;
    }
}
