@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @php
        $typeLabels = config('ai_engine.dataset_type_labels', []);

        // GET /api/v1/health answers a bare HealthResponse: {status, app, env, version}.
        // The db/redis probes live on /readiness, which this page never calls, so
        // they must not be rendered here as if they had been measured.
        $engineIsHealthy = ($engineHealth['status'] ?? null) === 'ok';
    @endphp

    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-slate-900">Dashboard</h1>
        <p class="mt-1 text-sm text-slate-500">
            Ringkasan data, status mesin AI, dan aktivitas terbaru di workspace.
        </p>
    </div>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Total dataset" :value="number_format((int) $stats['datasets'], 0, ',', '.')" hint="Seluruh dataset yang pernah diunggah" />
        <x-stat
            :label="\App\Enums\DatasetStatus::Committed->localizedLabel()"
            :value="number_format((int) $stats['committed'], 0, ',', '.')"
            hint="Data siap dipakai analitik"
        />
        <x-stat
            :label="\App\Enums\DatasetStatus::Quarantined->localizedLabel()"
            :value="number_format((int) $stats['quarantined'], 0, ',', '.')"
            hint="Gagal melewati ambang kualitas"
        />
        {{-- Spans uploaded, previewing and importing, so it names the group
             rather than borrowing the label of any one status. --}}
        <x-stat label="Sedang diproses" :value="number_format((int) $stats['importing'], 0, ',', '.')" hint="Unggah, pratinjau, atau impor yang sedang berjalan" />
    </div>

    <x-card
        class="mb-6"
        title="Mesin AI"
        description="Status koneksi dari FastAPI yang menjalankan ingestion, kualitas, machine learning, agen, dan RAG."
    >
        @if (is_null($engineHealth))
            <div role="status" class="flex flex-wrap items-center gap-2">
                <x-badge variant="warning">Tidak terjangkau</x-badge>
                <p class="text-sm text-slate-600">
                    Mesin AI tidak merespons. Periksa apakah service <code class="rounded bg-slate-100 px-1">fastapi</code> berjalan
                    dan variabel <code class="rounded bg-slate-100 px-1">AI_ENGINE_URL</code> sudah benar. Data lokal tetap dapat dikelola.
                </p>
            </div>
        @else
            <dl class="grid gap-4 sm:grid-cols-3">
                <div>
                    <dt class="text-sm font-medium text-slate-500">Status</dt>
                    <dd class="mt-1 flex flex-wrap items-center gap-2">
                        @if ($engineIsHealthy)
                            <x-badge variant="success">Sehat</x-badge>
                        @else
                            <x-badge variant="danger">Gangguan</x-badge>
                            <span class="text-sm text-slate-600">{{ $engineHealth['status'] ?? 'tidak diketahui' }}</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-slate-500">Aplikasi</dt>
                    <dd class="mt-1 text-sm text-slate-900">
                        {{ $engineHealth['app'] ?? 'tidak dilaporkan' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-slate-500">Lingkungan</dt>
                    <dd class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-900">
                        <span>{{ $engineHealth['env'] ?? 'tidak dilaporkan' }}</span>
                        @if (! empty($engineHealth['version']))
                            <x-badge variant="neutral">versi {{ $engineHealth['version'] }}</x-badge>
                        @endif
                    </dd>
                </div>
            </dl>
        @endif
    </x-card>

    <x-card
        class="mb-6"
        title="KPI penjualan"
        description="Dihitung mesin AI dari data yang sudah dikomit, agregasi harian."
    >
        @if (is_null($kpi))
            <x-empty-state
                title="KPI belum tersedia"
                description="Mesin AI tidak merespons atau belum ada data penjualan yang dikomit. Buka halaman Analitik untuk mencoba lagi."
            />
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {{-- Rupiah carries no decimals and the engine already sends the
                     percentage fields in 0-100, so they are passed through
                     un-scaled. --}}
                <x-stat label="Pendapatan" :value="\Illuminate\Support\Number::currency((float) ($kpi['revenue'] ?? 0), in: 'idr', locale: 'id', precision: 0)" />
                <x-stat label="Jumlah pesanan" :value="number_format((int) ($kpi['orders'] ?? 0), 0, ',', '.')" />
                <x-stat label="Unit terjual" :value="number_format((float) ($kpi['units'] ?? 0), 0, ',', '.')" />
                <x-stat label="Nilai pesanan rata-rata" :value="\Illuminate\Support\Number::currency((float) ($kpi['aov'] ?? 0), in: 'idr', locale: 'id', precision: 0)" />
                <x-stat
                    label="Pertumbuhan"
                    :value="\Illuminate\Support\Number::percentage((float) ($kpi['growth_pct'] ?? 0), precision: 1, locale: 'id')"
                    :hint="((float) ($kpi['growth_pct'] ?? 0)) >= 0 ? 'Naik dibanding periode sebelumnya' : 'Turun dibanding periode sebelumnya'"
                />
                <x-stat
                    label="Margin"
                    :value="\Illuminate\Support\Number::percentage((float) ($kpi['margin_pct'] ?? 0), precision: 1, locale: 'id')"
                    hint="Margin kotor dari laporan keuangan"
                />
            </div>
        @endif
    </x-card>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-card title="Dataset terbaru" description="Delapan dataset yang terakhir diunggah.">
            <x-slot:actions>
                <a
                    href="{{ route('datasets.index') }}"
                    class="rounded-md border border-slate-300 px-3 py-1 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                >Lihat semua</a>
            </x-slot:actions>

            @if ($recentDatasets->isEmpty())
                <x-empty-state
                    title="Belum ada dataset"
                    description="Unggah berkas pertama untuk memulai alur pratinjau, pemetaan kolom, pemeriksaan kualitas, dan komit."
                >
                    <x-slot:action>
                        <a
                            href="{{ route('datasets.create') }}"
                            class="inline-flex rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                        >Unggah dataset</a>
                    </x-slot:action>
                </x-empty-state>
            @else
                <x-table-wrapper label="Dataset terbaru">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th scope="col">Dataset</th>
                                <th scope="col">Tipe</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="text-right">Baris</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recentDatasets as $dataset)
                                @php $status = $dataset->status(); @endphp
                                <tr>
                                    <td>
                                        <a
                                            href="{{ route('datasets.show', $dataset) }}"
                                            class="rounded font-medium text-slate-900 hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                                        >{{ $dataset->name }}</a>
                                        <span class="mt-1 block text-xs text-slate-500">{{ $dataset->source_filename ?: 'Tanpa nama berkas' }}</span>
                                    </td>
                                    <td>{{ $typeLabels[$dataset->dataset_type] ?? $dataset->dataset_type }}</td>
                                    <td><x-badge :class="$status->badgeClass()">{{ $status->localizedLabel() }}</x-badge></td>
                                    <td class="text-right tabular-nums">{{ number_format((int) $dataset->row_count, 0, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-table-wrapper>
            @endif
        </x-card>

        <x-card title="Percakapan terakhir" description="Riwayat obrolan Anda dengan asisten data.">
            <x-slot:actions>
                <a
                    href="{{ route('assistant.index') }}"
                    class="rounded-md border border-slate-300 px-3 py-1 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                >Buka asisten</a>
            </x-slot:actions>

            @if ($recentThreads->isEmpty())
                <x-empty-state
                    title="Belum ada percakapan"
                    description="Ajukan pertanyaan apa saja tentang data Anda kepada asisten AI."
                />
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($recentThreads as $thread)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-3 first:pt-0 last:pb-0">
                            <div class="min-w-0">
                                <a
                                    href="{{ route('assistant.threads.show', $thread) }}"
                                    class="block truncate rounded text-sm font-medium text-slate-900 hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                                >{{ $thread->title ?: 'Percakapan tanpa judul' }}</a>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ number_format((int) $thread->message_count, 0, ',', '.') }} pesan
                                    @if ($thread->last_message_at)
                                        &middot; {{ $thread->last_message_at->locale('id')->translatedFormat('d M Y H:i') }}
                                    @endif
                                </p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    </div>
@endsection
