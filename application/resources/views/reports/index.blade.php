@extends('layouts.app')

@section('title', 'Laporan')

@section('content')
    @php
        $periodLabels = config('ai_engine.period_labels', []);
        $financeLabels = config('ai_engine.finance_labels', []);

        $kpi = (array) ($report['kpi'] ?? []);
        $finance = (array) ($report['finance'] ?? []);
        $narrative = (string) ($report['narrative'] ?? '');
        $sections = (array) ($report['sections'] ?? []);
        $hasContent = $report !== [];
    @endphp

    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-slate-900">Laporan eksekutif</h1>
        <p class="mt-1 text-sm text-slate-500">
            Ringkasan periode yang disusun mesin AI dari KPI, tren penjualan, inventori, dan keuangan.
        </p>
    </div>

    <nav aria-label="Pilih periode laporan" class="mb-6">
        <ul class="flex flex-wrap gap-2">
            @foreach ($periods as $value)
                @php $isActive = $period === $value; @endphp
                <li>
                    <a
                        href="{{ route('reports.index', ['period' => $value]) }}"
                        @if ($isActive) aria-current="page" @endif
                        class="inline-flex rounded-md border px-4 py-2 text-sm font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2 {{ $isActive ? 'border-brand-600 bg-brand-50 text-brand-800' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}"
                    >{{ $periodLabels[$value] ?? \Illuminate\Support\Str::headline($value) }}</a>
                </li>
            @endforeach
        </ul>
    </nav>

    @unless ($engineAvailable)
        <x-card class="mb-6">
            <div role="status" class="flex flex-wrap items-center gap-2">
                <x-badge variant="warning">Mesin AI tidak tersedia</x-badge>
                <p class="text-sm text-slate-600">
                    Laporan belum dapat disusun. Periksa layanan <code class="rounded bg-slate-100 px-1">fastapi</code>
                    lalu muat ulang halaman ini.
                </p>
            </div>
        </x-card>
    @endunless

    @unless ($hasContent)
        <x-card title="Laporan periode {{ $periodLabels[$period] ?? $period }}">
            <x-empty-state
                title="Laporan belum tersedia"
                description="Mesin AI tidak mengembalikan ringkasan untuk periode ini. Pastikan ada dataset penjualan yang sudah dikomit."
            />
        </x-card>
    @else
        <x-card
            class="mb-6"
            title="Ringkasan {{ $periodLabels[$period] ?? $period }}"
            description="Disusun dari data yang sudah dikomit, lalu dinarasi oleh mesin AI."
        >
            @if ($narrative === '')
                <x-empty-state
                    title="Narasi belum tersedia"
                    description="Mesin AI tidak menghasilkan narasi untuk periode ini. Nilai KPI di bawah tetap dapat dibaca."
                />
            @else
                <p class="whitespace-pre-line text-sm leading-[1.5] text-slate-700">{{ $narrative }}</p>
            @endif
        </x-card>

        @if ($sections !== [])
            <x-card class="mb-6" title="Rincian per bagian" description="Highlight, risiko, dan rekomendasi dari mesin AI.">
                <div class="space-y-4">
                    @foreach ($sections as $sectionKey => $section)
                        <div>
                            <h3 class="text-sm font-semibold text-slate-900">{{ \Illuminate\Support\Str::headline((string) $sectionKey) }}</h3>
                            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-700">
                                @if (is_array($section))
                                    @foreach ($section as $item)
                                        <li>
                                            @if (is_array($item))
                                                {{ is_scalar($item['text'] ?? null) ? $item['text'] : json_encode($item, JSON_UNESCAPED_UNICODE) }}
                                            @else
                                                {{ is_scalar($item) ? $item : json_encode($item, JSON_UNESCAPED_UNICODE) }}
                                            @endif
                                        </li>
                                    @endforeach
                                @else
                                    <li>{{ is_scalar($section) ? $section : json_encode($section, JSON_UNESCAPED_UNICODE) }}</li>
                                @endif
                            </ul>
                        </div>
                    @endforeach
                </div>
            </x-card>
        @endif

        <div class="grid gap-6 lg:grid-cols-2">
            <x-card title="KPI periode" description="Angka penjualan yang menjadi dasar laporan.">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-stat label="Pendapatan" :value="\Illuminate\Support\Number::currency((float) ($kpi['revenue'] ?? 0), in: 'idr', locale: 'id', precision: 0)" />
                    <x-stat label="Jumlah pesanan" :value="number_format((int) ($kpi['orders'] ?? 0), 0, ',', '.')" />
                    <x-stat label="Unit terjual" :value="number_format((float) ($kpi['units'] ?? 0), 0, ',', '.')" />
                    <x-stat label="Nilai pesanan rata-rata" :value="\Illuminate\Support\Number::currency((float) ($kpi['aov'] ?? 0), in: 'idr', locale: 'id', precision: 0)" />
                    <x-stat label="Pertumbuhan" :value="\Illuminate\Support\Number::percentage((float) ($kpi['growth_pct'] ?? 0), precision: 1, locale: 'id')" />
                    <x-stat label="Margin" :value="\Illuminate\Support\Number::percentage((float) ($kpi['margin_pct'] ?? 0), precision: 1, locale: 'id')" />
                </div>
            </x-card>

            <x-card title="Keuangan periode" description="Pendapatan, beban, dan laba periode berjalan.">
                @if ($finance === [])
                    <x-empty-state title="Data keuangan kosong" description="Mesin AI tidak mengembalikan ringkasan keuangan untuk periode ini." />
                @else
                    <dl class="space-y-3 text-sm">
                        @foreach ($finance as $key => $value)
                            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-2 last:border-b-0 last:pb-0">
                                <dt class="font-medium text-slate-500">{{ $financeLabels[$key] ?? \Illuminate\Support\Str::headline((string) $key) }}</dt>
                                <dd class="tabular-nums text-slate-900">
                                    @if ($key === 'margin_pct')
                                        {{ \Illuminate\Support\Number::percentage((float) $value, precision: 1, locale: 'id') }}
                                    @elseif (is_bool($value))
                                        {{ $value ? 'Ya' : 'Tidak' }}
                                    @else
                                        {{ \Illuminate\Support\Number::currency((float) $value, in: 'idr', locale: 'id', precision: 0) }}
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </x-card>
        </div>
    @endunless
@endsection
