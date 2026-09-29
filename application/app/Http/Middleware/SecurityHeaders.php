<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defence-in-depth response headers for the Laravel application.
 *
 * Nginx (`infrastructure/nginx/default.conf`, read-only for A8) already sets
 * these on the proxy hop; this middleware sets them at the application layer
 * so direct-to-Laravel traffic (local dev, tests, a mis-bound port) gets the
 * same baseline. Values mirror the nginx block:
 *
 * - `X-Content-Type-Options: nosniff`
 * - `X-Frame-Options: DENY` (strict; nginx uses SAMEORIGIN on its hop)
 * - `Referrer-Policy: strict-origin-when-cross-origin`
 * - `Permissions-Policy` locking the privacy-sensitive features
 * - `Strict-Transport-Security` ONLY when the request itself is HTTPS, so
 *   plain-HTTP dev/test never emits an HSTS pin for localhost.
 *
 * NOTE (master wiring, AppServiceProvider/bootstrap is master-owned — A8 must
 * not edit it). Register LAST in the global stack so headers survive error
 * responses rendered by the exception handler:
 *
 *   // bootstrap/app.php, inside ->withMiddleware(...):
 *   $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=()'
        );

        if ($request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        return $response;
    }
}
