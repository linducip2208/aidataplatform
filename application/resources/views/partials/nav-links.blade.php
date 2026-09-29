@php
    $linkClass = 'block rounded-md px-3 py-2 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2';
    $idleClass = 'text-slate-600 hover:bg-slate-100 hover:text-slate-900';
    $activeClass = 'bg-brand-50 text-brand-800';
@endphp<ul class="flex flex-col gap-1 md:flex-row md:flex-wrap md:items-center md:gap-1">
    @foreach ($primaryNav as $item)
        @php $isActive = request()->routeIs($item['pattern']); @endphp
        <li>
            <a
                href="{{ route($item['route']) }}"
                @if ($isActive) aria-current="page" @endif
                class="{{ $linkClass }} {{ $isActive ? $activeClass : $idleClass }}"
            >{{ $item['label'] }}</a>
        </li>
    @endforeach

    @if (auth()->user()->isAdmin())
        <li class="mt-2 border-t border-slate-200 pt-2 md:mt-0 md:ml-2 md:border-l md:border-t-0 md:pl-2 md:pt-0" aria-hidden="true"></li>

        @foreach ($adminNav as $item)
            @php $isActive = request()->routeIs($item['pattern']); @endphp
            <li>
                <a
                    href="{{ route($item['route']) }}"
                    @if ($isActive) aria-current="page" @endif
                    class="{{ $linkClass }} {{ $isActive ? $activeClass : $idleClass }}"
                >{{ $item['label'] }}</a>
            </li>
        @endforeach
    @endif
</ul>
