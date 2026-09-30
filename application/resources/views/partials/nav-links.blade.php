<ul class="navbar-nav">
    @foreach ($primaryNav as $item)
        @if (isset($item['heading']))
            <li class="nav-header"><span class="nav-link">{{ __('nav.sections.'.$item['heading']) }}</span></li>
        @else
            <li class="nav-item @if (request()->routeIs($item['pattern'])) active @endif">
                <a
                    href="{{ route($item['route']) }}"
                    class="nav-link"
                    @if (request()->routeIs($item['pattern'])) aria-current="page" @endif
                ><span class="nav-link-title">{{ __('nav.items.'.$item['key']) }}</span></a>
            </li>
        @endif
    @endforeach

    @if (auth()->user()->isAdmin())
        @foreach ($adminNav as $item)
            @if (isset($item['heading']))
                <li class="nav-header"><span class="nav-link">{{ __('nav.sections.'.$item['heading']) }}</span></li>
            @else
                <li class="nav-item @if (request()->routeIs($item['pattern'])) active @endif">
                    <a
                        href="{{ route($item['route']) }}"
                        class="nav-link"
                        @if (request()->routeIs($item['pattern'])) aria-current="page" @endif
                    ><span class="nav-link-title">{{ __('nav.items.'.$item['key']) }}</span></a>
                </li>
            @endif
        @endforeach
    @endif
</ul>
