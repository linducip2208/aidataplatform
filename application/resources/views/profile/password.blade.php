@extends('layouts.app')

@section('title', 'Ubah password')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-slate-900">Ubah password</h1>
        <p class="mt-1 text-sm text-slate-500">
            Password baru minimal 8 karakter dan harus memuat huruf serta angka.
        </p>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-card class="lg:col-span-2" title="Password akun" description="Masukkan password lama untuk memverifikasi perubahan.">
            <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
                @csrf
                @method('PUT')

                <x-field label="Password saat ini" for="current_password" name="current_password" required>
                    <input
                        id="current_password"
                        name="current_password"
                        type="password"
                        autocomplete="current-password"
                        required
                        @error('current_password') aria-invalid="true" @enderror
                        class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                    >
                </x-field>

                <x-field
                    label="Password baru"
                    for="password"
                    name="password"
                    required
                    hint="Minimal 8 karakter, berisi huruf dan angka."
                >
                    <input
                        id="password"
                        name="password"
                        type="password"
                        autocomplete="new-password"
                        required
                        @error('password') aria-invalid="true" aria-describedby="password-error" @enderror
                        class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                    >
                </x-field>

                <x-field label="Konfirmasi password baru" for="password_confirmation" name="password_confirmation" required>
                    <input
                        id="password_confirmation"
                        name="password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        required
                        class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                    >
                </x-field>

                <div class="border-t border-slate-200 pt-4">
                    <button
                        type="submit"
                        class="inline-flex items-center rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                    >Perbarui password</button>
                </div>
            </form>
        </x-card>

        <x-card title="Akun Anda" description="Informasi akun yang sedang digunakan.">
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="font-medium text-slate-500">Nama</dt>
                    <dd class="text-slate-900">{{ $user->name }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-slate-500">Email</dt>
                    <dd class="break-all text-slate-900">{{ $user->email }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-slate-500">Peran</dt>
                    <dd class="mt-1">
                        <x-badge variant="info">{{ $user->role()->label() }}</x-badge>
                    </dd>
                </div>
                <div>
                    <dt class="font-medium text-slate-500">Login terakhir</dt>
                    <dd class="text-slate-900">
                        {{ $user->last_login_at ? $user->last_login_at->locale('id')->translatedFormat('d M Y H:i') : 'Belum pernah' }}
                    </dd>
                </div>
            </dl>

            <p class="mt-4 text-xs text-slate-500">
                Setelah password diubah, gunakan password baru pada login berikutnya di perangkat ini maupun perangkat lain.
            </p>
        </x-card>
    </div>
@endsection
