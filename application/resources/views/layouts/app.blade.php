@php
    $primaryNav = [
        ['heading' => 'overview'],
        ['route' => 'dashboard', 'pattern' => 'dashboard', 'key' => 'dashboard', 'icon' => 'dashboard'],
        ['heading' => 'data'],
        ['route' => 'datasets.index', 'pattern' => 'datasets.*', 'key' => 'datasets', 'icon' => 'database'],
        ['route' => 'imports.index', 'pattern' => 'imports.*', 'key' => 'imports', 'icon' => 'file-import'],
        ['route' => 'quality.index', 'pattern' => 'quality.*', 'key' => 'quality', 'icon' => 'clipboard-check'],
        ['route' => 'alerts.index', 'pattern' => 'alerts.*', 'key' => 'alerts', 'icon' => 'bell'],
        ['heading' => 'ai_analytics'],
        ['route' => 'analytics.index', 'pattern' => 'analytics.*', 'key' => 'analytics', 'icon' => 'chart-line'],
        ['route' => 'ml.index', 'pattern' => 'ml.*', 'key' => 'ml', 'icon' => 'brain'],
        ['route' => 'assistant.index', 'pattern' => 'assistant.*', 'key' => 'assistant', 'icon' => 'message-chatbot'],
        ['route' => 'knowledge.index', 'pattern' => 'knowledge.*', 'key' => 'knowledge', 'icon' => 'books'],
        ['route' => 'glossary.index', 'pattern' => 'glossary.*', 'key' => 'glossary', 'icon' => 'vocabulary'],
        ['route' => 'reports.index', 'pattern' => 'reports.*', 'key' => 'reports', 'icon' => 'report'],
        ['route' => 'decisions.index', 'pattern' => 'decisions.*', 'key' => 'decisions', 'icon' => 'scale'],
        ['route' => 'ai.usage', 'pattern' => 'ai.usage', 'key' => 'ai_usage', 'icon' => 'coins'],
    ];

    $adminNav = [
        ['heading' => 'admin'],
        ['route' => 'admin.users.index', 'pattern' => 'admin.users.*', 'key' => 'users', 'icon' => 'users'],
        ['route' => 'audit.index', 'pattern' => 'audit.*', 'key' => 'audit', 'icon' => 'history'],
        ['route' => 'admin.organization.edit', 'pattern' => 'admin.organization.*', 'key' => 'organization', 'icon' => 'building'],
        ['route' => 'admin.providers.index', 'pattern' => 'admin.providers.*', 'key' => 'providers', 'icon' => 'plug'],
        ['route' => 'admin.webhooks.index', 'pattern' => 'admin.webhooks.*', 'key' => 'webhooks', 'icon' => 'webhook'],
    ];

    $user = auth()->user();
    $organization = \App\Models\Organization::current();
    $brandName = $organization?->displayName() ?? config('app.name', 'AIDataPlatform');
    $brandLogo = $organization?->logo_path;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@yield('title', __('nav.items.dashboard')) &middot; {{ config('app.name', 'AIDataPlatform') }}</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link
            href="https://fonts.googleapis.com/css2?family=Instrument+Sans:ital,wght@0,400..700;1,400..700&display=swap"
            rel="stylesheet"
        >

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <a href="#main-content" class="skip-link">{{ __('nav.skip_to_content') }}</a>

        <div class="page">
            <aside class="navbar navbar-vertical navbar-expand-lg" data-bs-theme="dark">
                <div class="container-fluid">
                    <button
                        class="navbar-toggler"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#sidebar-menu"
                        aria-controls="sidebar-menu"
                        aria-expanded="false"
                        aria-label="{{ __('nav.toggle_navigation') }}"
                    >
                        <span class="navbar-toggler-icon"></span>
                    </button>

                    <a href="{{ route('dashboard') }}" class="navbar-brand navbar-brand-autodark">
                        @if (is_string($brandLogo) && $brandLogo !== '')
                            <span class="avatar avatar-sm bg-white border me-2" aria-hidden="true">
                                <img src="{{ Storage::url($brandLogo) }}" alt="" width="32" height="32">
                            </span>
                        @else
                            <span class="avatar avatar-sm brand-mark me-2" aria-hidden="true"><x-icon name="database" /></span>
                        @endif
                        <span class="fw-bold">AI DATA PLATFORM</span>
                    </a>

                    <div class="collapse navbar-collapse" id="sidebar-menu">
                        <nav aria-label="{{ __('nav.main_navigation') }}" class="w-100">
                            @include('partials.nav-links', ['primaryNav' => $primaryNav, 'adminNav' => $adminNav])
                        </nav>

                        <div class="d-lg-none mt-3 pt-3 border-top">
                            <div class="text-truncate fw-bold">{{ $user->name }}</div>
                            <div class="text-truncate small text-secondary mb-2">{{ $user->email }}</div>
                            <a href="{{ route('password.edit') }}" class="btn btn-ghost-secondary w-100 mb-2">{{ __('nav.change_password') }}</a>
                            <form method="POST" action="{{ route('locale.update') }}" class="mb-2">
                                @csrf
                                <label for="locale-mobile" class="visually-hidden">{{ __('nav.language') }}</label>
                                <select id="locale-mobile" name="locale" class="form-select" onchange="this.form.submit()">
                                    <option value="id" @selected(app()->getLocale() === 'id')>{{ __('nav.language_indonesian') }}</option>
                                    <option value="en" @selected(app()->getLocale() === 'en')>{{ __('nav.language_english') }}</option>
                                </select>
                            </form>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="btn btn-outline-danger w-100">{{ __('nav.logout') }}</button>
                            </form>
                        </div>
                    </div>
                </div>
            </aside>

            <div class="page-wrapper">
                <header class="navbar navbar-expand-md d-none d-lg-flex d-print-none">
                    <div class="container-xl">
                        <form method="GET" action="{{ route('datasets.index') }}" class="d-none d-md-flex me-3" role="search">
                            <div class="input-icon">
                                <span class="input-icon-addon" aria-hidden="true"><x-icon name="search" /></span>
                                <label for="global-search" class="visually-hidden">{{ __('nav.search_label') }}</label>
                                <input
                                    id="global-search"
                                    name="q"
                                    type="search"
                                    class="form-control"
                                    placeholder="{{ __('nav.search_placeholder') }}"
                                    autocomplete="off"
                                >
                            </div>
                        </form>
                        <div class="navbar-nav flex-row order-md-last ms-auto">
                            <div class="nav-item d-none d-xl-flex align-items-center me-2 text-secondary small">
                                <span class="avatar avatar-xs bg-azure-lt me-2" aria-hidden="true"><x-icon name="building" /></span>
                                <span class="text-truncate" style="max-width: 12rem;">{{ $brandName }}</span>
                            </div>
                            <div class="nav-item">
                                <a
                                    href="{{ route('alerts.index') }}"
                                    class="nav-link px-2"
                                    aria-label="{{ __('nav.notifications') }}"
                                    title="{{ __('nav.notifications') }}"
                                ><x-icon name="bell" /></a>
                            </div>
                            <div class="nav-item">
                                <a
                                    href="{{ route('knowledge.index') }}"
                                    class="nav-link px-2"
                                    aria-label="{{ __('nav.help') }}"
                                    title="{{ __('nav.help') }}"
                                ><x-icon name="help" /></a>
                            </div>
                            <div class="nav-item dropdown">
                                <a
                                    href="#"
                                    class="nav-link d-flex lh-1 text-reset p-0"
                                    data-bs-toggle="dropdown"
                                    aria-label="{{ __('nav.open_user_menu') }}"
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
                                    <a href="{{ route('password.edit') }}" class="dropdown-item">{{ __('nav.change_password') }}</a>
                                    <div class="dropdown-item-text">
                                        <form method="POST" action="{{ route('locale.update') }}" class="d-flex align-items-center gap-2">
                                            @csrf
                                            <label for="locale-desktop" class="small text-secondary mb-0">{{ __('nav.language') }}</label>
                                            <select id="locale-desktop" name="locale" class="form-select form-select-sm" onchange="this.form.submit()">
                                                <option value="id" @selected(app()->getLocale() === 'id')>{{ __('nav.language_indonesian') }}</option>
                                                <option value="en" @selected(app()->getLocale() === 'en')>{{ __('nav.language_english') }}</option>
                                            </select>
                                        </form>
                                    </div>
                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <button type="submit" class="dropdown-item text-danger">{{ __('nav.logout') }}</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </header>

                <main id="main-content">
                    <div class="page-body">
                        <div class="container-xl">
                            <x-flash />

                            @yield('content')
                        </div>
                    </div>
                </main>

                <footer class="footer footer-transparent d-print-none">
                    <div class="container-xl">
                        <div class="text-secondary small">
                            {{ $brandName }} &middot; {{ $organization?->tagline ?: __('nav.footer') }}
                        </div>
                    </div>
                </footer>
            </div>
        </div>

        @stack('scripts')
    </body>
</html>
