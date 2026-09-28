<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $users = User::query()
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = '%'.$request->string('q')->trim().'%';
                $query->where(function ($inner) use ($term): void {
                    $inner->whereLike('name', $term, caseSensitive: false)
                        ->orWhereLike('email', $term, caseSensitive: false);
                });
            })
            ->when($request->filled('role'), function ($query) use ($request): void {
                $query->where('role', (string) $request->query('role'));
            })
            ->orderBy('id')
            ->paginate(ApiResponse::perPage())
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'roles' => UserRole::cases(),
            'filters' => $request->only(['q', 'role']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', Password::min(8)->letters()->numbers()],
            'role' => ['required', 'string', 'in:'.implode(',', UserRole::values())],
        ], [], [
            'name' => 'nama',
            'email' => 'email',
            'password' => 'kata sandi',
            'role' => 'peran',
        ]);

        $user = User::create($validated);

        AuditLog::record('user.created', 'user', $user->getKey(), [
            'role' => $user->role()->value,
        ]);

        return redirect()
            ->route('admin.users.index')
            ->with('status', "Pengguna {$user->name} dibuat.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => ['sometimes', 'required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->getKey())],
            'role' => ['sometimes', 'required', 'string', 'in:'.implode(',', UserRole::values())],
            'is_active' => ['sometimes', 'boolean'],
        ], [], [
            'name' => 'nama',
            'email' => 'email',
            'role' => 'peran',
            'is_active' => 'status aktif',
        ]);

        // The admin form resubmits every field, so the payload is not the
        // change set. Diffing against the stored values keeps the audit trail
        // honest: an edit that changed nothing must not read as three changes.
        $changed = [];

        foreach ($validated as $field => $value) {
            // `role` is cast to a backed enum, which cannot be cast to string
            // directly; compare the backing values.
            $current = match ($field) {
                'is_active' => (bool) $user->{$field},
                'role' => $user->role()->value,
                default => $user->{$field},
            };
            $after = $field === 'is_active' ? (bool) $value : $value;

            if ((string) $current !== (string) $after) {
                $changed[$field] = ['from' => $current, 'to' => $after];
            }
        }

        $user->fill($validated);

        if ($changed !== []) {
            $user->save();

            AuditLog::record('user.updated', 'user', $user->getKey(), [
                'fields' => array_keys($changed),
                'changes' => $changed,
            ]);
        }

        return back()->with('status', "Pengguna {$user->name} diperbarui.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'Anda tidak dapat menghapus akun sendiri.');
        }

        if ($user->isAdmin() && User::where('role', UserRole::Admin->value)->count() <= 1) {
            return back()->with('error', 'Minimal satu administrator harus tetap ada.');
        }

        $name = $user->name;

        AuditLog::record('user.deleted', 'user', $user->getKey(), [
            'name' => $name,
            'role' => $user->role()->value,
        ]);

        $user->delete();

        return back()->with('status', "Pengguna {$name} dihapus.");
    }
}
