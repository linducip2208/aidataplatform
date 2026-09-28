<?php

namespace App\Providers;

use App\Services\AiEngineClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AiEngineClient::class, static fn (): AiEngineClient => AiEngineClient::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiters();
    }

    /**
     * The application had no limiter at all: neither the web nor the token
     * login was throttled, so online password guessing against the three known
     * seeded accounts (admin@example.com and friends) was unthrottled.
     */
    private function configureRateLimiters(): void
    {
        // Keyed on the email *and* the IP, so one attacker cannot lock a real
        // user out of their own account by failing their password repeatedly.
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(5)->by(
            strtolower((string) $request->input('email')).'|'.$request->ip()
        ));

        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)->by($this->actor($request)));

        // Each upload is a synchronous round trip to the engine.
        RateLimiter::for('upload', fn (Request $request): Limit => Limit::perMinute(20)->by($this->actor($request)));

        // An LLM call and a model training run are the two expensive endpoints.
        RateLimiter::for('expensive', fn (Request $request): Limit => Limit::perMinute(10)->by($this->actor($request)));
    }

    private function actor(Request $request): string
    {
        return (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());
    }
}
