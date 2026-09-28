<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a deactivated account everywhere, not only on `role:`-gated routes.
 *
 * `EnsureRole` covers the write and admin routes. Without this, deactivating a
 * user removed their ability to train a model but left them able to read every
 * dashboard, dataset and audit row they had before.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'This account is deactivated.',
                    'code' => 'account_inactive',
                ], 403);
            }

            // 403, not a logout: silently invalidating the session on every
            // read page would sign a deactivated user out mid-navigation and
            // turn a permission answer into a confusing redirect. Re-login is
            // already refused by AuthController::store().
            return response()->view('errors.inactive', [], 403);
        }

        return $next($request);
    }
}
