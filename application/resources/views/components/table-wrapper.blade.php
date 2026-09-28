@props(['label' => 'Tabel data', 'caption' => null])

<div
    {{ $attributes->merge(['class' => 'overflow-x-auto rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2']) }}
    role="region"
    aria-label="{{ $label }}"
    tabindex="0"
>
    @if ($caption)
        <p class="mb-2 text-xs text-slate-500">{{ $caption }}</p>
    @endif

    {{ $slot }}
</div>
