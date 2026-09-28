@props(['variant' => null])

{{--
    The variant class is only emitted when `variant` is given. A caller that
    passes an enum-supplied class (`badge-success`, `badge-danger`, ...) through
    :class must not also receive `badge-info`, because app.css declares
    `.badge-info` after `.badge-success` at equal specificity and the later
    rule would win, painting a committed dataset in the info colour.
--}}
<span {{ $attributes->merge(['class' => trim('badge '.($variant ? 'badge-'.$variant : ''))]) }}>{{ $slot }}</span>
