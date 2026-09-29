@extends('layouts.app')

@section('title', 'Kumpulan data')

@section('content')
    @php
        $typeLabels = config('ai_engine.dataset_type_labels', []);
    @endphp

    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">Kumpulan data</h1>
                <div class="page-subtitle">Kelola berkas yang diunggah dan pantau alur pemrosesannya.</div>
            </div>
            @if (auth()->user()->isAnalyst())
                <div class="col-auto ms-auto">
                    <a
                        href="{{ route('datasets.create') }}"
                        class="btn btn-primary"
                    >Unggah dataset</a>
                </div>
            @endif
        </div>
    </div>

    <x-card class="mb-3" title="Filter" description="Saring daftar berdasarkan nama berkas, tipe, atau status.">
        <form method="GET" action="{{ route('datasets.index') }}" class="row row-cards">
            <div class="col-sm-6 col-lg-3">
                <x-field label="Cari" for="q" hint="Mencocokkan nama dataset atau nama berkas.">
                    <input
                        id="q"
                        name="q"
                        type="search"
                        value="{{ $filters['q'] ?? '' }}"
                        placeholder="mis. penjualan 2026"
                        class="form-control"
                    >
                </x-field>
            </div>

            <div class="col-sm-6 col-lg-3">
                <x-field label="Tipe dataset" for="dataset_type">
                    <select
                        id="dataset_type"
                        name="dataset_type"
                        class="form-select"
                    >
                        <option value="">Semua tipe</option>
                        @foreach ($datasetTypes as $type)
                            <option value="{{ $type }}" @selected(($filters['dataset_type'] ?? '') === $type)>{{ $typeLabels[$type] ?? $type }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>

            <div class="col-sm-6 col-lg-3">
                <x-field label="Status" for="status">
                    <select
                        id="status"
                        name="status"
                        class="form-select"
                    >
                        <option value="">Semua status</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->localizedLabel() }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>

            <div class="col-sm-6 col-lg-3">
                <div class="d-flex align-items-end gap-2">
                    <button
                        type="submit"
                        class="btn btn-primary"
                    >Terapkan</button>
                    <a
                        href="{{ route('datasets.index') }}"
                        class="btn"
                    >Atur ulang</a>
                </div>
            </div>
        </form>
    </x-card>

    <x-card title="Daftar dataset" description="{{ number_format($datasets->total(), 0, ',', '.') }} dataset ditemukan.">
        @if ($datasets->isEmpty())
            <x-empty-state
                title="Tidak ada dataset yang cocok"
                description="Ubah kata kunci atau filter, atau unggah berkas baru untuk memulai."
            />
        @else
            <x-table-wrapper label="Daftar dataset">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">Dataset</th>
                            <th scope="col">Tipe</th>
                            <th scope="col">Status</th>
                            <th scope="col">Kualitas</th>
                            <th scope="col" class="text-end">Baris</th>
                            <th scope="col" class="text-end">Ukuran</th>
                            <th scope="col" class="text-end">Diunggah</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($datasets as $dataset)
                            @php
                                $status = $dataset->status();
                                $verdict = $dataset->qualityVerdict();
                            @endphp
                            <tr>
                                <td>
                                    <a
                                        href="{{ route('datasets.show', $dataset) }}"
                                    >{{ $dataset->name }}</a>
                                    <span class="d-block text-secondary small">{{ $dataset->source_filename ?: 'Tanpa nama berkas' }}</span>
                                </td>
                                <td>{{ $typeLabels[$dataset->dataset_type] ?? $dataset->dataset_type }}</td>
                                <td><x-badge :class="$status->badgeClass()">{{ $status->localizedLabel() }}</x-badge></td>
                                <td>
                                    @if ($dataset->quality_score !== null)
                                        {{-- The score is stored 0-1 while Number::percentage()
                                             expects percentage points, hence the x100. --}}
                                        <span class="tabular-nums">{{ \Illuminate\Support\Number::percentage((float) $dataset->quality_score * 100, precision: 1, locale: 'id') }}</span>
                                        <span class="d-block">
                                            <x-badge :class="$verdict ? $verdict->badgeClass() : 'badge-neutral'">
                                                {{ $verdict ? $verdict->localizedLabel() : 'Tidak diketahui' }}
                                            </x-badge>
                                        </span>
                                    @else
                                        <x-badge variant="neutral">Belum dinilai</x-badge>
                                    @endif
                                </td>
                                <td class="text-end tabular-nums">{{ number_format((int) $dataset->row_count, 0, ',', '.') }}</td>
                                <td class="text-end tabular-nums">{{ $dataset->sizeForHumans() }}</td>
                                <td class="text-end">
                                    @if ($dataset->created_at)
                                        <span class="text-secondary small">{{ $dataset->created_at->locale('id')->translatedFormat('d M Y') }}</span>
                                    @else
                                        <span class="text-secondary small">Tidak diketahui</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>

            <div class="mt-3">
                {{ $datasets->links() }}
            </div>
        @endif
    </x-card>
@endsection
