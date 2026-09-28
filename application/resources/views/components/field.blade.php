@props(['label', 'for' => null, 'name' => null, 'hint' => null, 'required' => false])

@php
    $inputId = $for ?: $name;
    $error = $name ? $errors->first($name) : null;
@endphp

<div {{ $attributes->merge(['class' => 'space-y-1']) }}>
    @if ($inputId)
        <label for="{{ $inputId }}" class="block text-sm font-medium text-slate-700">
            {{ $label }}
            @if ($required)
                <span class="text-rose-700" aria-hidden="true">*</span>
                <span class="sr-only">(wajib diisi)</span>
            @endif
        </label>
    @else
        <p class="block text-sm font-medium text-slate-700">{{ $label }}</p>
    @endif

    {{ $slot }}

    @if ($hint && ! $error)
        <p @if ($inputId) id="{{ $inputId }}-hint" @endif class="text-xs text-slate-500">{{ $hint }}</p>
    @endif

    @if ($error)
        <p @if ($inputId) id="{{ $inputId }}-error" @endif class="text-xs font-medium text-rose-700">{{ $error }}</p>
    @endif
</div>
