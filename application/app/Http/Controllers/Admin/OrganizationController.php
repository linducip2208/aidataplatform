<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class OrganizationController extends Controller
{
    public function edit(): View
    {
        $organization = Organization::current()
            ?? new Organization([
                'name' => (string) config('app.name', 'AIDataPlatform'),
            ]);

        return view('admin.organization.edit', [
            'organization' => $organization,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg', 'max:1024'],
            'remove_logo' => ['nullable', 'boolean'],
        ], [], [
            'name' => 'nama organisasi',
            'tagline' => 'tagline',
            'logo' => 'logo',
        ]);

        $organization = Organization::current() ?? new Organization;

        $logoPath = $organization->logo_path;

        if (($validated['remove_logo'] ?? false) || $request->hasFile('logo')) {
            if (is_string($logoPath) && $logoPath !== '') {
                Storage::disk('public')->delete($logoPath);
            }

            $logoPath = null;
        }

        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->store('organization', 'public');
        }

        $organization->forceFill([
            'name' => $validated['name'],
            'tagline' => $validated['tagline'] ?? null,
            'logo_path' => $logoPath,
        ])->save();

        Organization::forgetCurrent();

        AuditLog::record('organization.updated', 'organization', $organization->getKey(), [
            'name' => $validated['name'],
        ]);

        return back()->with('status', "Profil organisasi '{$validated['name']}' disimpan.");
    }
}
