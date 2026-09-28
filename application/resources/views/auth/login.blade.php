@extends('layouts.guest')

@section('title', 'Masuk')

@section('content')
    <div class="app-card">
        <div class="app-card-header">
            <div>
                <h1 class="text-lg font-semibold text-slate-900">Masuk ke platform</h1>
                <p class="mt-1 text-sm text-slate-500">Gunakan email dan password akun yang terdaftar.</p>
            </div>
        </div>

        <div class="app-card-body">
            <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
                @csrf

                <x-field label="Email" for="email" name="email" required>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        value="{{ old('email') }}"
                        autocomplete="username"
                        required
                        autofocus
                        placeholder="nama@perusahaan.com"
                        @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                        class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                    >
                </x-field>

                <x-field label="Password" for="password" name="password" required>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        autocomplete="current-password"
                        required
                        placeholder="Minimal 8 karakter"
                        @error('password') aria-invalid="true" @enderror
                        class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                    >
                </x-field>

                <div class="flex items-center gap-2">
                    <input
                        id="remember"
                        name="remember"
                        type="checkbox"
                        value="1"
                        @checked(old('remember'))
                        class="h-4 w-4 rounded border-slate-300 text-brand-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                    >
                    <label for="remember" class="text-sm text-slate-700">Ingat saya di perangkat ini</label>
                </div>

                <button
                    type="submit"
                    class="inline-flex w-full items-center justify-center rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                >Masuk</button>
            </form>
        </div>
    </div>

    @if (app()->environment(['local', 'development', 'testing']))
        <div class="mt-6 rounded-lg border border-amber-600/30 bg-amber-50 p-4">
            <p class="text-sm font-semibold text-amber-900">Akun demo (lingkungan pengembangan)</p>
            <p class="mt-1 text-xs text-amber-800">Hanya ditampilkan di luar produksi. Jangan aktifkan di server produksi.</p>

            <ul class="mt-3 space-y-2 text-sm text-amber-900">
                <li class="flex flex-wrap items-center justify-between gap-2">
                    <span>Administrator</span>
                    <code class="rounded bg-white px-2 py-1 text-xs">admin@example.com / Admin123!</code>
                </li>
                <li class="flex flex-wrap items-center justify-between gap-2">
                    <span>Analyst</span>
                    <code class="rounded bg-white px-2 py-1 text-xs">analyst@example.com / Analyst123!</code>
                </li>
                <li class="flex flex-wrap items-center justify-between gap-2">
                    <span>Viewer</span>
                    <code class="rounded bg-white px-2 py-1 text-xs">viewer@example.com / Viewer123!</code>
                </li>
            </ul>
        </div>
    @endif
@endsection
