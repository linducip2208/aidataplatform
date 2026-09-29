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

    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">Laporan eksekutif</h1>
                <div class="page-subtitle">
                    Ringkasan periode yang disusun mesin AI dari KPI, tren penjualan, inventori, dan keuangan.
                </div>
            </div>
        </div>
    </div>

    <nav aria-label="Pilih periode laporan" class="mb-3">
        <ul class="list-unstyled d-flex flex-wrap gap-2 mb-0">
            @foreach ($periods as $value)
                @php $isActive = $period === $value; @endphp
                <li>
                    <a
                        href="{{ route('reports.index', ['period' => $value]) }}"
                        @if ($isActive) aria-current="page" @endif
                        class="{{ $isActive ? 'btn btn-primary' : 'btn' }}"
                    >{{ $periodLabels[$value] ?? \Illuminate\Support\Str::headline($value) }}</a>
                </li>
            @endforeach
        </ul>
    </nav>

    @unless ($engineAvailable)
        <x-card class="mb-3">
            <div role="status" class="alert alert-warning mb-0">
                <x-badge variant="warning">Mesin AI tidak tersedia</x-badge>
                <p class="mb-0 text-secondary">
                    Laporan belum dapat disusun. Periksa layanan <code>fastapi</code>
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
            class="mb-3"
            title="Ringkasan {{ $periodLabels[$period] ?? $period }}"
            description="Disusun dari data yang sudah dikomit, lalu dinarasi oleh mesin AI."
        >
            @if ($narrative === '')
                <x-empty-state
                    title="Narasi belum tersedia"
                    description="Mesin AI tidak menghasilkan narasi untuk periode ini. Nilai KPI di bawah tetap dapat dibaca."
                />
            @else
                <p class="text-secondary mb-0" style="white-space: pre-line;">{{ $narrative }}</p>
            @endif
        </x-card>

        @if ($sections !== [])
            <x-card class="mb-3" title="Rincian per bagian" description="Highlight, risiko, dan rekomendasi dari mesin AI.">
                <div class="d-grid gap-3">
                    @foreach ($sections as $sectionKey => $section)
                        <div>
                            <h3 class="fw-bold mb-2">{{ \Illuminate\Support\Str::headline((string) $sectionKey) }}</h3>
                            <ul class="mb-0 text-secondary">
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

        <div class="row row-cards">
            <div class="col-md-6">
                <x-card title="KPI periode" description="Angka penjualan yang menjadi dasar laporan.">
                    <div class="row row-cards">
                        <div class="col-sm-6"><x-stat label="Pendapatan" :value="\Illuminate\Support\Number::currency((float) ($kpi['revenue'] ?? 0), in: 'idr', locale: 'id', precision: 0)" /></div>
                        <div class="col-sm-6"><x-stat label="Jumlah pesanan" :value="number_format((int) ($kpi['orders'] ?? 0), 0, ',', '.')" /></div>
                        <div class="col-sm-6"><x-stat label="Unit terjual" :value="number_format((float) ($kpi['units'] ?? 0), 0, ',', '.')" /></div>
                        <div class="col-sm-6"><x-stat label="Nilai pesanan rata-rata" :value="\Illuminate\Support\Number::currency((float) ($kpi['aov'] ?? 0), in: 'idr', locale: 'id', precision: 0)" /></div>
                        <div class="col-sm-6"><x-stat label="Pertumbuhan" :value="\Illuminate\Support\Number::percentage((float) ($kpi['growth_pct'] ?? 0), precision: 1, locale: 'id')" /></div>
                        <div class="col-sm-6"><x-stat label="Margin" :value="\Illuminate\Support\Number::percentage((float) ($kpi['margin_pct'] ?? 0), precision: 1, locale: 'id')" /></div>
                    </div>
                </x-card>
            </div>

            <div class="col-md-6">
                <x-card title="Keuangan periode" description="Pendapatan, beban, dan laba periode berjalan.">
                    @if ($finance === [])
                        <x-empty-state title="Data keuangan kosong" description="Mesin AI tidak mengembalikan ringkasan keuangan untuk periode ini." />
                    @else
                        <dl class="datagrid">
                            @foreach ($finance as $key => $value)
                                <div class="datagrid-item">
                                    <dt class="datagrid-title">{{ $financeLabels[$key] ?? \Illuminate\Support\Str::headline((string) $key) }}</dt>
                                    <dd class="datagrid-content">
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
        </div>

        <x-card class="mt-3" title="Laporan otomatis" description="Snapshot KPI terjadwal dari mesin AI (bi.snapshot_kpis).">
            @php $autoSnapshots = $snapshots ?? []; @endphp
            @if (($snapshotsAvailable ?? false) === false)
                <x-empty-state
                    title="Snapshot belum tersedia"
                    description="Mesin AI tidak dapat dihubungi untuk daftar snapshot. Periksa layanan fastapi lalu muat ulang."
                />
            @elseif ($autoSnapshots === [])
                <x-empty-state
                    title="Belum ada snapshot terjadwal"
                    description="Belum ada KPI yang di-snapshot. Jadwalkan bi.snapshot_kpis di Celery beat (lihat docs/bi.md)."
                />
            @else
                <x-table-wrapper label="Snapshot KPI otomatis">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th scope="col">KPI</th>
                                <th scope="col" class="text-end">Nilai</th>
                                <th scope="col" class="text-end">Target</th>
                                <th scope="col">Status</th>
                                <th scope="col">Periode</th>
                                <th scope="col">Dihitung</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($autoSnapshots as $snap)
                                @php
                                    $sstatus = (string) ($snap['status'] ?? 'ok');
                                    $svariant = $sstatus === 'crit' ? 'danger' : ($sstatus === 'warn' ? 'warning' : 'success');
                                @endphp
                                <tr>
                                    <td class="fw-medium">{{ $snap['kpi_name'] ?? 'Tidak diketahui' }}</td>
                                    <td class="text-end">{{ number_format((float) ($snap['value'] ?? 0), 2, ',', '.') }}</td>
                                    <td class="text-end">
                                        @if (($snap['target'] ?? null) === null)
                                            <span class="text-secondary">—</span>
                                        @else
                                            {{ number_format((float) $snap['target'], 2, ',', '.') }}
                                        @endif
                                    </td>
                                    <td><x-badge :variant="$svariant">{{ strtoupper($sstatus) }}</x-badge></td>
                                    <td>{{ $snap['period'] ?? '—' }}</td>
                                    <td class="text-secondary">{{ $snap['computed_at'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-table-wrapper>
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <form method="POST" action="{{ url('/api/analytics/export') }}">
                        @csrf
                        <input type="hidden" name="format" value="csv">
                        <input type="hidden" name="dataset" value="kpi">
                        <button
                            type="submit"
                            class="btn"
                        >Unduh snapshot (CSV)</button>
                    </form>
                    <form method="POST" action="{{ url('/api/analytics/export') }}">
                        @csrf
                        <input type="hidden" name="format" value="xlsx">
                        <input type="hidden" name="dataset" value="kpi">
                        <button
                            type="submit"
                            class="btn"
                        >Unduh snapshot (XLSX)</button>
                    </form>
                </div>
            @endif
        </x-card>
    @endunless
@endsection
