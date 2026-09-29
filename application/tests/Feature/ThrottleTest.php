<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pins the four limiters in `AppServiceProvider::configureRateLimiters()`.
 *
 * The boundaries are asserted directly instead of with a `sleep()`: the
 * window is one minute, and a test that waits for it is a test nobody runs.
 * A fresh `array` cache store per test plus an explicit flush in `setUp()`
 * keeps one test's counter out of the next test's budget.
 */
class ThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected User $analyst;

    /**
     * Read at request time, not at fake-registration time: a later `Http::fake()`
     * call cannot override an earlier stub (stub callbacks are matched
     * first-registered-wins), so per-test variation has to go through state.
     */
    protected int $engineConversationId = 88;

    protected function setUp(): void
    {
        parent::setUp();

        // The limiter counters live in the cache. Without an array store the
        // suite shares one counter across every test in the file, and the first
        // test to spend the budget silently starves the rest.
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
        RateLimiter::clear('');

        $this->analyst = User::factory()->analyst()->create();
        Storage::fake('local');
        $this->fakeEngine();
    }

    /**
     * A single closure stub rather than a URL map: `Http::response()` returns a
     * promise, and stub callbacks are resolved first-registered-wins, so a
     * second `Http::fake()` in a test could never override a map registered in
     * `setUp()`.
     */
    protected function fakeEngine(): void
    {
        Http::fake(function (ClientRequest $request) {
            $url = $request->url();

            $payload = match (true) {
                str_ends_with($url, '/imports/upload') => [
                    'upload_id' => 1,
                    'import_job_id' => 42,
                    'validation' => [
                        'ok' => true,
                        'meta' => [
                            'size_bytes' => 2048,
                            'mime' => 'text/csv',
                            'checksum_sha256' => 'abc123',
                        ],
                    ],
                    'stored_path' => '/data/storage/sales.csv',
                ],
                str_ends_with($url, '/ai/chat') => [
                    'answer' => 'Pendapatan naik 4%.',
                    'conversation_id' => $this->engineConversationId,
                    'evidence' => [],
                    'steps' => 2,
                ],
                default => [],
            };

            return Http::response(['success' => true, 'data' => $payload], 200);
        });
    }

    protected function account(string $email, string $role = 'admin'): User
    {
        return User::factory()->create([
            'name' => 'Login Target',
            'email' => $email,
            'password' => Hash::make('Secret123!'),
            'role' => $role,
        ]);
    }

    /**
     * One wrong-password attempt. It cannot log in, so the `guest` middleware
     * keeps letting the next attempt through and the only thing that can refuse
     * it is the limiter.
     */
    protected function failedLogin(string $email): TestResponse
    {
        return $this->from(route('login'))->post(route('login.store'), [
            'email' => $email,
            'password' => 'wrong-password',
        ]);
    }

    // ------------------------------------------------------------------
    // login — 5/min keyed on email AND IP
    // ------------------------------------------------------------------

    public function test_the_fifth_login_attempt_is_served_and_the_sixth_is_throttled(): void
    {
        $this->account('admin@example.com');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->failedLogin('admin@example.com')
                ->assertStatus(302)
                ->assertSessionHasErrors('email');
        }

        $this->failedLogin('admin@example.com')->assertStatus(429);
    }

    public function test_a_different_email_from_the_same_ip_is_not_throttled_by_the_first(): void
    {
        $this->account('admin@example.com');
        $analyst = $this->account('analyst@example.com');

        // Spend the whole admin@example.com budget from this one IP.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->failedLogin('admin@example.com')
                ->assertStatus(302)
                ->assertSessionHasErrors('email');
        }

        $this->failedLogin('admin@example.com')->assertStatus(429);

        // The whole point of keying on the pair instead of the IP alone: one
        // attacker must not be able to lock every account on the network out
        // by failing their own password repeatedly.
        $this->from(route('login'))
            ->post(route('login.store'), [
                'email' => 'analyst@example.com',
                'password' => 'Secret123!',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($analyst);
    }

    public function test_the_login_limiter_is_case_insensitive_on_the_email(): void
    {
        $this->account('admin@example.com');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->failedLogin('admin@example.com')->assertStatus(302);
        }

        // `strtolower()` in the key: `Admin@Example.com` is the same account
        // and must not mint a fresh bucket.
        $this->failedLogin('Admin@Example.com')->assertStatus(429);
    }

    public function test_the_login_limiter_also_covers_the_api_login_route(): void
    {
        $this->account('admin@example.com');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson(route('api.login'), [
                'email' => 'admin@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson(route('api.login'), [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    // ------------------------------------------------------------------
    // api — 120/min
    // ------------------------------------------------------------------

    public function test_the_api_ceiling_serves_120_requests_and_throttles_the_121st(): void
    {
        Sanctum::actingAs($this->analyst);

        for ($request = 1; $request <= 120; $request++) {
            $this->getJson(route('api.me'))
                ->assertOk()
                ->assertHeader('X-RateLimit-Remaining', (string) (120 - $request));
        }

        $this->getJson(route('api.me'))->assertStatus(429);
    }

    public function test_the_api_ceiling_answers_429_with_the_documented_shape(): void
    {
        Sanctum::actingAs($this->analyst);

        for ($request = 1; $request <= 120; $request++) {
            $this->getJson(route('api.me'));
        }

        $response = $this->getJson(route('api.me'));

        $response->assertStatus(429)
            ->assertJsonPath('message', 'Too Many Attempts.')
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('X-RateLimit-Limit', '120')
            ->assertHeader('X-RateLimit-Remaining', '0');

        // A client that only reads the status has no idea whether to retry in
        // a second or in a minute.
        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertNotNull($response->headers->get('X-RateLimit-Reset'));
    }

    public function test_the_api_ceiling_is_counted_per_account_not_globally(): void
    {
        Sanctum::actingAs($this->analyst);

        for ($request = 1; $request <= 120; $request++) {
            $this->getJson(route('api.me'));
        }

        $this->getJson(route('api.me'))->assertStatus(429);

        // The key is the authenticated actor, so one noisy client cannot
        // exhaust everyone else's ceiling.
        Sanctum::actingAs(User::factory()->analyst()->create());

        $this->getJson(route('api.me'))
            ->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '119');
    }

    // ------------------------------------------------------------------
    // upload — 20/min
    // ------------------------------------------------------------------

    public function test_the_upload_limiter_serves_20_uploads_and_throttles_the_21st(): void
    {
        Sanctum::actingAs($this->analyst);

        for ($upload = 1; $upload <= 20; $upload++) {
            $this->post(route('api.datasets.store'), [
                'file' => UploadedFile::fake()->createWithContent('sales.csv', "a,b\n1,2\n"),
                'dataset_type' => 'sales',
            ])->assertCreated()
                ->assertHeader('X-RateLimit-Remaining', (string) (20 - $upload));
        }

        $this->post(route('api.datasets.store'), [
            'file' => UploadedFile::fake()->createWithContent('sales.csv', "a,b\n1,2\n"),
            'dataset_type' => 'sales',
        ])->assertStatus(429);

        // Each upload is a synchronous round trip to the engine, so the 21st
        // must never reach it.
        Http::assertSentCount(20);
    }

    public function test_the_upload_limiter_covers_the_web_upload_route_too(): void
    {
        $this->actingAs($this->analyst);

        for ($upload = 1; $upload <= 20; $upload++) {
            $this->post(route('datasets.store'), [
                'file' => UploadedFile::fake()->createWithContent('sales.csv', "a,b\n1,2\n"),
                'dataset_type' => 'sales',
            ])->assertSessionHasNoErrors();
        }

        $this->post(route('datasets.store'), [
            'file' => UploadedFile::fake()->createWithContent('sales.csv', "a,b\n1,2\n"),
            'dataset_type' => 'sales',
        ])->assertStatus(429);
    }

    // ------------------------------------------------------------------
    // expensive — 10/min
    // ------------------------------------------------------------------

    public function test_the_expensive_limiter_serves_ten_llm_calls_and_throttles_the_eleventh(): void
    {
        Sanctum::actingAs($this->analyst);

        for ($call = 1; $call <= 10; $call++) {
            $this->postJson(route('api.agent.chat'), ['message' => 'Pertanyaan '.$call])
                ->assertOk()
                ->assertHeader('X-RateLimit-Remaining', (string) (10 - $call));
        }

        $this->postJson(route('api.agent.chat'), ['message' => 'Pertanyaan 11'])->assertStatus(429);

        // The tenth call is the last one that reaches the engine; an LLM round
        // trip is the most expensive thing this platform does.
        Http::assertSentCount(10);
    }

    public function test_the_expensive_limiter_covers_the_rag_endpoint(): void
    {
        Sanctum::actingAs($this->analyst);

        for ($call = 1; $call <= 10; $call++) {
            $this->postJson(route('api.rag.query'), ['question' => 'Apa penjualan Q1?'])
                ->assertOk();
        }

        $this->postJson(route('api.rag.query'), ['question' => 'Apa penjualan Q1?'])
            ->assertStatus(429);
    }

    public function test_the_expensive_limiter_is_tighter_than_the_api_ceiling(): void
    {
        Sanctum::actingAs($this->analyst);

        for ($call = 1; $call <= 10; $call++) {
            $this->postJson(route('api.agent.chat'), ['message' => 'Pertanyaan '.$call])->assertOk();
        }

        // A plain read is still well inside its own 120/min budget: the two
        // limiters are independent buckets, not one shared counter.
        $this->getJson(route('api.me'))
            ->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '109');
    }
}
