@extends('layouts.app')

@section('title', 'System Health')

@section('content')
    @php
        $statusVariant = ['ok' => 'success', 'warn' => 'warning', 'down' => 'danger'];
        $statusLabel = ['ok' => 'OK', 'warn' => 'WARN', 'down' => 'DOWN'];
    @endphp

    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">System Health</h1>
                <div class="page-subtitle">
                    Pemeriksaan 17 titik yang sama dengan <code>php artisan platform:doctor</code>, di-cache 60 detik.
                    Diperiksa: {{ $checkedAt }}
                </div>
            </div>
            <div class="col-auto ms-auto">
                <x-badge variant="{{ $statusVariant[$status] ?? 'neutral' }}">
                    {{ $failed ? 'GAGAL MEMERIKSA' : ($statusLabel[$status] ?? $status) }}
                </x-badge>
            </div>
        </div>
    </div>

    @if ($failed)
        <x-card class="mb-3">
            <div role="alert" class="alert alert-danger mb-0">
                Pemeriksaan kesehatan gagal total. Lihat log aplikasi untuk detailnya.
            </div>
        </x-card>
    @else
        <div class="row row-cards mb-3">
            <div class="col-sm-6 col-lg-3">
                <x-stat label="OK" :value="$counts['ok'] ?? 0" />
            </div>
            <div class="col-sm-6 col-lg-3">
                <x-stat label="Peringatan" :value="$counts['warn'] ?? 0" />
            </div>
            <div class="col-sm-6 col-lg-3">
                <x-stat label="Gagal" :value="$counts['down'] ?? 0" />
            </div>
        </div>

        @foreach ($grouped as $group => $checks)
            <x-card title="{{ ucfirst($group) }}" class="mb-3">
                <x-table-wrapper label="Pemeriksaan {{ $group }}">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th scope="col">Pemeriksaan</th>
                                <th scope="col">Status</th>
                                <th scope="col">Detail</th>
                                <th scope="col">Remedi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($checks as $name => $check)
                                <tr>
                                    <td><code>{{ $name }}</code></td>
                                    <td>
                                        <x-badge variant="{{ $statusVariant[$check['status']] ?? 'neutral' }}">
                                            {{ $statusLabel[$check['status']] ?? $check['status'] }}
                                        </x-badge>
                                    </td>
                                    <td class="small text-secondary">{{ $check['detail'] ?? '' }}</td>
                                    <td class="small text-secondary">{{ $check['remedy'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-table-wrapper>
            </x-card>
        @endforeach
    @endif
@endsection
