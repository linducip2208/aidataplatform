<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The token API lives under the `api/` prefix and answers JSON, always.
 *
 * Without this, a client that omits `Accept: application/json` gets Laravel's
 * browser-shaped behaviour instead: a validation failure comes back as a 302
 * redirect back, an unauthenticated call as a 302 to the login page, and a 404
 * as an HTML page. An API client cannot parse any of that, and the failure looks
 * like a network problem rather than a missing header.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/*')) {
            // Forced, not defaulted: curl, browsers and most HTTP clients send
            // `Accept: */*`, and `expectsJson()` is false for that, so a
            // validation failure would come back as a 302 redirect back and an
            // unauthenticated call as a 302 to the login page.
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
