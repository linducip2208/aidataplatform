<?php

namespace Tests\Feature;

use App\Http\Controllers\DatasetController;
use App\Http\Middleware\SecurityHeaders;
use App\Models\ChatThread;
use App\Models\Dataset;
use App\Models\User;
use App\Policies\ChatThreadPolicy;
use App\Policies\DatasetPolicy;
use App\Support\PiiMask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Enterprise security surface (A8).
 *
 * Permission matrix over HTTP (role gates that exist today), upload
 * hardening (`DatasetController@store`), the new `SecurityHeaders`
 * middleware in isolation (master wires it globally — asserting it on a live
 * response before that would test wiring, not the middleware), the pure
 * policy matrix (`DatasetPolicy` / `ChatThreadPolicy`, not yet enforced —
 * see their docblocks), the pure `User::can()` helper, and `PiiMask`.
 *
 * Must stay green alongside `RoleMiddlewareTest`, `SecurityHardeningTest`
 * and `AuthTest` without modifying them.
 */
class SecurityEnterpriseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Http::fake(function ($request) {
            $url = $request->url();

            $payload = str_ends_with($url, '/imports/upload')
                ? [
                    'upload_id' => 1,
                    'import_job_id' => 42,
                    'validation' => [
                        'ok' => true,
                        'meta' => ['size_bytes' => 12, 'mime' => 'text/csv', 'checksum_sha256' => 'abc123'],
                    ],
                    'stored_path' => '/data/storage/sales.csv',
                ]
                : [];

            return Http::response(['success' => true, 'data' => $payload], 200);
        });
    }

    protected function user(string $role, bool $active = true): User
    {
        return User::factory()->create([
            'role' => $role,
            'is_active' => $active,
            'email' => Str::random(8).'@example.co.id',
        ]);
    }

    // ------------------------------------------------------------------
    // Permission matrix over HTTP (gates enforced today)
    // ------------------------------------------------------------------

    public function test_admin_reaches_admin_and_audit_pages_analyst_and_viewer_do_not(): void
    {
        $this->actingAs($this->user('admin'))->get(route('admin.users.index'))->assertOk();
        $this->actingAs($this->user('admin'))->get(route('audit.index'))->assertOk();

        $this->actingAs($this->user('analyst'))->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($this->user('analyst'))->get(route('audit.index'))->assertForbidden();

        $this->actingAs($this->user('viewer'))->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($this->user('viewer'))->get(route('audit.index'))->assertForbidden();
    }

    public function test_viewer_is_forbidden_from_writing_on_web_and_api(): void
    {
        $this->actingAs($this->user('viewer'))
            ->post(route('datasets.store'), [])
            ->assertForbidden();

        Sanctum::actingAs($this->user('viewer'));

        $this->postJson(route('api.datasets.store'), ['dataset_type' => 'sales'])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_viewer_is_forbidden_from_training_and_promoting_models(): void
    {
        $this->actingAs($this->user('viewer'))
            ->post(route('ml.train'), ['model_type' => 'forecast'])
            ->assertForbidden();

        Sanctum::actingAs($this->user('viewer'));

        $this->postJson(route('api.ml.models.promote', ['modelId' => 1]), ['version_id' => 2])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_analyst_passes_the_write_gate_but_not_the_admin_gate(): void
    {
        // Validation runs after the role gate: a 302 with `file` errors
        // proves the gate let the analyst through.
        $this->actingAs($this->user('analyst'))
            ->from(route('datasets.create'))
            ->post(route('datasets.store'), [])
            ->assertRedirect(route('datasets.create'))
            ->assertSessionHasErrors('file');

        $this->actingAs($this->user('analyst'))
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    public function test_deactivated_accounts_are_blocked_on_web_and_api(): void
    {
        $this->actingAs($this->user('viewer', active: false))
            ->get(route('datasets.index'))
            ->assertForbidden()
            ->assertViewIs('errors.inactive');

        Sanctum::actingAs($this->user('viewer', active: false));

        $this->getJson(route('api.me'))
            ->assertForbidden()
            ->assertJsonPath('code', 'account_inactive');
    }

    public function test_guest_is_redirected_to_login_on_web_and_unauthenticated_on_api(): void
    {
        $this->get(route('admin.users.index'))->assertRedirect(route('login'));

        $this->app['auth']->forgetGuards();
        $this->getJson(route('api.me'))->assertUnauthorized();
    }

    // ------------------------------------------------------------------
    // Upload hardening (DatasetController@store)
    // ------------------------------------------------------------------

    public function test_double_extension_with_executable_payload_is_rejected_without_touching_the_engine(): void
    {
        $this->actingAs($this->user('analyst'))
            ->from(route('datasets.create'))
            ->post(route('datasets.store'), [
                // Final extension is allow-listed (`csv`) so the `extensions:`
                // rule passes; the `php` payload in the middle is what the
                // new filename screen must catch.
                'file' => UploadedFile::fake()->createWithContent('sales.php.csv', "a,b\n1,2\n"),
                'dataset_type' => 'sales',
            ])
            ->assertRedirect(route('datasets.create'))
            ->assertSessionHasErrors('file');

        $this->assertDatabaseCount('datasets', 0);
        Http::assertNothingSent();
    }

    public function test_double_extension_on_the_api_is_a_known_gap_for_master(): void
    {
        // FINDING A8-02: `Api\DatasetController@store` is NOT A8-owned, so it
        // still lacks the double-extension screen the web route above has.
        // `sales.php.csv` ends in an allow-listed extension and is accepted
        // (201) there today. This pins the gap so the fix flips it to 422;
        // master must port `DatasetController::unsafeFilenameReason()` into
        // the API controller (see `docs/security.md` §8, A8-02).
        $this->assertNotNull(DatasetController::unsafeFilenameReason('sales.php.csv'));

        Sanctum::actingAs($this->user('analyst'));

        $this->post(route('api.datasets.store'), [
            'file' => UploadedFile::fake()->createWithContent('sales.php.csv', "a,b\n1,2\n"),
            'dataset_type' => 'sales',
        ])->assertCreated();

        $this->assertDatabaseCount('datasets', 1);
    }

    public function test_traversal_and_path_filenames_are_rejected(): void
    {
        // Deterministic unit pin: the HTTP fake layer may normalise odd
        // names, but the screen itself must refuse them.
        $this->assertNotNull(DatasetController::unsafeFilenameReason('../evil.csv'));
        $this->assertNotNull(DatasetController::unsafeFilenameReason('a/b.csv'));
        $this->assertNotNull(DatasetController::unsafeFilenameReason('a\\b.csv'));
        $this->assertNotNull(DatasetController::unsafeFilenameReason("sales.csv\0.php"));
        $this->assertNotNull(DatasetController::unsafeFilenameReason('.hidden.csv'));
        $this->assertNotNull(DatasetController::unsafeFilenameReason('sales.php.csv'));
        $this->assertNotNull(DatasetController::unsafeFilenameReason('report.txt.sh.csv'));

        $this->assertNull(DatasetController::unsafeFilenameReason('sales.csv'));
        $this->assertNull(DatasetController::unsafeFilenameReason('penjualan retail 2026.xlsx'));
        $this->assertNull(DatasetController::unsafeFilenameReason('2026.09.sales.csv'));

        $this->assertSame('sales.csv', DatasetController::sanitizeFilename('sales.csv'));
        $this->assertSame('evil.csv', DatasetController::sanitizeFilename('../evil.csv'));
    }

    public function test_over_long_name_is_rejected_and_valid_upload_still_succeeds(): void
    {
        $analyst = $this->user('analyst');

        $this->actingAs($analyst)
            ->from(route('datasets.create'))
            ->post(route('datasets.store'), [
                'file' => UploadedFile::fake()->createWithContent('sales.csv', "a,b\n1,2\n"),
                'name' => str_repeat('a', 200),
                'dataset_type' => 'sales',
            ])
            ->assertRedirect(route('datasets.create'))
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('datasets', 0);

        $this->actingAs($analyst)
            ->post(route('datasets.store'), [
                'file' => UploadedFile::fake()->createWithContent('sales.csv', "a,b\n1,2\n"),
                'dataset_type' => 'sales',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $dataset = Dataset::firstOrFail();
        $this->assertSame('sales.csv', $dataset->source_filename);
        Storage::disk('local')->assertExists($dataset->path);
    }

    public function test_max_upload_mb_is_enforced_as_megabytes_not_kilobytes(): void
    {
        // 1 MB budget: a 12-byte CSV must pass. Under the old
        // `'max:'.config('ai_engine.max_upload_mb')` spelling the rule meant
        // 1 KB and this upload failed.
        config(['ai_engine.max_upload_mb' => 1]);
        Sanctum::actingAs($this->user('analyst'));

        $this->post(route('api.datasets.store'), [
            'file' => UploadedFile::fake()->createWithContent('sales.csv', "a,b\n1,2\n"),
            'dataset_type' => 'sales',
        ])->assertCreated();

        $this->assertDatabaseCount('datasets', 1);
    }

    // ------------------------------------------------------------------
    // SecurityHeaders (isolated — master owns global wiring)
    // ------------------------------------------------------------------

    public function test_security_headers_are_set_and_hsts_only_on_https(): void
    {
        $middleware = new SecurityHeaders;

        $plain = $middleware->handle(Request::create('/datasets', 'GET'), fn (): Response => response('ok'));
        $this->assertSame('nosniff', $plain->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $plain->headers->get('X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $plain->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('camera=()', (string) $plain->headers->get('Permissions-Policy'));
        $this->assertFalse($plain->headers->has('Strict-Transport-Security'));

        $secure = $middleware->handle(
            Request::create('https://example.co.id/datasets', 'GET'),
            fn (): Response => response('ok')
        );
        $this->assertTrue($secure->headers->has('Strict-Transport-Security'));
        $this->assertStringContainsString('max-age=31536000', (string) $secure->headers->get('Strict-Transport-Security'));
    }

    // ------------------------------------------------------------------
    // Policy matrix (pure — not yet enforced, see policy docblocks)
    // ------------------------------------------------------------------

    protected function datasetFor(User $owner): Dataset
    {
        $dataset = new Dataset;
        $dataset->forceFill([
            'uuid' => (string) Str::uuid(),
            'name' => 'Penjualan',
            'dataset_type' => 'sales',
            'source_filename' => 'sales.csv',
            'disk' => 'local',
            'path' => 'datasets/sales.csv',
            'size_bytes' => 12,
            'status' => 'uploaded',
            'user_id' => $owner->getKey(),
        ]);

        return $dataset;
    }

    protected function threadFor(User $owner): ChatThread
    {
        $thread = new ChatThread;
        $thread->forceFill([
            'title' => 'Analisis',
            'user_id' => $owner->getKey(),
        ]);

        return $thread;
    }

    public function test_dataset_policy_owner_or_admin_may_write_delete_viewer_may_not(): void
    {
        $policy = new DatasetPolicy;
        $owner = $this->user('analyst');
        $otherAnalyst = $this->user('analyst');
        $admin = $this->user('admin');
        $viewer = $this->user('viewer');
        $dataset = $this->datasetFor($owner);

        // Read is global for any active account (mirrors index/show today).
        $this->assertTrue($policy->view($viewer, $dataset));
        $this->assertTrue($policy->viewAny($viewer));

        $this->assertTrue($policy->update($owner, $dataset));
        $this->assertTrue($policy->delete($owner, $dataset));
        $this->assertTrue($policy->update($admin, $dataset));
        $this->assertTrue($policy->delete($admin, $dataset));

        $this->assertFalse($policy->update($otherAnalyst, $dataset));
        $this->assertFalse($policy->delete($otherAnalyst, $dataset));
        $this->assertFalse($policy->update($viewer, $dataset));
        $this->assertFalse($policy->delete($viewer, $dataset));
        $this->assertFalse($policy->create($viewer));

        $inactiveAdmin = $this->user('admin', active: false);
        $this->assertFalse($policy->view($inactiveAdmin, $dataset));
        $this->assertFalse($policy->update($inactiveAdmin, $dataset));
    }

    public function test_chat_thread_policy_is_owner_only_even_for_admins(): void
    {
        $policy = new ChatThreadPolicy;
        $owner = $this->user('analyst');
        $admin = $this->user('admin');
        $thread = $this->threadFor($owner);

        $this->assertTrue($policy->view($owner, $thread));
        $this->assertTrue($policy->delete($owner, $thread));

        // Mirrors abort_unless(..., 404): no admin bypass, existence undisclosed.
        $this->assertFalse($policy->view($admin, $thread));
        $this->assertFalse($policy->update($admin, $thread));
        $this->assertFalse($policy->delete($admin, $thread));

        $inactive = $this->user('analyst', active: false);
        $ownThread = $this->threadFor($inactive);
        $this->assertFalse($policy->view($inactive, $ownThread));
    }

    public function test_user_can_helper_matches_the_matrix_and_grants_nothing_unknown(): void
    {
        $owner = $this->user('analyst');
        $other = $this->user('analyst');
        $admin = $this->user('admin');
        $viewer = $this->user('viewer');
        $dataset = $this->datasetFor($owner);
        $thread = $this->threadFor($owner);

        $this->assertTrue($owner->canDo('datasets.view'));
        $this->assertTrue($owner->canDo('datasets.write', $dataset));
        $this->assertFalse($other->canDo('datasets.write', $dataset));
        $this->assertTrue($admin->canDo('datasets.delete', $dataset));
        $this->assertFalse($viewer->canDo('datasets.write', $dataset));

        $this->assertTrue($owner->canDo('threads.view', $thread));
        $this->assertFalse($admin->canDo('threads.view', $thread));

        $this->assertTrue($admin->canDo('users.manage'));
        $this->assertFalse($owner->canDo('users.manage'));
        $this->assertTrue($admin->canDo('audit.view'));
        $this->assertFalse($viewer->canDo('audit.view'));
        $this->assertTrue($admin->canDo('models.approve'));
        $this->assertFalse($owner->canDo('models.approve'));

        $this->assertTrue($viewer->canDo('agent.chat'));
        $this->assertFalse($this->user('analyst', active: false)->canDo('agent.chat'));
        $this->assertFalse($viewer->canDo('no.such.action'));
    }

    // ------------------------------------------------------------------
    // PII masking
    // ------------------------------------------------------------------

    public function test_pii_mask_masks_emails_phones_text_and_arrays(): void
    {
        $this->assertSame('b***@example.co.id', PiiMask::maskEmail('budi@example.co.id'));
        $this->assertSame('', PiiMask::maskEmail(''));
        $this->assertSame('***', PiiMask::maskEmail('not-an-email'));

        $maskedPhone = PiiMask::maskPhone('0812-3456-7890');
        $this->assertStringEndsWith('90', $maskedPhone);
        $this->assertStringNotContainsString('0812', $maskedPhone);

        $text = PiiMask::maskText('hubungi budi@example.co.id atau 0812-3456-7890 ya');
        $this->assertStringNotContainsString('budi@example.co.id', $text);
        $this->assertStringNotContainsString('0812-3456-7890', $text);
        $this->assertStringContainsString('ya', $text);

        $row = PiiMask::maskArray([
            'email' => 'ani@example.co.id',
            'telepon' => '0813-2211-0045',
            'kota' => 'Bandung',
        ]);
        $this->assertSame('a***@example.co.id', $row['email']);
        $this->assertStringNotContainsString('0813', $row['telepon']);
        $this->assertSame('Bandung', $row['kota']);
    }
}
