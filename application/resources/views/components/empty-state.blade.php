@props(['title', 'description' => null])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center gap-2 px-4 py-10 text-center']) }}>
    <p class="text-sm font-semibold text-slate-900">{{ $title }}</p>

    @if ($description)
        <p class="max-w-prose text-sm text-slate-500">{{ $description }}</p>
    @endif

    @isset($action)
        <div class="mt-2">{{ $action }}</div>
    @endisset
</div>
