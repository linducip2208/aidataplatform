@props(['label' => 'Tabel data', 'caption' => null])

<div
    {{ $attributes->merge(['class' => 'table-responsive']) }}
    role="region"
    aria-label="{{ $label }}"
    tabindex="0"
>
    @if ($caption)
        <p class="text-secondary small mb-2">{{ $caption }}</p>
    @endif

    {{ $slot }}
</div>
