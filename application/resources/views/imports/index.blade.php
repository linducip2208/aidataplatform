@extends('layouts.app')

@section('title', 'Imports')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-slate-900">Imports</h1>
        <p class="mt-1 text-sm text-slate-500">
            Dataset yang sudah memiliki job import. Buka salah satu untuk memantau progres baris yang diproses worker.
        </p>
    </div>

    <x-card title="Job import" description="{{ number_format($datasets->total()) }} dataset memiliki job import.">
        @if ($datasets->isEmpty())
            <x-empty-state
                title="Belum ada job import"
                description="Job import dibuat otomatis saat dataset diunggah dan di-commit ke gudang analytics."
            >
                <x-slot:action>
                    <a
                        href="{{ route('datasets.index') }}"
                        class="inline-flex rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                    >Lihat dataset</a>
                </x-slot:action>
            </x-empty-state>
        @else
            <x-table-wrapper label="Daftar job import">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th scope="col">Dataset</th>
                            <th scope="col">Status alur</th>
                            <th scope="col" class="text-right">Job ID</th>
                            <th scope="col" class="text-right">Baris</th>
                            <th scope="col" class="text-right">Diperbarui</th>
                            <th scope="col"><span class="sr-only">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($datasets as $dataset)
                            @php $status = $dataset->status(); @endphp
                            <tr>
                                <td>
                                    <a
                                        href="{{ route('imports.show', $dataset) }}"
                                        class="rounded font-medium text-slate-900 hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                                    >{{ $dataset->name }}</a>
                                    <span class="mt-1 block text-xs text-slate-500">{{ $dataset->dataset_type }}</span>
                                </td>
                                <td><x-badge :class="$status->badgeClass()">{{ $status->label() }}</x-badge></td>
                                <td class="text-right tabular-nums">{{ number_format((int) $dataset->import_job_id) }}</td>
                                <td class="text-right tabular-nums">{{ number_format((int) $dataset->row_count) }}</td>
                                <td class="text-right">
                                    @if ($dataset->updated_at)
                                        <span class="text-xs text-slate-500">{{ $dataset->updated_at->locale('id')->translatedFormat('d M Y H:i') }}</span>
                                    @else
                                        <span class="text-xs text-slate-400">Tidak diketahui</span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    <a
                                        href="{{ route('imports.show', $dataset) }}"
                                        class="rounded-md border border-slate-300 px-3 py-1 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                                    >Detail</a>
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
