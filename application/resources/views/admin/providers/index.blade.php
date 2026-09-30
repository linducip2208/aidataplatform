@extends('layouts.app')

@section('title', 'Provider AI')

@section('content')
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">Provider AI (BYOK)</h1>
                <div class="page-subtitle">
                    Daftarkan kunci milikmu sendiri. Kunci tidak pernah disimpan — hanya dipakai saat uji dan tulis env.
                    @if ($active)
                        Aktif: <strong>{{ $active->name }}</strong> ({{ $active->typeLabel() }}).
                    @else
                        Belum ada provider aktif.
                    @endif
                </div>
            </div>
            <div class="col-auto ms-auto">
                <a href="{{ route('admin.providers.create') }}" class="btn btn-primary">Tambah provider</a>
            </div>
        </div>
    </div>

    @if (session('publish_block'))
        <x-card class="mb-3" title="Blok env untuk operator" description="Jalankan perintah di bawah setelah menempel.">
            <pre class="mb-2 p-3 bg-light border rounded small">{{ session('publish_block') }}</pre>
            <p class="small text-secondary"><code>{{ session('publish_restart') }}</code></p>
        </x-card>
    @endif

    <x-card title="Provider terdaftar" description="Kunci API tidak ditampilkan di mana pun setelah disimpan.">
        @if ($providers->isEmpty())
            <x-empty-state
                title="Belum ada provider"
                description="Daftarkan provider pertama (mis. OpenRouter atau Ollama lokal)."
            />
        @else
            <x-table-wrapper label="Provider AI">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">Nama</th>
                            <th scope="col">Tipe</th>
                            <th scope="col">Model</th>
                            <th scope="col" class="text-end">Prioritas</th>
                            <th scope="col">Status</th>
                            <th scope="col">Uji terakhir</th>
                            <th scope="col"><span class="visually-hidden">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($providers as $provider)
                            <tr>
                                <td>
                                    <div class="fw-bold text-secondary">{{ $provider->name }}</div>
                                    <div class="small text-secondary text-truncate" style="max-width: 22rem;">{{ $provider->base_url }}</div>
                                </td>
                                <td class="small text-secondary">{{ $provider->typeLabel() }}</td>
                                <td><code>{{ $provider->model }}</code></td>
                                <td class="text-end">{{ $provider->priority }}</td>
                                <td>
                                    <x-badge variant="{{ $provider->is_active ? 'success' : 'neutral' }}">
                                        {{ $provider->is_active ? 'Aktif' : 'Nonaktif' }}
                                    </x-badge>
                                </td>
                                <td class="small text-secondary">
                                    @if ($provider->last_tested_at)
                                        {{ $provider->last_test_status }} &middot; {{ $provider->last_tested_at->locale('id')->translatedFormat('d M Y H:i') }}
                                    @else
                                        Belum diuji
                                    @endif
                                </td>
                                <td class="text-end">
                                    <div class="d-flex flex-wrap justify-content-end gap-1">
                                        <a href="{{ route('admin.providers.edit', $provider) }}" class="btn btn-sm">Ubah</a>
                                        <form method="POST" action="{{ route('admin.providers.toggle', $provider) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm">{{ $provider->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.providers.destroy', $provider) }}" x-data x-on:submit.confirm="Hapus provider ini?">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="7" class="bg-light">
                                    <div class="d-flex flex-wrap gap-2 align-items-end">
                                        <form method="POST" action="{{ route('admin.providers.test', $provider) }}" class="d-flex flex-wrap gap-2 align-items-end">
                                            @csrf
                                            <div>
                                                <label for="test-key-{{ $provider->getKey() }}" class="form-label small">API key untuk uji</label>
                                                <input id="test-key-{{ $provider->getKey() }}" name="api_key" type="password" autocomplete="off" class="form-control form-control-sm" placeholder="tidak disimpan">
                                            </div>
                                            <button type="submit" class="btn btn-sm">Uji koneksi</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.providers.publish', $provider) }}" class="d-flex flex-wrap gap-2 align-items-end">
                                            @csrf
                                            <div>
                                                <label for="publish-key-{{ $provider->getKey() }}" class="form-label small">API key untuk terbit</label>
                                                <input id="publish-key-{{ $provider->getKey() }}" name="api_key" type="password" autocomplete="off" class="form-control form-control-sm" placeholder="tidak disimpan">
                                            </div>
                                            <button type="submit" class="btn btn-sm btn-primary">Terbitkan</button>
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

    @if ($inContainer)
        <x-card title="Mode container" description="Publish dari UI tidak berlaku di sini.">
            <p class="text-secondary small mb-0">
                Container menerima env saat dibuat; file env di dalam container tidak terbaca ulang.
                Gunakan tombol Terbitkan untuk menyalin blok, tempel ke root <code>.env</code> di host,
                lalu buat ulang service AI.
            </p>
        </x-card>
    @endif
@endsection
