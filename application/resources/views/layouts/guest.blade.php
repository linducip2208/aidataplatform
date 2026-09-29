<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', 'Masuk') &middot; {{ config('app.name', 'AIDataPlatform') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="d-flex flex-column">
        <div class="page page-center">
            <main class="container container-tight py-4" id="main-content">
                <div class="text-center mb-4">
                    <span class="avatar avatar-lg bg-primary text-white mb-2" aria-hidden="true">A</span>
                    <div class="h2 mb-0">{{ config('app.name', 'AIDataPlatform') }}</div>
                </div>

                <x-flash />

                @yield('content')
            </main>
        </div>

        @stack('scripts')
    </body>
</html>
