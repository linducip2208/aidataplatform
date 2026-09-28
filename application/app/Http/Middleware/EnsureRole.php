<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Restrict a route to one or more roles: `->middleware('role:admin')` or
     * `->middleware('role:admin,analyst')`.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Unauthenticated.'], 401)
                : redirect()->guest(route('login'));
        }

        if (! $user->is_active) {
            return $request->expectsJson()
                ? response()->json(['message' => 'This account is deactivated.'], 403)
                : abort(403, 'This account is deactivated.');
        }

        // `UserRole::tryFromName()` maps anything unrecognised to Viewer, so
        // running the allowed-list through it makes a typo'd or stale role
        // string widen the route instead of narrowing it: `role:admin,analist`
        // resolves to [Admin, Viewer] and every viewer walks in. Resolve with
        // `tryFrom()` and refuse the whole list when any entry is unknown.
        $allowed = [];

        foreach ($roles as $role) {
            $resolved = UserRole::tryFrom(trim($role));

            if ($resolved === null) {
                return $request->expectsJson()
                    ? response()->json([
                        'message' => 'Misconfigured role guard: unknown role "'.$role.'".',
                        'code' => 'forbidden',
                    ], 403)
                    : abort(403, 'Misconfigured role guard: unknown role "'.$role.'".');
            }

            $allowed[] = $resolved;
        }

        if ($allowed === [] || ! in_array($user->role(), $allowed, true)) {
            return $request->expectsJson()
                ? response()->json([
                    'message' => 'This action requires role: '.implode(' or ', $roles).'.',
                    'code' => 'forbidden',
                ], 403)
                : abort(403, 'This action requires role: '.implode(' or ', $roles).'.');
        }

        return $next($request);
    }
}
