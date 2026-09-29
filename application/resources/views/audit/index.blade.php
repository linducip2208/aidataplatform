@extends('layouts.app')

@section('title', 'Audit log')

@section('content')
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">Audit log</h1>
                <div class="page-subtitle">
                    Catatan aktivitas penting pengguna: login, perubahan dataset, pelatihan model, dan pengelolaan akun.
                </div>
            </div>
        </div>
    </div>

    <x-card class="mb-3" title="Filter" description="Saring berdasarkan nama aksi atau email aktor.">
        <form method="GET" action="{{ route('audit.index') }}">
            <div class="row row-cards">
                <div class="col-md-4">
                    <x-field label="Aksi" for="action">
                        <select
                            id="action"
                            name="action"
                            class="form-select"
                        >
                            <option value="">Semua aksi</option>
                            @foreach ($actions as $action)
                                <option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ $action }}</option>
                            @endforeach
                        </select>
                    </x-field>
                </div>

                <div class="col-md-4">
                    <x-field label="Aktor" for="actor" hint="Mencocokkan email aktor.">
                        <input
                            id="actor"
                            name="actor"
                            type="search"
                            value="{{ $filters['actor'] ?? '' }}"
                            placeholder="mis. admin@example.com"
                            @error('actor') aria-invalid="true" @enderror
                            class="form-control"
                        >
                    </x-field>
                </div>

                <div class="col-md-4">
                    <div class="d-flex align-items-end gap-2 h-100">
                        <button
                            type="submit"
                            class="btn btn-primary flex-fill"
                        >Terapkan</button>
                        <a
                            href="{{ route('audit.index') }}"
                            class="btn"
                        >Atur ulang</a>
                    </div>
                </div>
            </div>
        </form>
    </x-card>

    <x-card title="Aktivitas" description="{{ number_format($logs->total(), 0, ',', '.') }} catatan ditemukan.">
        @if ($logs->isEmpty())
            <x-empty-state
                title="Belum ada aktivitas"
                description="Catatan audit akan muncul di sini setelah ada login, unggah dataset, atau perubahan akun."
            />
        @else
            <x-table-wrapper label="Daftar aktivitas audit">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">Waktu</th>
                            <th scope="col">Aktor</th>
                            <th scope="col">Aksi</th>
                            <th scope="col">Sumber daya</th>
                            <th scope="col">Rincian</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($logs as $log)
                            <tr>
                                <td class="text-nowrap">
                                    @if ($log->created_at)
                                        <span>{{ $log->created_at->locale('id')->translatedFormat('d M Y H:i') }}</span>
                                    @else
                                        <span class="small text-secondary">Tidak diketahui</span>
                                    @endif
                                    @if ($log->ip)
                                        <span class="d-block small text-secondary">{{ $log->ip }}</span>
                                    @endif
                                </td>
                                <td class="text-break">{{ $log->actor ?: 'sistem' }}</td>
                                <td><code>{{ $log->action }}</code></td>
                                <td>
                                    @if ($log->resource)
                                        <span class="text-secondary">{{ $log->resource }}</span>
                                        <span class="d-block small text-secondary">
                                            {{ $log->resource_id !== null ? 'ID '.$log->resource_id : 'Tanpa ID' }}
                                        </span>
                                    @else
                                        <span class="text-secondary">Tidak ada</span>
                                    @endif
                                </td>
                                <td>
                                    @php
                                        $detail = (array) ($log->detail ?? []);
                                        $detailJson = $detail === [] ? '' : (string) json_encode($detail, JSON_UNESCAPED_UNICODE);
                                    @endphp
                                    @if ($detailJson === '')
                                        <span class="small text-secondary">Tidak ada</span>
                                    @else
                                        <details class="text-start">
                                            <summary>
                                                <span class="visually-hidden">Tampilkan rincian</span>
                                                Lihat rincian
                                            </summary>
                                            <pre class="mt-2 small text-secondary text-break" style="white-space: pre-wrap;">{{ $detailJson }}</pre>
                                        </details>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>

            <div class="mt-3">
                {{ $logs->links() }}
            </div>
        @endif
    </x-card>
@endsection
