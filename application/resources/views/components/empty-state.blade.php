@props(['title', 'description' => null, 'icon' => null])

<div {{ $attributes->merge(['class' => 'empty']) }}>
    @if ($icon)
        <span class="avatar avatar-lg bg-primary-lt text-primary mb-3" aria-hidden="true"><x-icon :name="$icon" /></span>
    @endif
    <p class="empty-title">{{ $title }}</p>

    @if ($description)
        <p class="empty-subtitle text-secondary">{{ $description }}</p>
    @endif

    @isset($action)
        <div class="empty-action">{{ $action }}</div>
    @endisset
</div>
