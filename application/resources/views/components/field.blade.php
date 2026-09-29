@props(['label', 'for' => null, 'name' => null, 'hint' => null, 'required' => false])

@php
    $inputId = $for ?: $name;
    $error = $name ? $errors->first($name) : null;
@endphp

<div {{ $attributes->merge(['class' => 'mb-3']) }}>
    @if ($inputId)
        <label for="{{ $inputId }}" class="form-label">
            {{ $label }}
            @if ($required)
                <span class="text-danger" aria-hidden="true">*</span>
                <span class="visually-hidden">(wajib diisi)</span>
            @endif
        </label>
    @else
        <p class="form-label">{{ $label }}</p>
    @endif

    {{ $slot }}

    @if ($hint && ! $error)
        <small @if ($inputId) id="{{ $inputId }}-hint" @endif class="form-hint">{{ $hint }}</small>
    @endif

    @if ($error)
        <div @if ($inputId) id="{{ $inputId }}-error" @endif class="invalid-feedback d-block">{{ $error }}</div>
    @endif
</div>
