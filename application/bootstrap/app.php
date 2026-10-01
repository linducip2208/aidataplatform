<?php

use App\Exceptions\AiEngineException;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureAiBudget;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => EnsureRole::class,
            'ai.budget' => EnsureAiBudget::class,
        ]);

        $middleware->append(ForceJsonResponse::class);
        $middleware->append(SecurityHeaders::class);

        // Every authenticated route, not just the role-gated ones: a
        // deactivated account must lose read access too, not only the ability
        // to write.
        $middleware->web(append: [
            SetLocale::class,
            EnsureAccountActive::class,
        ]);
        $middleware->api(append: [
            EnsureAccountActive::class,
            // A blanket ceiling on the token API; the specific routes below
            // carry the tighter limits.
            'throttle:api',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // The routing pipeline renders exceptions itself, so a global middleware
        // try/catch never sees them: this renderable is the only place an
        // upstream engine failure can be turned into a usable response.
        $exceptions->render(function (AiEngineException $exception, Request $request) {
            $status = $exception->statusForClient();

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'code' => 'ai_engine_error',
                    'operation' => $exception->operation(),
                ], $status);
            }

            if ($request->isMethod('GET')) {
                return response()->view('errors.engine', [
                    'message' => $exception->getMessage(),
                    'operation' => $exception->operation(),
                ], $status);
            }

            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        });
    })->create();
