@props(['label', 'value', 'hint' => null])

<div {{ $attributes->merge(['class' => 'app-card p-4']) }}>
    <p class="text-sm font-medium text-slate-500">{{ $label }}</p>
    <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $value }}</p>

    @if ($hint)
        <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
    @endif
</div>
