@props(['title', 'description' => null])

<div {{ $attributes->merge(['class' => 'empty']) }}>
    <p class="empty-title">{{ $title }}</p>

    @if ($description)
        <p class="empty-subtitle text-secondary">{{ $description }}</p>
    @endif

    @isset($action)
        <div class="empty-action">{{ $action }}</div>
    @endisset
</div>
