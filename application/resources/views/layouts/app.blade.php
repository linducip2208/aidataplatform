@php
    $primaryNav = [
        ['route' => 'dashboard', 'pattern' => 'dashboard', 'label' => 'Dashboard'],
        ['route' => 'datasets.index', 'pattern' => 'datasets.*', 'label' => 'Kumpulan data'],
        ['route' => 'imports.index', 'pattern' => 'imports.*', 'label' => 'Impor'],
        ['route' => 'quality.index', 'pattern' => 'quality.*', 'label' => 'Kualitas'],
        ['route' => 'analytics.index', 'pattern' => 'analytics.*', 'label' => 'Analitik'],
        ['route' => 'ml.index', 'pattern' => 'ml.*', 'label' => 'Pembelajaran mesin'],
        ['route' => 'assistant.index', 'pattern' => 'assistant.*', 'label' => 'Asisten'],
        ['route' => 'reports.index', 'pattern' => 'reports.*', 'label' => 'Laporan'],
    ];

    $adminNav = [
        ['route' => 'admin.users.index', 'pattern' => 'admin.users.*', 'label' => 'Pengguna'],
        ['route' => 'audit.index', 'pattern' => 'audit.*', 'label' => 'Log audit'],
    ];

    $user = auth()->user();
@endphp
<!DOCTYPE html>
<html lang="id" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@yield('title', 'Dashboard') &middot; {{ config('app.name', 'AIDataPlatform') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="flex min-h-full flex-col bg-slate-50 text-slate-900 antialiased" x-data="{ navOpen: false }">
        <a
            href="#main-content"
            class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-brand-700 focus:shadow"
        >Lewati ke konten utama</a>

        <header class="sticky top-0 z-40 border-b border-slate-200 bg-white">
            <div class="mx-auto flex w-full max-w-7xl items-center gap-3 px-4 py-3 sm:px-6 lg:px-8">
                <a
                    href="{{ route('dashboard') }}"
                    class="flex items-center gap-2 rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                >
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-md bg-brand-600 text-sm font-bold text-white" aria-hidden="true">A</span>
                    <span class="text-base font-semibold text-slate-900">{{ config('app.name', 'AIDataPlatform') }}</span>
                </a>

                <button
                    type="button"
                    class="ml-auto inline-flex h-10 w-10 items-center justify-center rounded-md border border-slate-200 text-slate-600 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2 md:hidden"
                    x-on:click="navOpen = ! navOpen"
                    x-bind:aria-expanded="navOpen ? 'true' : 'false'"
                    aria-controls="primary-nav"
                    aria-label="Tampilkan atau sembunyikan navigasi utama"
                >
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" d="M3 6h14M3 10h14M3 14h14" />
                    </svg>
                </button>

                <div class="ml-auto md:ml-0">
                    <details class="relative">
                        <summary
                            class="flex cursor-pointer list-none items-center gap-2 rounded-md border border-slate-200 px-2 py-1 text-sm text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2 [&::-webkit-details-marker]:hidden"
                        >
                            <span
                                class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-accent-600 text-xs font-semibold text-white"
                                aria-hidden="true"
                            >{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($user->name, 0, 1)) }}</span>
                            <span class="hidden max-w-40 truncate sm:inline">{{ $user->name }}</span>
                            <span class="sr-only">Buka menu pengguna</span>
                        </summary>

                        <div class="absolute right-0 z-50 mt-2 w-72 rounded-lg border border-slate-200 bg-white p-4 shadow-lg">
                            <p class="truncate text-sm font-semibold text-slate-900">{{ $user->name }}</p>
                            <p class="truncate text-sm text-slate-500">{{ $user->email }}</p>
                            <p class="mt-2">
                                <x-badge variant="info">{{ $user->role()->localizedLabel() }}</x-badge>
                            </p>

                            <div class="mt-4 space-y-2 border-t border-slate-200 pt-4">
                                <a
                                    href="{{ route('password.edit') }}"
                                    class="block rounded-md px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                                >Ubah password</a>

                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button
                                        type="submit"
                                        class="block w-full rounded-md px-3 py-2 text-left text-sm font-medium text-rose-700 hover:bg-rose-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2"
                                    >Keluar</button>
                                </form>
                            </div>
                        </div>
                    </details>
                </div>
            </div>

            <nav id="primary-nav" class="border-t border-slate-200 bg-white" aria-label="Navigasi utama">
                <div class="hidden md:block">
                    <div class="mx-auto w-full max-w-7xl px-4 py-2 sm:px-6 lg:px-8">
                        @include('partials.nav-links', ['primaryNav' => $primaryNav, 'adminNav' => $adminNav])
                    </div>
                </div>

                <div class="md:hidden" x-show="navOpen" x-cloak>
                    <div class="mx-auto w-full max-w-7xl px-4 py-3 sm:px-6">
                        @include('partials.nav-links', ['primaryNav' => $primaryNav, 'adminNav' => $adminNav])
                    </div>
                </div>
            </nav>
        </header>

        <main id="main-content" class="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6 lg:px-8">
            <x-flash />

            @yield('content')
        </main>

        <footer class="border-t border-slate-200 bg-white">
            <div class="mx-auto w-full max-w-7xl px-4 py-4 text-xs text-slate-500 sm:px-6 lg:px-8">
                {{ config('app.name', 'AIDataPlatform') }} &middot; orkestrasi UI di Laravel, komputasi berat dilayani mesin AI.
            </div>
        </footer>

        @stack('scripts')
    </body>
</html>
