@props(['variant' => null])

{{--
    The variant class is only emitted when `variant` is given. A caller that
    passes an enum-supplied class (`badge-success`, `badge-danger`, ...) through
    :class must not also receive `badge-info`: app.css resolves the conflict
    with compound `.badge.badge-<variant>` selectors rather than by rule order,
    but an element carrying two colours is still a bug worth not writing.
--}}
<span {{ $attributes->merge(['class' => trim('badge '.($variant ? 'badge-'.$variant : ''))]) }}>{{ $slot }}</span>
