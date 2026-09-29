@extends('layouts.app')

@section('title', 'Impor '.$dataset->name)

@section('content')
    @php
        $typeLabels = config('ai_engine.dataset_type_labels', []);
        $reportLabels = config('ai_engine.import_job_report_labels', []);

        $status = $dataset->status();
        $jobStatus = strtolower((string) ($job['status'] ?? ''));
        $progress = isset($job['progress']) ? (float) $job['progress'] : null;
        $progress = $progress === null ? null : max(0.0, min(100.0, $progress));

        // The engine reports progress in percentage points already, so it is
        // passed to Number::percentage() unscaled. An unlisted status gets a
        // neutral badge rather than the raw token.
        $jobBadge = $jobStatus === ''
            ? ['label' => 'Belum ada laporan job', 'badge' => 'badge-neutral']
            : (config('ai_engine.import_job_statuses')[$jobStatus]
                ?? ['label' => 'Status tidak dikenal', 'badge' => 'badge-neutral']);
    @endphp

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <nav aria-label="Remah roti" class="mb-2 text-sm text-slate-500">
                <a
                    href="{{ route('imports.index') }}"
                    class="rounded hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                >Impor</a>
                <span aria-hidden="true"> / </span>
                <span class="text-slate-700">{{ $dataset->name }}</span>
            </nav>

            <h1 class="text-2xl font-semibold text-slate-900">Status impor</h1>
            <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                <x-badge :class="$status->badgeClass()">{{ $status->localizedLabel() }}</x-badge>
                <span>ID job impor {{ number_format((int) $dataset->import_job_id, 0, ',', '.') }}</span>
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <a
                href="{{ route('imports.show', ['dataset' => $dataset, 'refresh' => 1]) }}"
                class="inline-flex items-center rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
            >Perbarui status</a>
            <a
                href="{{ route('datasets.show', $dataset) }}"
                class="inline-flex items-center rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
            >Kembali ke dataset</a>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-card title="Progres" description="Diambil dari mesin AI ketika tautan Perbarui status diklik.">
                @if ($progress === null)
                    <x-empty-state
                        title="Belum ada data progres"
                        description="Mesin AI belum melaporkan progres untuk job ini. Klik Perbarui status untuk mengambil laporan terbaru."
                    />
                @else
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <x-badge :class="$jobBadge['badge']">{{ $jobBadge['label'] }}</x-badge>
                        <span class="text-sm font-semibold tabular-nums text-slate-700">{{ \Illuminate\Support\Number::percentage($progress, precision: 1, locale: 'id') }}</span>                    </div>

                    <div
                        class="h-2 w-full overflow-hidden rounded-full bg-slate-200"
                        role="progressbar"
                        aria-valuenow="{{ number_format($progress, 1, '.', '') }}"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-label="Progres impor {{ $dataset->name }}"
                    >
                        <div
                            class="h-2 rounded-full {{ $progress >= 100 ? 'bg-emerald-600' : 'bg-brand-600' }}"
                            style="width: {{ number_format($progress, 1, '.', '') }}%"
                        ></div>
                    </div>
                @endif
            </x-card>

            <x-card title="Rincian baris" description="Jumlah baris yang diproses, berhasil, dan bermasalah.">
                @if ($job === [])
                    <x-empty-state
                        title="Laporan job belum tersedia"
                        description="Klik Perbarui status untuk mengambil laporan terbaru dari mesin AI."
                    />
                @else
                    <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <dt class="text-sm font-medium text-slate-500">Total baris</dt>
                            <dd class="mt-1 text-lg font-semibold tabular-nums text-slate-900">{{ number_format((int) ($job['total_rows'] ?? 0), 0, ',', '.') }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-slate-500">Baris diproses</dt>
                            <dd class="mt-1 text-lg font-semibold tabular-nums text-slate-900">{{ number_format((int) ($job['processed_rows'] ?? 0), 0, ',', '.') }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-slate-500">Baris berhasil</dt>
                            <dd class="mt-1 text-lg font-semibold tabular-nums text-emerald-700">
                                {{ number_format(max(0, (int) ($job['processed_rows'] ?? 0) - (int) ($job['error_rows'] ?? 0)), 0, ',', '.') }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-slate-500">Baris bermasalah</dt>
                            <dd class="mt-1 text-lg font-semibold tabular-nums text-rose-700">{{ number_format((int) ($job['error_rows'] ?? 0), 0, ',', '.') }}</dd>
                        </div>
                    </dl>
                @endif
            </x-card>

            <x-card title="Laporan mesin AI" description="Output mentah dari laporan status impor pada mesin AI.">
                @if (! is_array($job['report'] ?? null) || ($job['report'] ?? []) === [])
                    <x-empty-state
                        title="Laporan kosong"
                        description="Mesin AI belum memberikan laporan rinci untuk job ini."
                    />
                @else
                    <dl class="divide-y divide-slate-100 text-sm">
                        @foreach ((array) $job['report'] as $key => $value)
                            <div class="flex flex-wrap items-start justify-between gap-2 py-2">
                                <dt class="font-medium text-slate-500">{{ $reportLabels[$key] ?? \Illuminate\Support\Str::headline((string) $key) }}</dt>
                                <dd class="max-w-full break-words text-right text-slate-800">
                                    @php
                                        $reportValue = is_scalar($value) || $value === null
                                            ? (string) ($value ?? '—')
                                            : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                                    @endphp
                                    {{ $reportValue === '' ? '—' : $reportValue }}
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Dataset" description="Ringkasan berkas yang diimpor.">
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="font-medium text-slate-500">Nama</dt>
                        <dd class="text-slate-900">{{ $dataset->name }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-slate-500">Tipe</dt>
                        <dd class="text-slate-900">{{ $typeLabels[$dataset->dataset_type] ?? $dataset->dataset_type }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-slate-500">Berkas</dt>
                        <dd class="break-all text-slate-900">{{ $dataset->source_filename ?: 'Tidak diketahui' }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-slate-500">Jumlah baris tercatat</dt>
                        <dd class="text-slate-900">{{ number_format((int) $dataset->row_count, 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-slate-500">Waktu komit</dt>
                        <dd class="text-slate-900">
                            {{ $dataset->committed_at ? $dataset->committed_at->locale('id')->translatedFormat('d M Y H:i') : 'Belum dikomit' }}
                        </dd>
                    </div>
                </dl>
            </x-card>
        </div>
    </div>
@endsection
