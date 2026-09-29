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

    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">Dashboard</h1>
                <div class="page-subtitle">
                    Ringkasan data, status mesin AI, dan aktivitas terbaru di workspace.
                </div>
            </div>
        </div>
    </div>

    <div class="row row-cards mb-3">
        <div class="col-sm-6 col-lg-3">
            <x-stat label="Total dataset" :value="number_format((int) $stats['datasets'], 0, ',', '.')" hint="Seluruh dataset yang pernah diunggah" />
        </div>
        <div class="col-sm-6 col-lg-3">
            <x-stat
                :label="\App\Enums\DatasetStatus::Committed->localizedLabel()"
                :value="number_format((int) $stats['committed'], 0, ',', '.')"
                hint="Data siap dipakai analitik"
            />
        </div>
        <div class="col-sm-6 col-lg-3">
            <x-stat
                :label="\App\Enums\DatasetStatus::Quarantined->localizedLabel()"
                :value="number_format((int) $stats['quarantined'], 0, ',', '.')"
                hint="Gagal melewati ambang kualitas"
            />
        </div>
        {{-- Spans uploaded, previewing and importing, so it names the group
             rather than borrowing the label of any one status. --}}
        <div class="col-sm-6 col-lg-3">
            <x-stat label="Sedang diproses" :value="number_format((int) $stats['importing'], 0, ',', '.')" hint="Unggah, pratinjau, atau impor yang sedang berjalan" />
        </div>
    </div>

    <x-card
        class="mb-3"
        title="Mesin AI"
        description="Status koneksi dari FastAPI yang menjalankan ingestion, kualitas, machine learning, agen, dan RAG."
    >
        @if (is_null($engineHealth))
            <div role="status" class="d-flex flex-wrap align-items-center gap-2">
                <x-badge variant="warning">Tidak terjangkau</x-badge>
                <p class="text-secondary">
                    Mesin AI tidak merespons. Periksa apakah service <code>fastapi</code> berjalan
                    dan variabel <code>AI_ENGINE_URL</code> sudah benar. Data lokal tetap dapat dikelola.
                </p>
            </div>
        @else
            <dl class="datagrid">
                <div class="datagrid-item">
                    <dt class="datagrid-title">Status</dt>
                    <dd class="datagrid-content">
                        @if ($engineIsHealthy)
                            <x-badge variant="success">Sehat</x-badge>
                        @else
                            <x-badge variant="danger">Gangguan</x-badge>
                            <span class="text-secondary">{{ $engineHealth['status'] ?? 'tidak diketahui' }}</span>
                        @endif
                    </dd>
                </div>
                <div class="datagrid-item">
                    <dt class="datagrid-title">Aplikasi</dt>
                    <dd class="datagrid-content">
                        {{ $engineHealth['app'] ?? 'tidak dilaporkan' }}
                    </dd>
                </div>
                <div class="datagrid-item">
                    <dt class="datagrid-title">Lingkungan</dt>
                    <dd class="datagrid-content">
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
        class="mb-3"
        title="KPI penjualan"
        description="Dihitung mesin AI dari data yang sudah dikomit, agregasi harian."
    >
        @if (is_null($kpi))
            <x-empty-state
                title="KPI belum tersedia"
                description="Mesin AI tidak merespons atau belum ada data penjualan yang dikomit. Buka halaman Analitik untuk mencoba lagi."
            />
        @else
            <div class="row row-cards">
                {{-- Rupiah carries no decimals and the engine already sends the
                     percentage fields in 0-100, so they are passed through
                     un-scaled. --}}
                <div class="col-sm-6 col-lg-4">
                    <x-stat label="Pendapatan" :value="\Illuminate\Support\Number::currency((float) ($kpi['revenue'] ?? 0), in: 'idr', locale: 'id', precision: 0)" />
                </div>
                <div class="col-sm-6 col-lg-4">
                    <x-stat label="Jumlah pesanan" :value="number_format((int) ($kpi['orders'] ?? 0), 0, ',', '.')" />
                </div>
                <div class="col-sm-6 col-lg-4">
                    <x-stat label="Unit terjual" :value="number_format((float) ($kpi['units'] ?? 0), 0, ',', '.')" />
                </div>
                <div class="col-sm-6 col-lg-4">
                    <x-stat label="Nilai pesanan rata-rata" :value="\Illuminate\Support\Number::currency((float) ($kpi['aov'] ?? 0), in: 'idr', locale: 'id', precision: 0)" />
                </div>
                <div class="col-sm-6 col-lg-4">
                    <x-stat
                        label="Pertumbuhan"
                        :value="\Illuminate\Support\Number::percentage((float) ($kpi['growth_pct'] ?? 0), precision: 1, locale: 'id')"
                        :hint="((float) ($kpi['growth_pct'] ?? 0)) >= 0 ? 'Naik dibanding periode sebelumnya' : 'Turun dibanding periode sebelumnya'"
                    />
                </div>
                <div class="col-sm-6 col-lg-4">
                    <x-stat
                        label="Margin"
                        :value="\Illuminate\Support\Number::percentage((float) ($kpi['margin_pct'] ?? 0), precision: 1, locale: 'id')"
                        hint="Margin kotor dari laporan keuangan"
                    />
                </div>
            </div>
        @endif
    </x-card>

    <div class="row row-cards">
        <div class="col-md-6">
            <x-card title="Dataset terbaru" description="Delapan dataset yang terakhir diunggah.">
                <x-slot:actions>
                    <a
                        href="{{ route('datasets.index') }}"
                        class="btn"
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
                                class="btn btn-primary"
                            >Unggah dataset</a>
                        </x-slot:action>
                    </x-empty-state>
                @else
                    <x-table-wrapper label="Dataset terbaru">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th scope="col">Dataset</th>
                                    <th scope="col">Tipe</th>
                                    <th scope="col">Status</th>
                                    <th scope="col" class="text-end">Baris</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recentDatasets as $dataset)
                                    @php $status = $dataset->status(); @endphp
                                    <tr>
                                        <td>
                                            <a
                                                href="{{ route('datasets.show', $dataset) }}"
                                            >{{ $dataset->name }}</a>
                                            <span class="d-block text-secondary small">{{ $dataset->source_filename ?: 'Tanpa nama berkas' }}</span>
                                        </td>
                                        <td>{{ $typeLabels[$dataset->dataset_type] ?? $dataset->dataset_type }}</td>
                                        <td><x-badge :class="$status->badgeClass()">{{ $status->localizedLabel() }}</x-badge></td>
                                        <td class="text-end tabular-nums">{{ number_format((int) $dataset->row_count, 0, ',', '.') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-table-wrapper>
                @endif
            </x-card>
        </div>

        <div class="col-md-6">
            <x-card title="Percakapan terakhir" description="Riwayat obrolan Anda dengan asisten data.">
                <x-slot:actions>
                    <a
                        href="{{ route('assistant.index') }}"
                        class="btn"
                    >Buka asisten</a>
                </x-slot:actions>

                @if ($recentThreads->isEmpty())
                    <x-empty-state
                        title="Belum ada percakapan"
                        description="Ajukan pertanyaan apa saja tentang data Anda kepada asisten AI."
                    />
                @else
                    <ul class="list-group list-group-flush">
                        @foreach ($recentThreads as $thread)
                            <li class="list-group-item">
                                <div>
                                    <a
                                        href="{{ route('assistant.threads.show', $thread) }}"
                                    >{{ $thread->title ?: 'Percakapan tanpa judul' }}</a>
                                    <p class="text-secondary small">
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
    </div>
@endsection
