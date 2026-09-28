@extends('layouts.app')

@section('title', 'Pengguna')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-slate-900">Manajemen pengguna</h1>
        <p class="mt-1 text-sm text-slate-500">
            Tambah akun, ubah peran, aktifkan atau nonaktifkan akun, dan hapus pengguna yang tidak lagi dibutuhkan.
        </p>
    </div>

    <x-card class="mb-6" title="Filter" description="Cari pengguna berdasarkan nama atau email, atau saring berdasarkan peran.">
        <form method="GET" action="{{ route('admin.users.index') }}" class="grid gap-4 sm:grid-cols-3">
            <x-field label="Cari" for="q" hint="Mencocokkan nama dan email pengguna.">
                <input
                    id="q"
                    name="q"
                    type="search"
                    value="{{ $filters['q'] ?? '' }}"
                    placeholder="mis. analyst"
                    @error('q') aria-invalid="true" @enderror
                    class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                >
            </x-field>

            <x-field label="Peran" for="role">
                <select
                    id="role"
                    name="role"
                    class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                >
                    <option value="">Semua peran</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role->value }}" @selected(($filters['role'] ?? '') === $role->value)>{{ $role->label() }}</option>
                    @endforeach
                </select>
            </x-field>

            <div class="flex items-end gap-2">
                <button
                    type="submit"
                    class="inline-flex flex-1 items-center justify-center rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                >Terapkan</button>
                <a
                    href="{{ route('admin.users.index') }}"
                    class="inline-flex items-center justify-center rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                >Reset</a>
            </div>
        </form>
    </x-card>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-card title="Daftar pengguna" description="{{ number_format($users->total()) }} akun terdaftar.">
                @if ($users->isEmpty())
                <x-empty-state
                    title="Belum ada pengguna"
                    description="Gunakan formulir di samping untuk membuat akun pertama."
                />
            @else
                <ul class="space-y-4">
                    @foreach ($users as $user)
                        @php $isSelf = $user->getKey() === auth()->id(); @endphp
                        <li class="rounded-lg border border-slate-200 p-4">
                            <div class="grid gap-4 lg:grid-cols-2">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-slate-900">
                                        {{ $user->name }}
                                        @if ($isSelf)
                                            <span class="ml-1 text-xs font-normal text-slate-500">(Anda)</span>
                                        @endif
                                    </p>
                                    <p class="mt-1 break-all text-sm text-slate-500">{{ $user->email }}</p>

                                    <dl class="mt-3 space-y-2 text-sm">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <dt class="font-medium text-slate-500">Peran</dt>
                                            <dd><x-badge variant="info">{{ $user->role()->label() }}</x-badge></dd>
                                        </div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <dt class="font-medium text-slate-500">Status</dt>
                                            <dd>
                                                @if ($user->is_active)
                                                    <x-badge variant="success">Aktif</x-badge>
                                                @else
                                                    <x-badge variant="danger">Nonaktif</x-badge>
                                                @endif
                                            </dd>
                                        </div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <dt class="font-medium text-slate-500">Login terakhir</dt>
                                            <dd class="text-slate-700">
                                                {{ $user->last_login_at ? $user->last_login_at->locale('id')->translatedFormat('d M Y H:i') : 'Belum pernah' }}
                                            </dd>
                                        </div>
                                    </dl>
                                </div>

                                <form method="POST" action="{{ route('admin.users.update', $user) }}" class="space-y-3 border-t border-slate-100 pt-4 lg:border-l lg:border-t-0 lg:pl-4 lg:pt-0">
                                    @csrf
                                    @method('PATCH')

                                    <p class="text-sm font-semibold text-slate-900">Ubah akun</p>

                                    <x-field label="Nama" for="name-{{ $user->getKey() }}" name="name" required>
                                        <input
                                            id="name-{{ $user->getKey() }}"
                                            name="name"
                                            type="text"
                                            value="{{ old('name', $user->name) }}"
                                            required
                                            maxlength="100"
                                            @error('name') aria-invalid="true" @enderror
                                            class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                                        >
                                    </x-field>

                                    <x-field label="Email" for="email-{{ $user->getKey() }}" name="email" required>
                                        <input
                                            id="email-{{ $user->getKey() }}"
                                            name="email"
                                            type="email"
                                            value="{{ old('email', $user->email) }}"
                                            required
                                            @error('email') aria-invalid="true" @enderror
                                            class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                                        >
                                    </x-field>

                                    <x-field label="Peran" for="role-{{ $user->getKey() }}" name="role" required>
                                        <select
                                            id="role-{{ $user->getKey() }}"
                                            name="role"
                                            required
                                            @error('role') aria-invalid="true" @enderror
                                            class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                                        >
                                            @foreach ($roles as $role)
                                                <option value="{{ $role->value }}" @selected(old('role', $user->role->value) === $role->value)>
                                                    {{ $role->label() }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </x-field>

                                    <div class="flex items-center gap-2">
                                        {{-- The controller validates is_active as `sometimes`,
                                             so an unchecked box that sends nothing would leave the
                                             account active. The hidden field is what carries the
                                             "0" when the box is cleared. --}}
                                        <input type="hidden" name="is_active" value="0">
                                        <input
                                            id="is_active-{{ $user->getKey() }}"
                                            name="is_active"
                                            type="checkbox"
                                            value="1"
                                            @checked(old('is_active', $user->is_active ? '1' : '0') === '1')
                                            class="h-4 w-4 rounded border-slate-300 text-brand-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                                        >
                                        <label for="is_active-{{ $user->getKey() }}" class="text-sm text-slate-700">Akun aktif dan dapat login</label>
                                    </div>

                                    <button
                                        type="submit"
                                        class="inline-flex w-full items-center justify-center rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                                    >Simpan perubahan</button>

                                    @if ($isSelf)
                                        <p class="text-xs text-slate-500">
                                            Akun yang sedang digunakan tidak dapat dihapus. Nonaktifkan bila perlu.
                                        </p>
                                    @else
                                        <button
                                            type="submit"
                                            form="delete-user-{{ $user->getKey() }}"
                                            class="inline-flex w-full items-center justify-center rounded-md border border-rose-600 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2"
                                        >Hapus pengguna</button>
                                    @endif
                                </form>
                            </div>

                            @unless ($isSelf)
                                <form
                                    id="delete-user-{{ $user->getKey() }}"
                                    method="POST"
                                    action="{{ route('admin.users.destroy', $user) }}"
                                    x-on:submit.confirm="Hapus pengguna ini? Tindakan ini tidak dapat dibatalkan."
                                >
                                    @csrf
                                    @method('DELETE')
                                </form>
                            @endunless
                        </li>
                    @endforeach
                </ul>


                <div class="mt-4">
                    {{ $users->links() }}
                </div>
            @endif
        </x-card>

        <div class="space-y-6">
            <x-card title="Tambah pengguna" description="Password minimal 8 karakter.">
                <form method="POST" action="{{ route('admin.users.store') }}" class="space-y-4">
                    @csrf

                    <x-field label="Nama" for="new-name" name="name" required>
                        <input
                            id="new-name"
                            name="name"
                            type="text"
                            value="{{ old('name') }}"
                            required
                            maxlength="100"
                            @error('name') aria-invalid="true" @enderror
                            class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                        >
                    </x-field>

                    <x-field label="Email" for="new-email" name="email" required>
                        <input
                            id="new-email"
                            name="email"
                            type="email"
                            value="{{ old('email') }}"
                            required
                            autocomplete="off"
                            @error('email') aria-invalid="true" @enderror
                            class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                        >
                    </x-field>

                    <x-field label="Password" for="new-password" name="password" required>
                        <input
                            id="new-password"
                            name="password"
                            type="password"
                            required
                            autocomplete="new-password"
                            @error('password') aria-invalid="true" @enderror
                            class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                        >
                    </x-field>

                    <x-field label="Peran" for="new-role" name="role" required hint="Setiap peran punya kewenangan berbeda pada platform.">
                        <select
                            id="new-role"
                            name="role"
                            required
                            @error('role') aria-invalid="true" @enderror
                            class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                        >
                            @foreach ($roles as $role)
                                <option value="{{ $role->value }}" @selected(old('role') === $role->value)>
                                    {{ $role->label() }} - {{ $role->description() }}
                                </option>
                            @endforeach
                        </select>
                    </x-field>

                    <button
                        type="submit"
                        class="inline-flex w-full items-center justify-center rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                    >Tambah pengguna</button>
                </form>
            </x-card>

            <x-card title="Peran dan akses" description="Kewenangan setiap peran dalam platform.">
                <ul class="space-y-3 text-sm">
                    @foreach ($roles as $role)
                        <li>
                            <p class="font-semibold text-slate-900">{{ $role->label() }}</p>
                            <p class="mt-1 text-slate-600">{{ $role->description() }}</p>
                        </li>
                    @endforeach
                </ul>
            </x-card>
        </div>
    </div>
@endsection
