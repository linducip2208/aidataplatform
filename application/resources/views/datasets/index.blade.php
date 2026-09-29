@extends('layouts.app')

@section('title', 'Kumpulan data')

@section('content')
    @php
        $typeLabels = config('ai_engine.dataset_type_labels', []);
    @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Kumpulan data</h1>
            <p class="mt-1 text-sm text-slate-500">Kelola berkas yang diunggah dan pantau alur pemrosesannya.</p>
        </div>

        @if (auth()->user()->isAnalyst())
            <a
                href="{{ route('datasets.create') }}"
                class="inline-flex items-center rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
            >Unggah dataset</a>
        @endif
    </div>

    <x-card class="mb-6" title="Filter" description="Saring daftar berdasarkan nama berkas, tipe, atau status.">
        <form method="GET" action="{{ route('datasets.index') }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-field label="Cari" for="q" hint="Mencocokkan nama dataset atau nama berkas.">
                <input
                    id="q"
                    name="q"
                    type="search"
                    value="{{ $filters['q'] ?? '' }}"
                    placeholder="mis. penjualan 2026"
                    class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                >
            </x-field>

            <x-field label="Tipe dataset" for="dataset_type">
                <select
                    id="dataset_type"
                    name="dataset_type"
                    class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                >
                    <option value="">Semua tipe</option>
                    @foreach ($datasetTypes as $type)
                        <option value="{{ $type }}" @selected(($filters['dataset_type'] ?? '') === $type)>{{ $typeLabels[$type] ?? $type }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field label="Status" for="status">
                <select
                    id="status"
                    name="status"
                    class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                >
                    <option value="">Semua status</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->localizedLabel() }}</option>
                    @endforeach
                </select>
            </x-field>

            <div class="flex items-end gap-2">
                <button
                    type="submit"
                    class="inline-flex flex-1 items-center justify-center rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                >Terapkan</button>
                <a
                    href="{{ route('datasets.index') }}"
                    class="inline-flex items-center justify-center rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                >Atur ulang</a>
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
                <table class="app-table">
                    <thead>
                        <tr>
                            <th scope="col">Dataset</th>
                            <th scope="col">Tipe</th>
                            <th scope="col">Status</th>
                            <th scope="col">Kualitas</th>
                            <th scope="col" class="text-right">Baris</th>
                            <th scope="col" class="text-right">Ukuran</th>
                            <th scope="col" class="text-right">Diunggah</th>
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
                                        class="rounded font-medium text-slate-900 hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                                    >{{ $dataset->name }}</a>
                                    <span class="mt-1 block text-xs text-slate-500">{{ $dataset->source_filename ?: 'Tanpa nama berkas' }}</span>
                                </td>
                                <td>{{ $typeLabels[$dataset->dataset_type] ?? $dataset->dataset_type }}</td>
                                <td><x-badge :class="$status->badgeClass()">{{ $status->localizedLabel() }}</x-badge></td>
                                <td>
                                    @if ($dataset->quality_score !== null)
                                        {{-- The score is stored 0-1 while Number::percentage()
                                             expects percentage points, hence the x100. --}}
                                        <span class="tabular-nums">{{ \Illuminate\Support\Number::percentage((float) $dataset->quality_score * 100, precision: 1, locale: 'id') }}</span>
                                        <span class="mt-1 block">
                                            <x-badge :class="$verdict ? $verdict->badgeClass() : 'badge-neutral'">
                                                {{ $verdict ? $verdict->localizedLabel() : 'Tidak diketahui' }}
                                            </x-badge>
                                        </span>
                                    @else
                                        <x-badge variant="neutral">Belum dinilai</x-badge>
                                    @endif
                                </td>
                                <td class="text-right tabular-nums">{{ number_format((int) $dataset->row_count, 0, ',', '.') }}</td>
                                <td class="text-right tabular-nums">{{ $dataset->sizeForHumans() }}</td>
                                <td class="text-right">
                                    @if ($dataset->created_at)
                                        <span class="text-xs text-slate-500">{{ $dataset->created_at->locale('id')->translatedFormat('d M Y') }}</span>
                                    @else
                                        <span class="text-xs text-slate-400">Tidak diketahui</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>

            <div class="mt-4">
                {{ $datasets->links() }}
            </div>
        @endif
    </x-card>
@endsection
