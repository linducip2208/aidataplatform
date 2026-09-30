@extends('layouts.app')

@section('title', 'Webhooks')

@section('content')
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">Webhooks</h1>
                <p class="page-subtitle">
                    Kejadian platform diteruskan bertanda HMAC ke URL langganan, dengan retry dan riwayat pengiriman.
                </p>
            </div>
        </div>
    </div>

    @if (session('webhook_secret'))
        <x-card class="mb-3" title="Secret sekali tampil" description="Salin sekarang. Tidak akan ditampilkan lagi.">
            <p><code>{{ session('webhook_secret') }}</code></p>
        </x-card>
    @endif

    <x-card title="Langganan" description="Hanya URL publik (perlindungan SSRF aktif).">
        @if ($webhooks->isEmpty())
            <x-empty-state
                title="Belum ada webhook"
                description="Daftarkan URL pertama melalui formulir di bawah."
            />
        @else
            <x-table-wrapper label="Langganan webhook">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">Nama</th>
                            <th scope="col">URL</th>
                            <th scope="col">Event</th>
                            <th scope="col">Aktif</th>
                            <th scope="col"><span class="visually-hidden">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($webhooks as $webhook)
                            <tr>
                                <td class="fw-bold text-secondary">{{ $webhook->name }}</td>
                                <td class="small text-secondary text-truncate" style="max-width: 20rem;">{{ $webhook->url }}</td>
                                <td class="small text-secondary">{{ collect((array) $webhook->events)->implode(', ') }}</td>
                                <td>
                                    <x-badge variant="{{ $webhook->is_active ? 'success' : 'neutral' }}">
                                        {{ $webhook->is_active ? 'Aktif' : 'Nonaktif' }}
                                    </x-badge>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex flex-wrap justify-content-end gap-1">
                                        <form method="POST" action="{{ route('admin.webhooks.toggle', $webhook) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm">{{ $webhook->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.webhooks.destroy', $webhook) }}" x-data x-on:submit.confirm="Hapus webhook ini beserta riwayatnya?">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>
        @endif
    </x-card>

    <x-card title="Daftarkan webhook" description="Secret dibuat otomatis dan tampil sekali.">
        <form method="POST" action="{{ route('admin.webhooks.store') }}" class="row row-cards">
            @csrf
            <div class="col-md-6">
                <x-field label="Nama" for="webhook_name" name="name" required>
                    <input id="webhook_name" name="name" type="text" value="{{ old('name') }}" required maxlength="128" placeholder="mis. Notifikasi tim" @error('name') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>
            <div class="col-md-6">
                <x-field label="URL" for="webhook_url" name="url" required hint="https publik, bukan IP privat.">
                    <input id="webhook_url" name="url" type="url" value="{{ old('url') }}" required maxlength="1024" placeholder="https://contoh.id/hook" @error('url') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>
            <div class="col-12">
                <fieldset>
                    <legend class="form-label">Event</legend>
                    <div class="d-flex flex-wrap gap-3">
                        @foreach ($events as $event)
                            <label class="form-check">
                                <input name="events[]" type="checkbox" value="{{ $event }}" @checked(in_array($event, old('events', []), true)) class="form-check-input">
                                <span class="form-check-label"><code>{{ $event }}</code></span>
                            </label>
                        @endforeach
                    </div>
                    @error('events')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </fieldset>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">Daftarkan</button>
            </div>
        </form>
    </x-card>

    <x-card title="50 pengiriman terakhir" description="Gagal bisa diantre ulang.">
        @if ($deliveries->isEmpty())
            <x-empty-state title="Belum ada pengiriman" description="Pengiriman tercatat setiap event dipicu." />
        @else
            <x-table-wrapper label="Riwayat pengiriman webhook">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col" class="text-end">ID</th>
                            <th scope="col">Event</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-end">HTTP</th>
                            <th scope="col" class="text-end">Percobaan</th>
                            <th scope="col"><span class="visually-hidden">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($deliveries as $delivery)
                            <tr>
                                <td class="text-end">{{ $delivery->getKey() }}</td>
                                <td><code>{{ $delivery->event }}</code></td>
                                <td>
                                    <x-badge variant="{{ $delivery->status === 'delivered' ? 'success' : ($delivery->status === 'failed' ? 'danger' : 'warning') }}">
                                        {{ $delivery->status }}
                                    </x-badge>
                                </td>
                                <td class="text-end">{{ $delivery->http_status ?? '—' }}</td>
                                <td class="text-end">{{ $delivery->attempts }}</td>
                                <td class="text-end">
                                    @if ($delivery->status === 'failed')
                                        <form method="POST" action="{{ route('admin.webhooks.replay', $delivery) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm">Antre ulang</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>
        @endif
    </x-card>
@endsection
