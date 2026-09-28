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

        $allowed = array_map(
            static fn (string $role): UserRole => UserRole::tryFromName($role),
            $roles,
        );

        if (! in_array($user->role(), $allowed, true)) {
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
