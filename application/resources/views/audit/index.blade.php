@extends('layouts.app')

@section('title', 'Audit log')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-slate-900">Audit log</h1>
        <p class="mt-1 text-sm text-slate-500">
            Catatan aktivitas penting pengguna: login, perubahan dataset, pelatihan model, dan pengelolaan akun.
        </p>
    </div>

    <x-card class="mb-6" title="Filter" description="Saring berdasarkan nama aksi atau email aktor.">
        <form method="GET" action="{{ route('audit.index') }}" class="grid gap-4 sm:grid-cols-3">
            <x-field label="Aksi" for="action">
                <select
                    id="action"
                    name="action"
                    class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                >
                    <option value="">Semua aksi</option>
                    @foreach ($actions as $action)
                        <option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ $action }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field label="Aktor" for="actor" hint="Mencocokkan email aktor.">
                <input
                    id="actor"
                    name="actor"
                    type="search"
                    value="{{ $filters['actor'] ?? '' }}"
                    placeholder="mis. admin@example.com"
                    @error('actor') aria-invalid="true" @enderror
                    class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                >
            </x-field>

            <div class="flex items-end gap-2">
                <button
                    type="submit"
                    class="inline-flex flex-1 items-center justify-center rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                >Terapkan</button>
                <a
                    href="{{ route('audit.index') }}"
                    class="inline-flex items-center justify-center rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                >Atur ulang</a>
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
                <table class="app-table">
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
                                <td class="whitespace-nowrap">
                                    @if ($log->created_at)
                                        <span class="text-sm text-slate-700">{{ $log->created_at->locale('id')->translatedFormat('d M Y H:i') }}</span>
                                    @else
                                        <span class="text-xs text-slate-400">Tidak diketahui</span>
                                    @endif
                                    @if ($log->ip)
                                        <span class="mt-1 block text-xs text-slate-500">{{ $log->ip }}</span>
                                    @endif
                                </td>
                                <td class="break-all text-slate-900">{{ $log->actor ?: 'sistem' }}</td>
                                <td><code class="rounded bg-slate-100 px-1 text-xs text-slate-700">{{ $log->action }}</code></td>
                                <td>
                                    @if ($log->resource)
                                        <span class="text-slate-700">{{ $log->resource }}</span>
                                        <span class="mt-1 block text-xs text-slate-500">
                                            {{ $log->resource_id !== null ? 'ID '.$log->resource_id : 'Tanpa ID' }}
                                        </span>
                                    @else
                                        <span class="text-slate-400">Tidak ada</span>
                                    @endif
                                </td>
                                <td>
                                    @php
                                        $detail = (array) ($log->detail ?? []);
                                        $detailJson = $detail === [] ? '' : (string) json_encode($detail, JSON_UNESCAPED_UNICODE);
                                    @endphp
                                    @if ($detailJson === '')
                                        <span class="text-xs text-slate-400">Tidak ada</span>
                                    @else
                                        <details class="text-left">
                                            <summary class="cursor-pointer list-none rounded text-xs font-semibold text-brand-700 hover:text-brand-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2">
                                                <span class="sr-only">Tampilkan rincian</span>
                                                Lihat rincian
                                            </summary>
                                            <pre class="mt-2 max-w-md overflow-x-auto whitespace-pre-wrap break-all rounded bg-slate-50 p-2 text-xs text-slate-700">{{ $detailJson }}</pre>
                                        </details>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>

            <div class="mt-4">
                {{ $logs->links() }}
            </div>
        @endif
    </x-card>
@endsection
