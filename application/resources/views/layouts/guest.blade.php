<!DOCTYPE html>
<html lang="id" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', 'Masuk') &middot; {{ config('app.name', 'AIDataPlatform') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="flex min-h-full flex-col items-center justify-center bg-slate-100 px-4 py-10 text-slate-900 antialiased">
        <main class="w-full max-w-md">
            <div class="mb-6 flex items-center justify-center gap-2">
                <span class="inline-flex h-10 w-10 items-center justify-center rounded-md bg-brand-600 text-base font-bold text-white" aria-hidden="true">A</span>
                <span class="text-lg font-semibold text-slate-900">{{ config('app.name', 'AIDataPlatform') }}</span>
            </div>

            <x-flash />

            @yield('content')
        </main>

        @stack('scripts')
    </body>
</html>
