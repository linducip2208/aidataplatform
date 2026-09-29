@props(['title' => null, 'description' => null])

<div {{ $attributes->merge(['class' => 'card']) }}>
    @if ($title || isset($actions))
        <div class="card-header">
            <div>
                @if ($title)
                    <h2 class="card-title">{{ $title }}</h2>
                @endif

                @if ($description)
                    <div class="card-subtitle">{{ $description }}</div>
                @endif
            </div>

            @isset($actions)
                <div class="card-actions">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    <div class="card-body">{{ $slot }}</div>
</div>
