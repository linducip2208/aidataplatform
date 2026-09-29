@props(['label', 'value', 'hint' => null])

<div {{ $attributes->merge(['class' => 'card']) }}>
    <div class="card-body">
        <div class="subheader">{{ $label }}</div>
        <div class="h1 mb-0">{{ $value }}</div>

        @if ($hint)
            <div class="text-secondary small mt-1">{{ $hint }}</div>
        @endif
    </div>
</div>
