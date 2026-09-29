<?php

namespace Tests\Feature;

use App\Enums\DatasetStatus;
use App\Enums\UserRole;
use App\Models\ChatThread;
use App\Models\Dataset;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Asserts on the rendered HTML rather than on the view name.
 *
 * `ViewRenderingTest` proves every page answers 200 with the right view and
 * survives a dead engine. That says nothing about what came out. These tests
 * read the markup: the classes, the words, the form methods, the escaping.
 *
 * The first test is the regression test for the badge bug. `x-badge` used to
 * default its variant to `info`, so a committed dataset and a quarantined one
 * both painted `.badge-info`: `app.css` declares `.badge-info` after
 * `.badge-success` at equal specificity, and the later rule wins. The
 * resulting page looked correct in a diff and told the user nothing in the
 * browser. It is asserted here in terms of the *class list*, not the rendered
 * colour, so it fails the moment a default variant comes back.
 */
class ViewContentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*/api/v1/*' => Http::response(['success' => true, 'data' => []], 200)]);

        $this->admin = User::factory()->admin()->create();
    }

    // ------------------------------------------------------------------
    // status is colour AND text, and the two never disagree
    // ------------------------------------------------------------------

    public function test_a_committed_and_a_quarantined_dataset_render_different_badge_classes(): void
    {
        $committed = Dataset::factory()->committed()->create(['name' => 'DatasetTerkomit']);
        $quarantined = Dataset::factory()->quarantined()->create(['name' => 'DatasetKarantina']);

        $committedBadge = $this->badgeFor(
            $this->render(route('datasets.show', $committed)),
            DatasetStatus::Committed->localizedLabel()
        );

        $quarantinedBadge = $this->badgeFor(
            $this->render(route('datasets.show', $quarantined)),
            DatasetStatus::Quarantined->localizedLabel()
        );

        $this->assertSame('badge-success', $committedBadge['variant']);
        $this->assertSame('badge-danger', $quarantinedBadge['variant']);

        // The two states must not share a class list, or the page is painting
        // two different outcomes in the same colour.
        $this->assertNotSame($committedBadge['classes'], $quarantinedBadge['classes']);

        // The guard against the original bug: a badge must carry exactly one
        // variant class. `app.css` now resolves this with a compound
        // `.badge.badge-<variant>` selector, so the double class would still
        // paint correctly today — but `class="badge badge-info badge-success"`
        // is precisely the shape the bug had, and it stops being unambiguous
        // the moment that stylesheet rule changes. Verified by mutation: with
        // `@props(['variant' => 'info'])` restored, this assertion fails on
        // `'badge badge-info badge-success'`.
        $this->assertStringNotContainsString('badge-info', $committedBadge['classes']);
        $this->assertStringNotContainsString('badge-info', $quarantinedBadge['classes']);
        $this->assertStringNotContainsString('badge-success', $quarantinedBadge['classes']);
        $this->assertStringNotContainsString('badge-danger', $committedBadge['classes']);
    }

    public function test_a_quarantined_dataset_states_its_status_in_words_not_just_in_colour(): void
    {
        $html = $this->render(route('datasets.show', Dataset::factory()->quarantined()->create()));

        $this->assertStringContainsString(DatasetStatus::Quarantined->localizedLabel(), $html);
        $this->assertStringContainsString('Belum lolos', $html);
        $this->assertStringContainsString('badge-danger', $html);

        // The quality breakdown is spelled out per dimension, so the verdict is
        // not the only signal a user gets.
        $this->assertStringContainsString('Kelengkapan', $html);
        $this->assertStringContainsString('Keunikan', $html);
    }

    public function test_a_committed_dataset_states_its_status_in_words_not_just_in_colour(): void
    {
        $html = $this->render(route('datasets.show', Dataset::factory()->committed()->create()));

        $this->assertStringContainsString(DatasetStatus::Committed->localizedLabel(), $html);
        $this->assertStringContainsString('Lolos', $html);
        $this->assertStringContainsString('badge-success', $html);
    }

    public function test_every_dataset_status_case_renders_a_non_blank_label(): void
    {
        foreach (DatasetStatus::cases() as $status) {
            Dataset::factory()->create([
                'status' => $status,
                'import_job_id' => $status === DatasetStatus::Uploaded ? null : 4242,
            ]);
        }

        $html = $this->render(route('datasets.index'));

        foreach (DatasetStatus::cases() as $status) {
            $this->assertNotSame('', $status->localizedLabel());
            $this->assertNotSame('', $status->label());

            // The filter select enumerates every case, so a case added to the
            // enum can never render as a blank option.
            $this->assertStringContainsString('>'.$status->localizedLabel().'</span>', $html);
            $this->assertMatchesRegularExpression(
                '/<option value="'.preg_quote($status->value, '/').'"\s*>/',
                $html,
                "The status filter is missing the [{$status->value}] option."
            );
        }
    }

    public function test_a_deactivated_account_is_marked_in_words_and_not_only_in_colour(): void
    {
        User::factory()->viewer()->inactive()->create(['name' => 'Akun Nonaktif']);

        $html = $this->render(route('admin.users.index'));

        $this->assertStringContainsString('Nonaktif', $html);
        $this->assertStringContainsString('badge-danger', $html);
    }

    // ------------------------------------------------------------------
    // roles
    // ------------------------------------------------------------------

    public function test_the_role_label_of_the_signed_in_user_is_in_the_layout(): void
    {
        foreach (UserRole::cases() as $role) {
            $user = User::factory()->create(['role' => $role->value]);

            $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

            $this->assertStringContainsString($role->localizedLabel(), $html);
        }
    }

    public function test_every_role_description_is_reachable_in_the_ui(): void
    {
        $html = $this->render(route('admin.users.index'));

        foreach (UserRole::cases() as $role) {
            $this->assertStringContainsString($role->description(), $html);
        }
    }

    public function test_admin_only_navigation_is_shown_to_an_admin_and_hidden_from_a_viewer(): void
    {
        $adminHtml = $this->actingAs(User::factory()->admin()->create())->get(route('dashboard'))->getContent();
        $viewerHtml = $this->actingAs(User::factory()->viewer()->create())->get(route('dashboard'))->getContent();

        $adminLinks = [route('admin.users.index'), route('audit.index')];
        $sharedLinks = [route('dashboard'), route('datasets.index'), route('analytics.index')];

        foreach ($adminLinks as $link) {
            $this->assertStringContainsString('href="'.$link.'"', $adminHtml, "An admin cannot reach [{$link}] from the nav.");
            $this->assertStringNotContainsString('href="'.$link.'"', $viewerHtml, "A viewer is shown the admin-only link [{$link}].");
        }

        foreach ($sharedLinks as $link) {
            $this->assertStringContainsString('href="'.$link.'"', $adminHtml);
            $this->assertStringContainsString('href="'.$link.'"', $viewerHtml);
        }
    }

    // ------------------------------------------------------------------
    // document structure
    // ------------------------------------------------------------------

    public function test_the_layout_declares_indonesian_and_lands_has_a_main_and_a_named_nav(): void
    {
        $pages = [
            route('dashboard'),
            route('datasets.index'),
            route('analytics.index'),
            route('password.edit'),
        ];

        foreach ($pages as $page) {
            $html = $this->render($page);
            $xpath = $this->xpath($html);

            $this->assertSame('id', $xpath->query('//html')->item(0)?->getAttribute('lang'), "{$page} has no lang attribute.");
            $this->assertSame(1, $xpath->query('//main')->length, "{$page} has no <main> landmark.");

            // A <nav> with no accessible name is two unnamed navigation
            // landmarks to a screen reader reader, which is worse than none.
            $namedNavs = $xpath->query('//nav[@aria-label or @aria-labelledby]');

            $this->assertGreaterThan(0, $namedNavs->length, "{$page} has no <nav> with an accessible name.");

            $labels = [];

            foreach ($namedNavs as $nav) {
                $labels[] = trim((string) $nav->getAttribute('aria-label'));
            }

            $this->assertNotContains('', $labels, "{$page} renders an unnamed <nav>.");
        }
    }

    public function test_the_guest_layout_also_declares_indonesian_and_a_main_landmark(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $this->assertSame('id', $xpath->query('//html')->item(0)?->getAttribute('lang'));
        $this->assertSame(1, $xpath->query('//main')->length);
    }

    // ------------------------------------------------------------------
    // forms
    // ------------------------------------------------------------------

    public function test_every_mutating_form_carries_a_csrf_token_or_a_spoofed_method(): void
    {
        $checked = 0;

        foreach ($this->formPages() as $page => $html) {
            foreach ($this->forms($html) as $form) {
                $method = $this->effectiveMethod($form);

                if ($method === 'GET') {
                    continue;
                }

                $checked++;
                $fields = $form['fields'];

                $this->assertTrue(
                    isset($fields['_token']) || isset($fields['_method']),
                    "The {$method} form on [{$page}] to {$form['action']} carries neither a CSRF field nor a method override."
                );
            }
        }

        $this->assertGreaterThan(10, $checked, 'The CSRF sweep found fewer mutating forms than expected; the page list has drifted.');
    }

    public function test_every_form_posts_to_a_route_that_is_registered(): void
    {
        $checked = 0;

        foreach ($this->formPages() as $page => $html) {
            foreach ($this->forms($html) as $form) {
                $checked++;
                $method = $this->effectiveMethod($form);

                $this->assertNotEmpty(
                    $this->matchingRoutes($method, $form['path']),
                    "The [{$method}] form on [{$page}] posts to [{$form['path']}], which matches no route in route:list."
                );
            }
        }

        $this->assertGreaterThan(10, $checked, 'The route sweep found fewer forms than expected; the page list has drifted.');
    }

    /**
     * Every page that carries a form, keyed by a human-readable label. Kept in
     * one place so the CSRF sweep and the route sweep cannot disagree about
     * which pages are covered.
     *
     * @return array<string, string>
     */
    protected function formPages(): array
    {
        $dataset = Dataset::factory()->committed()->create();
        $thread = ChatThread::factory()->forUser($this->admin)->titled('Percakapan uji', 2)->create();
        $thread->messages()->create(['role' => 'user', 'content' => 'Halo']);

        return [
            // The guest page first: `actingAs()` sticks for the rest of the
            // test, and `/login` redirects an authenticated user to the
            // dashboard instead of rendering the form.
            'auth.login' => $this->get(route('login'))->getContent(),
            'dashboard' => $this->actingAs($this->admin)->get(route('dashboard'))->getContent(),
            'datasets.index' => $this->actingAs($this->admin)->get(route('datasets.index'))->getContent(),
            'datasets.create' => $this->actingAs($this->admin)->get(route('datasets.create'))->getContent(),
            'datasets.show' => $this->actingAs($this->admin)->get(route('datasets.show', $dataset))->getContent(),
            'imports.index' => $this->actingAs($this->admin)->get(route('imports.index'))->getContent(),
            'quality.index' => $this->actingAs($this->admin)->get(route('quality.index'))->getContent(),
            'analytics.index' => $this->actingAs($this->admin)->get(route('analytics.index'))->getContent(),
            'ml.index' => $this->actingAs($this->admin)->get(route('ml.index'))->getContent(),
            'assistant.index' => $this->actingAs($this->admin)->get(route('assistant.index', ['thread' => $thread->getKey()]))->getContent(),
            'reports.index' => $this->actingAs($this->admin)->get(route('reports.index'))->getContent(),
            'admin.users.index' => $this->actingAs($this->admin)->get(route('admin.users.index'))->getContent(),
            'audit.index' => $this->actingAs($this->admin)->get(route('audit.index'))->getContent(),
            'password.edit' => $this->actingAs($this->admin)->get(route('password.edit'))->getContent(),
        ];
    }

    /**
     * @return list<array{action: string, path: string, method: string, fields: array<string, string>}>
     */
    protected function forms(string $html): array
    {
        $xpath = $this->xpath($html);
        $forms = [];

        foreach ($xpath->query('//form') as $form) {
            if (! $form instanceof DOMElement) {
                continue;
            }

            $action = $form->getAttribute('action');
            $path = (string) parse_url($action, PHP_URL_PATH);

            // Every named input in the form, which is where `@csrf` and
            // `@method` land.
            $fields = [];

            foreach ($xpath->query('.//input[@name]', $form) as $input) {
                if ($input instanceof DOMElement) {
                    $fields[$input->getAttribute('name')] = $input->getAttribute('value');
                }
            }

            $forms[] = [
                'action' => $action,
                'path' => $path === '' ? '/' : $path,
                'method' => strtoupper($form->getAttribute('method') ?: 'GET'),
                'fields' => $fields,
            ];
        }

        return $forms;
    }

    /**
     * A form posting with `@method('PATCH')` is a PATCH, whatever its `method`
     * attribute says — the attribute is only the transport.
     *
     * @param  array{method: string, fields: array<string, string>}  $form
     */
    protected function effectiveMethod(array $form): string
    {
        return strtoupper($form['fields']['_method'] ?? $form['method']);
    }

    /**
     * The registered routes that accept this verb at this path, matched against
     * the same URIs `route:list` prints.
     *
     * @return list<string>
     */
    protected function matchingRoutes(string $method, string $path): array
    {
        $matches = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! $route instanceof RoutingRoute) {
                continue;
            }

            $methods = array_values(array_diff($route->methods(), ['HEAD']));

            if (! in_array($method, $methods, true)) {
                continue;
            }

            if (preg_match($this->uriPattern($route->uri()), $path) === 1) {
                $matches[] = $route->uri().' ['.$method.']';
            }
        }

        return $matches;
    }

    protected function uriPattern(string $uri): string
    {
        $pattern = (string) preg_replace('#\{[^}]+\}#', '[^/]+', $uri);

        return '#^/'.trim($pattern, '/').'(?:/)?$#';
    }

    // ------------------------------------------------------------------
    // escaping
    // ------------------------------------------------------------------

    public function test_a_script_payload_in_a_dataset_name_is_escaped(): void
    {
        $dataset = Dataset::factory()->committed()->create(['name' => $this->payload('dataset')]);

        foreach ([route('datasets.index'), route('datasets.show', $dataset), route('imports.index')] as $page) {
            $html = $this->render($page);

            $this->assertStringNotContainsString('<script>alert("dataset")', $html, "[{$page}] emitted a raw script tag.");
            $this->assertStringContainsString('&lt;script&gt;alert(&quot;dataset&quot;)', $html, "[{$page}] did not render the escaped payload at all.");
        }
    }

    public function test_a_script_payload_in_an_llm_answer_is_escaped(): void
    {
        $thread = ChatThread::factory()->forUser($this->admin)->titled('Percakapan uji', 1)->create();
        $thread->messages()->create([
            'role' => 'assistant',
            'content' => $this->payload('llm'),
            'steps' => 1,
        ]);

        $html = $this->render(route('assistant.index', ['thread' => $thread->getKey()]));

        $this->assertStringNotContainsString('<script>alert("llm")', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;llm&quot;)', $html);
    }

    public function test_a_script_payload_in_a_rag_citation_is_escaped(): void
    {
        $thread = ChatThread::factory()->forUser($this->admin)->titled('Percakapan uji', 1)->create();
        $thread->messages()->create([
            'role' => 'assistant',
            'content' => 'Berikut kutipannya.',
            'evidence' => [
                ['source' => 'rag.kebijakan', 'data' => ['snippet' => $this->payload('rag')]],
            ],
            'steps' => 1,
        ]);

        $html = $this->render(route('assistant.index', ['thread' => $thread->getKey()]));

        $this->assertStringNotContainsString('<script>alert("rag")', $html);
        // The evidence row is rendered as JSON, so the payload arrives escaped
        // from both ends: Blade escapes the JSON string, and the JSON itself
        // cannot close the surrounding element.
        $this->assertStringContainsString('&lt;script&gt;alert(', $html);
    }

    public function test_no_view_uses_the_unescaped_blade_echo_directive(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            if (str_contains((string) file_get_contents($file), '{!!')) {
                $offenders[] = Str::after($file, base_path().DIRECTORY_SEPARATOR);
            }
        }

        $this->assertSame([], $offenders, 'Unescaped Blade output in: '.implode(', ', $offenders));
    }

    protected function payload(string $tag): string
    {
        return '<script>alert("'.$tag.'")</script>';
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    protected function render(string $page): string
    {
        return $this->actingAs($this->admin)
            ->get($page)
            ->assertOk()
            ->getContent();
    }

    /**
     * The `<span class="badge …">` that carries this exact text, as a class
     * list. Returns an empty variant when no badge holds the text, so a missing
     * badge fails the assertion rather than silently passing.
     *
     * @return array{classes: string, variant: string}
     */
    protected function badgeFor(string $html, string $text): array
    {
        foreach ($this->xpath($html)->query('//span[contains(@class, "badge")]') as $badge) {
            if (! $badge instanceof DOMElement || trim($badge->textContent) !== $text) {
                continue;
            }

            $classes = trim($badge->getAttribute('class'));
            $variant = '';

            foreach (explode(' ', $classes) as $class) {
                if (Str::startsWith($class, 'badge-')) {
                    $variant = $class;
                }
            }

            return ['classes' => $classes, 'variant' => $variant];
        }

        $this->fail("No badge with the text [{$text}] was rendered.");
    }

    /** @return list<string> */
    protected function bladeFiles(): array
    {
        $files = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    protected function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($document);
    }
}
