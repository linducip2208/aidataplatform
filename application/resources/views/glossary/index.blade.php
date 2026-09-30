@extends('layouts.app')

@section('title', 'Glosarium bisnis')

@section('content')
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">Glosarium bisnis</h1>
                <p class="page-subtitle">
                    Definisi metrik tersertifikasi yang dipakai AI saat menjawab dan menyusun SQL.
                    @if ($version)
                        <x-badge variant="info">versi {{ $version }}</x-badge>
                    @endif
                </p>
            </div>
        </div>
    </div>

    @unless ($engineAvailable)
        <x-card class="mb-3">
            <div role="status" class="alert alert-warning">
                <x-badge variant="warning">Mesin AI tidak tersedia</x-badge>
                <p class="text-secondary">
                    Daftar definisi belum dapat dimuat. Periksa layanan <code>fastapi</code>
                    lalu muat ulang halaman ini.
                </p>
            </div>
        </x-card>
    @endunless

    <x-card title="Definisi metrik" description="Rumus mengutip fungsi yang menghitungnya — bukan tebakan.">
        @if ($metrics === [])
            <x-empty-state
                title="Belum ada definisi"
                description="Mesin AI tidak mengembalikan glosarium."
            />
        @else
            <x-table-wrapper label="Definisi metrik tersertifikasi">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">Metrik</th>
                            <th scope="col">Definisi</th>
                            <th scope="col">Rumus</th>
                            <th scope="col">Sumber</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($metrics as $metric)
                            <tr>
                                <td>
                                    <div class="fw-bold text-secondary"><code>{{ $metric['name'] ?? '?' }}</code></div>
                                    <div class="small text-secondary">{{ collect((array) ($metric['aliases'] ?? []))->take(4)->implode(', ') }}</div>
                                </td>
                                <td class="small text-secondary">{{ $metric['definition'] ?? '' }}</td>
                                <td><code>{{ $metric['formula'] ?? '' }}</code></td>
                                <td class="small text-secondary">{{ $metric['source'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>
        @endif
    </x-card>
@endsection
