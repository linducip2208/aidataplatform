@props(['points' => [], 'label' => 'Grafik tren'])

@php
    $values = collect($points)->map(fn ($point): float => (float) ($point['value'] ?? 0))->values();
    $labels = collect($points)->map(fn ($point): string => (string) ($point['label'] ?? ''))->values();
    $max = max(1.0, (float) $values->max());
    $count = $values->count();

    $width = 560;
    $height = 180;
    $padLeft = 8;
    $padRight = 8;
    $padTop = 12;
    $padBottom = 24;
    $innerWidth = $width - $padLeft - $padRight;
    $innerHeight = $height - $padTop - $padBottom;

    $coords = [];
    foreach ($values as $index => $value) {
        $x = $padLeft + ($count > 1 ? $innerWidth * $index / ($count - 1) : $innerWidth / 2);
        $y = $padTop + $innerHeight * (1 - min(1.0, max(0.0, $value / $max)));
        $coords[] = [$x, $y];
    }

    $line = collect($coords)->map(fn ($point): string => number_format($point[0], 1, '.', '').','.number_format($point[1], 1, '.', ''))->implode(' ');
    $area = $padLeft.','.($padTop + $innerHeight).' '.$line.' '.($padLeft + $innerWidth).','.($padTop + $innerHeight);
@endphp

@if ($count === 0)
    <x-empty-state title="Belum ada data grafik" description="Tidak ada titik tren untuk digambar." />
@else
    <figure class="mb-3">
        <svg
            viewBox="0 0 {{ $width }} {{ $height }}"
            class="w-100 h-auto"
            role="img"
            aria-label="{{ $label }}: {{ $count }} titik, tertinggi {{ number_format($max, 0, ',', '.') }}"
            preserveAspectRatio="xMidYMid meet"
        >
            @foreach ([0.25, 0.5, 0.75] as $fraction)
                <line
                    x1="{{ $padLeft }}"
                    x2="{{ $padLeft + $innerWidth }}"
                    y1="{{ $padTop + $innerHeight * $fraction }}"
                    y2="{{ $padTop + $innerHeight * $fraction }}"
                    stroke="currentColor"
                    stroke-opacity="0.12"
                    stroke-width="1"
                />
            @endforeach
            <polygon points="{{ $area }}" fill="currentColor" opacity="0.12" class="text-primary" />
            <polyline
                points="{{ $line }}"
                fill="none"
                stroke="currentColor"
                stroke-width="2.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                class="text-primary"
            />
            @foreach ($coords as $index => $point)
                <circle cx="{{ $point[0] }}" cy="{{ $point[1] }}" r="3.5" fill="currentColor" class="text-primary">
                    <title>{{ $labels[$index] }}</title>
                </circle>
            @endforeach
        </svg>
        <figcaption class="small text-secondary d-flex justify-content-between">
            <span>{{ $labels->first() }}</span>
            <span>{{ $labels->last() }}</span>
        </figcaption>
    </figure>
@endif
