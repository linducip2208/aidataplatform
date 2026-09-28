@props(['title' => null, 'description' => null])

<section {{ $attributes->merge(['class' => 'app-card']) }}>
    @if ($title || isset($actions))
        <header class="app-card-header">
            <div>
                @if ($title)
                    <h2 class="text-base font-semibold text-slate-900">{{ $title }}</h2>
                @endif

                @if ($description)
                    <p class="mt-1 text-sm text-slate-500">{{ $description }}</p>
                @endif
            </div>

            @isset($actions)
                <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    <div class="app-card-body">{{ $slot }}</div>
</section>
