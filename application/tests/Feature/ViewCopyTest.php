<?php

namespace Tests\Feature;

use App\Enums\DatasetStatus;
use App\Enums\QualityVerdict;
use App\Enums\UserRole;
use App\Models\Dataset;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The interface is Indonesian while the code, the comments and the identifiers
 * are English, and the seam between the two leaks: English words creep into a
 * template, a status word is retyped next to the enum that already owns it, or
 * a number is rendered with the PHP default separators. Each of those is
 * invisible in a code review that only reads for behaviour, and none of them
 * breaks a page, so nothing else in the suite would notice them coming back.
 *
 * These tests are that missing check. They are deliberately narrow and literal:
 * a short deny-list of copy that was actually wrong, one assertion per enum
 * case, the Indonesian number conventions, and the stylesheet rules the enums
 * depend on.
 */
class ViewCopyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * English copy that reached a template at some point. Deliberately short and
     * written out by hand: this is a tripwire for the drift, not a dictionary
     * checker, because a broad "detect English" rule would flag route names,
     * class names and the English comments the codebase is supposed to have.
     *
     * The entries are quoted the way they appeared in markup or in a copy map,
     * so a legitimate use of the same word in an identifier or a comment does
     * not trip them.
     */
    private const ENGLISH_USER_FACING = [
        '>Reset<',
        '>Submit<',
        '>Save<',
        '>Cancel<',
        '>Delete<',
        '>Search<',
        '>Committed<',
        '>Quarantined<',
        '>Uploaded<',
        '>Pending<',
        '>Datasets<',
        '>Imports<',
        '>Machine learning<',
        '>Audit Log<',
        '>Commit dataset<',
        '>Grade ',
        "'Forecast' =>",
        "'Churn' =>",
        "'Committed' =>",
        "'Quarantined' =>",
        'Full access, model governance',
        'Read-only dashboards and reports.',
    ];

    /**
     * The Indonesian wording for a status, a verdict or a role must come from
     * the enum, so none of these words may be typed into a template. Every one
     * of them is a `localizedLabel()` on one of the three enums.
     */
    private const ENUM_OWNED_WORDS = [
        'Terunggah',
        'Sedang dipratinjau',
        'Dipetakan',
        'Sedang diimpor',
        'Dikomit',
        'Dikarantina',
        'Gagal',
        'Lolos',
        'Analis Data',
        'Pengunjung',
    ];

    /**
     * Labels that were written in title case. Sentence case capitalises the
     * first word only, and a status tile that reads "Belum Dinilai" reads as a
     * heading rather than as a value.
     */
    private const TITLE_CASE_LABELS = [
        'Belum Dinilai',
        'Sudah Dikomit',
        'Semua Status',
        'Tipe Dataset',
        'Status Alur',
        'Job Import',
        'Hapus Dataset',
        'Daftar Pengguna',
        'Login Terakhir',
    ];

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->admin = User::factory()->admin()->create();

        $this->fakeEngine();
    }

    // ------------------------------------------------------------------
    // untranslated copy
    // ------------------------------------------------------------------

    public function test_no_view_contains_english_user_facing_copy(): void
    {
        foreach ($this->viewFiles() as $file) {
            $markup = $this->markupOf($file);

            foreach (self::ENGLISH_USER_FACING as $phrase) {
                $this->assertStringNotContainsString(
                    $phrase,
                    $markup,
                    "\"{$phrase}\" is English user-facing copy in ".basename($file).'; the interface is Indonesian.',
                );
            }
        }
    }

    public function test_no_view_retypes_a_word_the_enums_own(): void
    {
        foreach ($this->viewFiles() as $file) {
            $markup = $this->markupOf($file);

            foreach (self::ENUM_OWNED_WORDS as $word) {
                $this->assertStringNotContainsString(
                    "'{$word}'",
                    $markup,
                    "\"{$word}\" is hardcoded in ".basename($file)
                    .'; the enum that owns it must be the single source.',
                );
            }
        }
    }

    public function test_the_interface_uses_the_formal_register(): void
    {
        foreach ($this->viewFiles() as $file) {
            $markup = $this->markupOf($file);

            // "kamu" is the register of a chat app, not of a data platform used
            // by business users, which is addressed as "Anda".
            $this->assertDoesNotMatchRegularExpression(
                '/\b(?:kamu|anda-?mu)\b/i',
                $markup,
                basename($file).' addresses the user informally; this product is written for "Anda".',
            );
        }
    }

    public function test_labels_are_sentence_case(): void
    {
        foreach ($this->viewFiles() as $file) {
            $markup = $this->markupOf($file);

            foreach (self::TITLE_CASE_LABELS as $label) {
                $this->assertStringNotContainsString(
                    $label,
                    $markup,
                    "\"{$label}\" is title case in ".basename($file).'; labels are sentence case.',
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // the enums are the single source of a status word
    // ------------------------------------------------------------------

    public function test_every_dataset_status_renders_the_word_its_enum_owns(): void
    {
        foreach (DatasetStatus::cases() as $case) {
            Dataset::factory()->create([
                'name' => 'Data '.$case->value,
                'status' => $case,
            ]);
        }

        $html = $this->actingAs($this->admin)->get(route('datasets.index'))->assertOk()->getContent();

        foreach (DatasetStatus::cases() as $case) {
            $this->assertStringContainsString(
                $case->localizedLabel(),
                $html,
                "DatasetStatus::{$case->value} never renders; the status is invisible on the list page.",
            );

            $this->assertStringNotContainsString(
                $case->label(),
                $html,
                "DatasetStatus::{$case->value} rendered its English machine label; the user must read localizedLabel().",
            );
        }
    }

    public function test_every_quality_verdict_renders_the_word_its_enum_owns(): void
    {
        Dataset::factory()->committed()->create(['name' => 'Data lolos']);
        Dataset::factory()->quarantined()->create(['name' => 'Data dikarantina']);

        $html = $this->actingAs($this->admin)->get(route('quality.index'))->assertOk()->getContent();

        foreach (QualityVerdict::cases() as $case) {
            $this->assertStringContainsString(
                $case->localizedLabel(),
                $html,
                "QualityVerdict::{$case->value} never renders on the quality page.",
            );
        }
    }

    public function test_every_user_role_renders_the_word_its_enum_owns(): void
    {
        User::factory()->admin()->create(['name' => 'Admin Satu']);
        User::factory()->analyst()->create(['name' => 'Analis Dua']);
        User::factory()->viewer()->create(['name' => 'Pengunjung Tiga']);

        $html = $this->actingAs($this->admin)->get(route('admin.users.index'))->assertOk()->getContent();

        foreach (UserRole::cases() as $case) {
            $this->assertStringContainsString(
                $case->localizedLabel(),
                $html,
                "UserRole::{$case->value} never renders on the user page.",
            );
        }
    }

    public function test_every_enum_case_has_a_unique_indonesian_label(): void
    {
        foreach ([DatasetStatus::class, QualityVerdict::class, UserRole::class] as $enum) {
            $labels = [];

            foreach ($enum::cases() as $case) {
                $label = $case->localizedLabel();

                $this->assertNotSame('', $label, $enum.' has a case without a label.');

                $labels[] = $label;
            }

            $this->assertSame($labels, array_unique($labels), $enum.' has two cases that read the same.');
        }
    }

    // ------------------------------------------------------------------
    // number formatting
    // ------------------------------------------------------------------

    public function test_the_number_helpers_follow_the_indonesian_convention(): void
    {
        // Rupiah: Indonesian thousands separator, no decimals, "Rp" prefix. intl
        // puts a non-breaking space after the symbol, so the shape is asserted
        // with a pattern rather than a literal.
        $money = (string) Number::currency(1234567, in: 'idr', locale: 'id', precision: 0);

        $this->assertMatchesRegularExpression(
            '/^Rp\s*1\.234\.567$/u',
            $money,
            'Rupiah must read 1.234.567: a dot for thousands, no decimal mark, no "Rp" suffix.',
        );
        $this->assertStringNotContainsString(',', $money);
        $this->assertMatchesRegularExpression(
            '/^Rp\s*0$/u',
            (string) Number::currency(0, in: 'idr', locale: 'id', precision: 0),
        );

        // `Number::percentage()` takes percentage points, not a 0-1 ratio: it
        // divides by 100 and lets the intl formatter multiply back. A caller
        // that passes 0.8 therefore renders "0,8%", which is the bug this line
        // pins down.
        $this->assertSame('80,0%', Number::percentage(80, precision: 1, locale: 'id'));
        $this->assertSame('0,8%', Number::percentage(0.8, precision: 1, locale: 'id'));
        $this->assertSame('4,2%', Number::percentage(4.2, precision: 1, locale: 'id'));

        // Counts: "." groups, "," is the decimal mark. The PHP default is the
        // other way round, so every call has to state the separators.
        $this->assertSame('1.234.567', number_format(1234567, 0, ',', '.'));
    }

    public function test_a_zero_to_one_score_is_never_rendered_as_a_tenth_of_a_percent(): void
    {
        Dataset::factory()->committed()->create([
            'name' => 'Dataheses',
            'quality_score' => 0.8,
        ]);

        $html = $this->actingAs($this->admin)->get(route('datasets.index'))->assertOk()->getContent();

        $this->assertStringContainsString('80,0%', $html);
        $this->assertStringNotContainsString('0,8%', $html);
        $this->assertStringNotContainsString('0.8%', $html);
    }

    public function test_the_quality_threshold_renders_as_a_percentage_not_a_ratio(): void
    {
        // QUALITY_THRESHOLD is 0.75, so 75,0% is the only correct rendering.
        $html = $this->actingAs($this->admin)->get(route('quality.index'))->assertOk()->getContent();

        $this->assertStringContainsString('75,0%', $html);
        $this->assertStringNotContainsString('0,8%', $html);
    }

    public function test_money_and_counts_render_with_indonesian_separators(): void
    {
        $html = $this->actingAs($this->admin)->get(route('analytics.index'))->assertOk()->getContent();

        // Built through the helper rather than typed, so the assertion is about
        // the rendering and not about the exact space intl puts after "Rp".
        $revenue = Number::currency(391000000, in: 'idr', locale: 'id', precision: 0);
        $average = Number::currency(114565, in: 'idr', locale: 'id', precision: 0);

        $this->assertStringContainsString($revenue, $html, 'The revenue must render through Number::currency().');
        $this->assertStringContainsString($average, $html);

        $this->assertStringContainsString('3.412', $html, 'A count of 3412 must read 3.412.');
        $this->assertStringNotContainsString('3,412', $html, 'The PHP default separator leaked into the page.');
        $this->assertStringNotContainsString('391,000,000', $html);

        // The engine sends these already in percentage points.
        $this->assertStringContainsString('4,2%', $html);
        $this->assertStringContainsString('18,5%', $html);
        $this->assertStringContainsString('31,6%', $html);
    }

    public function test_every_count_in_a_template_states_the_indonesian_separators(): void
    {
        foreach ($this->viewFiles() as $file) {
            $markup = $this->markupOf($file);

            preg_match_all('/number_format\(/', $markup, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [$call, $offset]) {
                $arguments = $this->balancedCall($markup, (int) $offset);

                $this->assertGreaterThanOrEqual(
                    3,
                    substr_count((string) $arguments, ','),
                    basename($file).' calls '.$arguments
                    .' without a decimal and a thousands separator, so it renders PHP defaults (3,412).',
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // the stylesheet
    // ------------------------------------------------------------------

    public function test_every_badge_class_the_enums_emit_is_declared_in_the_stylesheet(): void
    {
        foreach ($this->emittedBadgeClasses() as $class) {
            $this->assertTrue(
                $this->cssDeclares($class),
                "\"{$class}\" is emitted by an enum or a config value but is not declared in app.css; it renders unstyled.",
            );
        }
    }

    public function test_a_badge_colour_cannot_be_overridden_by_an_equal_specificity_rule(): void
    {
        // The regression: every rule in app.css has one class selector, so a
        // later `.badge-info` beat an earlier `.badge-success` and a committed
        // dataset painted like a quarantined one. The colour rules must now be
        // declared with a compound selector, which outranks any single class.
        $variants = ['success', 'danger', 'warning', 'info', 'neutral'];

        $declared = $this->cssSelectors();

        foreach ($variants as $variant) {
            $this->assertContains(
                '.badge.badge-'.$variant,
                $declared,
                ".badge.badge-{$variant} is missing, so a badge carrying two colour classes is "
                .'decided by rule order again.',
            );
        }
    }

    public function test_the_templates_use_the_theme_tokens_instead_of_hardcoded_colours(): void
    {
        $theme = $this->themeTokens();

        $this->assertNotEmpty($theme, 'No --color-* token was found in the @theme block of app.css.');

        foreach ($this->viewFiles() as $file) {
            $markup = $this->markupOf($file);

            $this->assertDoesNotMatchRegularExpression(
                '/#[0-9a-fA-F]{3,8}\b|\brgba?\(|\bhsla?\(|\boklch\(/',
                $markup,
                basename($file).' hardcodes a colour instead of using a @theme token.',
            );

            preg_match_all('/\b(brand|accent)-(\d{2,3})\b/', $markup, $used, PREG_SET_ORDER);

            foreach ($used as [, $family, $shade]) {
                $token = '--color-'.$family.'-'.$shade;

                $this->assertArrayHasKey(
                    $token,
                    $theme,
                    basename($file)." uses {$family}-{$shade}, which app.css never declares in @theme.",
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // labelling
    // ------------------------------------------------------------------

    public function test_every_icon_only_control_carries_an_accessible_name(): void
    {
        $pages = [
            'dashboard' => fn (): TestResponse => $this->actingAs($this->admin)->get(route('dashboard')),
            'datasets.index' => fn (): TestResponse => $this->actingAs($this->admin)->get(route('datasets.index')),
            'datasets.show' => fn (): TestResponse => $this->actingAs($this->admin)
                ->get(route('datasets.show', Dataset::factory()->committed()->create())),
            'quality.index' => fn (): TestResponse => $this->actingAs($this->admin)->get(route('quality.index')),
            'analytics.index' => fn (): TestResponse => $this->actingAs($this->admin)->get(route('analytics.index')),
            'ml.index' => fn (): TestResponse => $this->actingAs($this->admin)->get(route('ml.index')),
            'assistant.index' => fn (): TestResponse => $this->actingAs($this->admin)->get(route('assistant.index')),
            'reports.index' => fn (): TestResponse => $this->actingAs($this->admin)->get(route('reports.index')),
            'admin.users.index' => fn (): TestResponse => $this->actingAs($this->admin)->get(route('admin.users.index')),
            'audit.index' => fn (): TestResponse => $this->actingAs($this->admin)->get(route('audit.index')),
            'profile.password' => fn (): TestResponse => $this->actingAs($this->admin)->get(route('password.edit')),
        ];

        $checked = 0;

        foreach ($pages as $name => $page) {
            $xpath = $this->xpathOf($page()->assertOk()->getContent());

            /** @var DOMElement $element */
            foreach ($xpath->query('//button | //a | //summary | //input[@type="checkbox"] | //select') as $element) {
                $checked++;

                $label = $element->getAttribute('aria-label')
                    ?: $element->getAttribute('title')
                    ?: $element->getAttribute('aria-labelledby')
                    ?: trim((string) $element->textContent);

                // A form control is also named by a <label for="id">, which is
                // the only way a checkbox can ever be labelled.
                if ($label === '' && ($id = $element->getAttribute('id')) !== '') {
                    $labelled = $xpath->query('//label[@for='.$this->xpathLiteral($id).']');

                    $label = $labelled->length > 0 ? (string) $labelled->item(0)->textContent : '';
                }

                $this->assertNotSame(
                    '',
                    trim((string) $label),
                    "An icon-only <{$element->nodeName}> on {$name} has no aria-label, title, text or <label>: "
                    .'a screen reader announces it as an unlabelled control.',
                );
            }
        }

        $this->assertGreaterThan(20, $checked, 'The labelling check only saw a handful of controls.');
    }

    /**
     * Wrap a value so it can sit inside an XPath expression.
     */
    protected function xpathLiteral(string $value): string
    {
        if (str_contains($value, "'")) {
            return 'concat('.implode(", \"'\", ", array_map(
                static fn (string $part): string => "'".$part."'",
                explode("'", $value),
            )).')';
        }

        return "'".$value."'";
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * A working engine: the analytics page is where money and percentages are
     * rendered, so the formatting assertions need real numbers behind them.
     */
    protected function fakeEngine(): void
    {
        Http::fake(function (ClientRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            $data = match (true) {
                $path === '/api/v1/health' => null,
                str_ends_with($path, '/analytics/kpi') => [
                    'revenue' => 391000000,
                    'orders' => 3412,
                    'units' => 5120,
                    'aov' => 114565,
                    'growth_pct' => 4.2,
                    'margin_pct' => 18.5,
                ],
                str_ends_with($path, '/analytics/trend') => [
                    ['period' => '2026-09-01', 'revenue' => 1234000, 'orders' => 120, 'units' => 300],
                ],
                str_ends_with($path, '/analytics/branches') => [
                    ['branch' => 'BR-01', 'revenue' => 250000000, 'orders' => 2000, 'share_pct' => 31.6],
                ],
                str_ends_with($path, '/analytics/finance') => [
                    'total_revenue' => 391000000,
                    'total_cogs' => 260000000,
                    'total_expenses' => 60000000,
                    'gross_profit' => 131000000,
                    'net_profit' => 71000000,
                    'margin_pct' => 18.5,
                ],
                str_ends_with($path, '/analytics/abc') => [
                    ['product' => 'Minyak Goreng 1L', 'revenue' => 120000000, 'share_pct' => 30.7, 'cumulative_pct' => 30.7, 'grade' => 'A'],
                ],
                str_ends_with($path, '/analytics/cohort') => [
                    ['cohort' => '2026-06', 'period_offset' => 0, 'retention_pct' => 100.0, 'active_customers' => 1234],
                ],
                str_ends_with($path, '/analytics/rfm') => [
                    ['customer' => 'Toko Berkah Jaya', 'recency_days' => 4, 'frequency' => 22, 'monetary' => 45000000, 'r_score' => 5, 'f_score' => 5, 'm_score' => 5, 'segment' => 'champions'],
                ],
                default => [],
            };

            if ($path === '/api/v1/health') {
                return Http::response(['status' => 'ok', 'app' => 'aidata-engine', 'env' => 'test', 'version' => '1.4.2'], 200);
            }

            return Http::response(['success' => true, 'data' => $data], 200);
        });
    }

    /**
     * @return list<string>
     */
    protected function viewFiles(): array
    {
        $root = resource_path('views');

        $files = array_map(
            static fn (string $path): string => $path,
            $this->phpFilesIn($root),
        );

        $this->assertGreaterThanOrEqual(26, count($files), 'The view tree shrank; the copy scan is not looking at every page.');

        return $files;
    }

    /**
     * @return list<string>
     */
    protected function phpFilesIn(string $directory): array
    {
        $found = [];

        /** @var \SplFileInfo $entry */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $found[] = $entry->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    /**
     * The template with its Blade comments removed. Comments are English by
     * design in this codebase, so they are not copy and must not be scanned.
     */
    protected function markupOf(string $file): string
    {
        $source = (string) file_get_contents($file);

        $markup = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);

        $this->assertNotSame('', $markup, basename($file).' is empty.');

        return $markup;
    }

    /**
     * The text of a balanced `name(...)` call, from its offset to the closing
     * parenthesis.
     */
    protected function balancedCall(string $source, int $offset): string
    {
        $length = strlen($source);
        $depth = 0;

        for ($i = $offset; $i < $length; $i++) {
            if ($source[$i] === '(') {
                $depth++;
            } elseif ($source[$i] === ')') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $offset, $i - $offset + 1);
                }
            }
        }

        return substr($source, $offset);
    }

    /**
     * @return list<string>
     */
    protected function emittedBadgeClasses(): array
    {
        $classes = [];

        foreach (DatasetStatus::cases() as $case) {
            $classes[] = $case->badgeClass();
        }

        foreach (QualityVerdict::cases() as $case) {
            $classes[] = $case->badgeClass();
        }

        foreach (['model_version_statuses', 'import_job_statuses'] as $key) {
            foreach ((array) config('ai_engine.'.$key, []) as $entry) {
                if (is_array($entry) && isset($entry['badge'])) {
                    $classes[] = (string) $entry['badge'];
                }
            }
        }

        $classes = array_values(array_unique($classes));

        $this->assertContains('badge-success', $classes);
        $this->assertContains('badge-danger', $classes);
        $this->assertContains('badge-warning', $classes);
        $this->assertContains('badge-info', $classes);

        return $classes;
    }

    protected function css(): string
    {
        $path = resource_path('css/app.css');

        $this->assertFileExists($path);

        return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($path));
    }

    protected function cssDeclares(string $class): bool
    {
        // Anchored on a selector boundary so `.badge-success` matches but
        // `.badge-successful` does not.
        return preg_match(
            '/(?:^|[\s,>+~:])\.'.preg_quote($class, '/').'(?![A-Za-z0-9_-])/',
            $this->css(),
        ) === 1;
    }

    /**
     * Every class selector that opens a rule, normalised.
     *
     * @return list<string>
     */
    protected function cssSelectors(): array
    {
        preg_match_all('/([^{}]+)\{/', $this->css(), $blocks);

        $selectors = [];

        foreach ($blocks[1] as $block) {
            $block = trim($block);

            if ($block === '' || str_contains($block, '@')) {
                continue;
            }

            foreach (explode(',', $block) as $selector) {
                $selector = trim((string) preg_replace('/\s+/', ' ', $selector));

                if ($selector !== '') {
                    $selectors[] = $selector;
                }
            }
        }

        return array_values(array_unique($selectors));
    }

    /**
     * The `--color-*` tokens the `@theme` block declares.
     *
     * @return array<string, string>
     */
    protected function themeTokens(): array
    {
        preg_match('/@theme\s*\{(.*?)\n\}/s', $this->css(), $theme);

        if (($theme[1] ?? '') === '') {
            return [];
        }

        preg_match_all('/(--color-[a-z0-9-]+):\s*([^;]+);/i', $theme[1], $tokens, PREG_SET_ORDER);

        $map = [];

        foreach ($tokens as [, $name, $value]) {
            $map[$name] = trim($value);
        }

        return $map;
    }

    protected function xpathOf(string $html): DOMXPath
    {
        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
