<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the request locale: authenticated user's saved preference first,
 * then the session choice, then the application default.
 *
 * Only `en` and `id` are supported; anything else falls back without
 * failing the request. The default locale is Indonesian (`id`) since the
 * product copy is Indonesian-first.
 */
class SetLocale
{
    public const SUPPORTED = ['en', 'id'];

    public const SESSION_KEY = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolve($request));

        return $next($request);
    }

    public function resolve(Request $request): string
    {
        $user = $request->user();

        if ($user && is_string($user->locale) && $user->locale !== '') {
            $candidate = strtolower(trim($user->locale));

            if (in_array($candidate, self::SUPPORTED, true)) {
                return $candidate;
            }
        }

        $session = $request->hasSession() ? (string) $request->session()->get(self::SESSION_KEY, '') : '';

        if (in_array(strtolower(trim($session)), self::SUPPORTED, true)) {
            return strtolower(trim($session));
        }

        $configured = strtolower(trim((string) config('app.locale', 'id')));

        return in_array($configured, self::SUPPORTED, true) ? $configured : 'id';
    }

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && in_array(strtolower(trim($locale)), self::SUPPORTED, true);
    }
}
