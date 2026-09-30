@php
    $primaryNav = [
        ['heading' => 'Utama'],
        ['route' => 'dashboard', 'pattern' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'home'],
        ['heading' => 'Data'],
        ['route' => 'datasets.index', 'pattern' => 'datasets.*', 'label' => 'Kumpulan data', 'icon' => 'database'],
        ['route' => 'imports.index', 'pattern' => 'imports.*', 'label' => 'Impor', 'icon' => 'upload'],
        ['route' => 'quality.index', 'pattern' => 'quality.*', 'label' => 'Kualitas', 'icon' => 'checkup-list'],
        ['route' => 'alerts.index', 'pattern' => 'alerts.*', 'label' => 'Peringatan', 'icon' => 'bell'],
        ['heading' => 'Analitik & AI'],
        ['route' => 'analytics.index', 'pattern' => 'analytics.*', 'label' => 'Analitik', 'icon' => 'chart-bar'],
        ['route' => 'ml.index', 'pattern' => 'ml.*', 'label' => 'Pembelajaran mesin', 'icon' => 'brain'],
        ['route' => 'assistant.index', 'pattern' => 'assistant.*', 'label' => 'Asisten', 'icon' => 'message-chatbot'],
        ['route' => 'knowledge.index', 'pattern' => 'knowledge.*', 'label' => 'Basis pengetahuan', 'icon' => 'books'],
        ['route' => 'glossary.index', 'pattern' => 'glossary.*', 'label' => 'Glosarium', 'icon' => 'dictionary'],
        ['route' => 'reports.index', 'pattern' => 'reports.*', 'label' => 'Laporan', 'icon' => 'report'],
        ['route' => 'decisions.index', 'pattern' => 'decisions.*', 'label' => 'Keputusan', 'icon' => 'scale'],
        ['route' => 'ai.usage', 'pattern' => 'ai.usage', 'label' => 'Biaya AI', 'icon' => 'coins'],
    ];

    $adminNav = [
        ['heading' => 'Administrasi'],
        ['route' => 'admin.users.index', 'pattern' => 'admin.users.*', 'label' => 'Pengguna', 'icon' => 'users'],
        ['route' => 'audit.index', 'pattern' => 'audit.*', 'label' => 'Log audit', 'icon' => 'shield-check'],
    ];

    $user = auth()->user();
@endphp
<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@yield('title', 'Dashboard') &middot; {{ config('app.name', 'AIDataPlatform') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <a href="#main-content" class="skip-link">Lewati ke konten utama</a>

        <div class="page">
            <aside class="navbar navbar-vertical navbar-expand-lg" data-bs-theme="light">
                <div class="container-fluid">
                    <button
                        class="navbar-toggler"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#sidebar-menu"
                        aria-controls="sidebar-menu"
                        aria-expanded="false"
                        aria-label="Tampilkan atau sembunyikan navigasi utama"
                    >
                        <span class="navbar-toggler-icon"></span>
                    </button>

                    <a href="{{ route('dashboard') }}" class="navbar-brand navbar-brand-autodark">
                        <span class="avatar avatar-sm bg-primary text-white me-2" aria-hidden="true">A</span>
                        <span>{{ config('app.name', 'AIDataPlatform') }}</span>
                    </a>

                    <div class="collapse navbar-collapse" id="sidebar-menu">
                        <nav aria-label="Navigasi utama" class="w-100">
                            @include('partials.nav-links', ['primaryNav' => $primaryNav, 'adminNav' => $adminNav])
                        </nav>

                        <div class="d-lg-none mt-3 pt-3 border-top">
                            <div class="text-truncate fw-bold">{{ $user->name }}</div>
                            <div class="text-truncate small text-secondary mb-2">{{ $user->email }}</div>
                            <a href="{{ route('password.edit') }}" class="btn btn-ghost-secondary w-100 mb-2">Ubah password</a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="btn btn-outline-danger w-100">Keluar</button>
                            </form>
                        </div>
                    </div>
                </div>
            </aside>

            <header class="navbar navbar-expand-md d-none d-lg-flex d-print-none">
                <div class="container-xl">
                    <div class="navbar-nav flex-row order-md-last ms-auto">
                        <div class="nav-item dropdown">
                            <a
                                href="#"
                                class="nav-link d-flex lh-1 text-reset p-0"
                                data-bs-toggle="dropdown"
                                aria-label="Buka menu pengguna"
                            >
                                <span
                                    class="avatar avatar-sm bg-azure-lt"
                                    aria-hidden="true"
                                >{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($user->name, 0, 1)) }}</span>
                                <div class="d-none d-xl-block ps-2">
                                    <div>{{ $user->name }}</div>
                                    <div class="mt-1 small text-secondary">{{ $user->role()->localizedLabel() }}</div>
                                </div>
                            </a>
                            <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                                <div class="dropdown-header">
                                    <div class="text-truncate fw-bold">{{ $user->name }}</div>
                                    <div class="text-truncate small text-secondary">{{ $user->email }}</div>
                                    <div class="mt-2">
                                        <x-badge variant="info">{{ $user->role()->localizedLabel() }}</x-badge>
                                    </div>
                                </div>
                                <div class="dropdown-divider"></div>
                                <a href="{{ route('password.edit') }}" class="dropdown-item">Ubah password</a>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="dropdown-item text-danger">Keluar</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <main id="main-content" class="page-wrapper">
                <div class="page-body">
                    <div class="container-xl">
                        <x-flash />

                        @yield('content')
                    </div>
                </div>

                <footer class="footer footer-transparent d-print-none">
                    <div class="container-xl">
                        <div class="text-secondary small">
                            {{ config('app.name', 'AIDataPlatform') }} &middot; orkestrasi UI di Laravel, komputasi berat dilayani mesin AI.
                        </div>
                    </div>
                </footer>
            </main>
        </div>

        @stack('scripts')
    </body>
</html>
