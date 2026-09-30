<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', 'in:en,id'],
        ]);

        $locale = strtolower(trim($validated['locale']));

        $request->session()->put(SetLocale::SESSION_KEY, $locale);

        $user = $request->user();

        if ($user) {
            $user->forceFill(['locale' => $locale])->save();

            AuditLog::record('account.locale_changed', 'user', $user->getKey(), [
                'locale' => $locale,
            ]);
        }

        return back()->with('status', __('nav.language_saved'));
    }
}
