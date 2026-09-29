@extends('layouts.app')

@section('title', 'Impor')

@section('content')
    @php
        $typeLabels = config('ai_engine.dataset_type_labels', []);
    @endphp

    <div class="page-header">
        <h1 class="page-title">Impor</h1>
        <p class="page-subtitle">
            Dataset yang sudah memiliki job impor. Buka salah satu untuk memantau progres baris yang diproses oleh worker.
        </p>
    </div>

    <x-card title="Job impor" description="{{ number_format($datasets->total(), 0, ',', '.') }} dataset memiliki job impor.">
        @if ($datasets->isEmpty())
            <x-empty-state
                title="Belum ada job impor"
                description="Job impor dibuat otomatis saat dataset diunggah dan dikomit ke gudang data."
            >
                <x-slot:action>
                    <a
                        href="{{ route('datasets.index') }}"
                        class="btn"
                    >Lihat dataset</a>
                </x-slot:action>
            </x-empty-state>
        @else
            <x-table-wrapper label="Daftar job impor">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">Dataset</th>
                            <th scope="col">Status alur</th>
                            <th scope="col" class="text-end">Job ID</th>
                            <th scope="col" class="text-end">Baris</th>
                            <th scope="col" class="text-end">Diperbarui</th>
                            <th scope="col"><span class="visually-hidden">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($datasets as $dataset)
                            @php $status = $dataset->status(); @endphp
                            <tr>
                                <td>
                                    <a
                                        href="{{ route('imports.show', $dataset) }}"
                                        class="fw-bold text-secondary"
                                    >{{ $dataset->name }}</a>
                                    <span class="text-secondary">{{ $typeLabels[$dataset->dataset_type] ?? $dataset->dataset_type }}</span>
                                </td>
                                <td><x-badge :class="$status->badgeClass()">{{ $status->localizedLabel() }}</x-badge></td>
                                <td class="text-end">{{ number_format((int) $dataset->import_job_id, 0, ',', '.') }}</td>
                                <td class="text-end">{{ number_format((int) $dataset->row_count, 0, ',', '.') }}</td>
                                <td class="text-end">
                                    @if ($dataset->updated_at)
                                        <span class="text-secondary">{{ $dataset->updated_at->locale('id')->translatedFormat('d M Y H:i') }}</span>
                                    @else
                                        <span class="text-secondary">Tidak diketahui</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a
                                        href="{{ route('imports.show', $dataset) }}"
                                        class="btn"
                                    >Detail</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>

            <div>
                {{ $datasets->links() }}
            </div>
        @endif
    </x-card>
@endsection
