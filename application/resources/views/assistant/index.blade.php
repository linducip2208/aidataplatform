@extends('layouts.app')

@section('title', 'Asisten AI')

@section('content')
    @php
        $roleLabels = [
            'user' => 'Anda',
            'assistant' => 'Asisten',
            'system' => 'Sistem',
        ];
        $canWrite = auth()->user()->isAnalyst();
        $threadTitle = $thread ? ($thread->title ?: 'Percakapan baru') : 'Percakapan baru';
    @endphp

    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">Asisten AI</h1>
                <div class="page-subtitle">
                    Tanyakan apa saja tentang data Anda. Jawaban dilengkapi jejak alat dan bukti baris yang dipakai.
                </div>
            </div>
        </div>
    </div>

    <div class="row row-cards">
        <div class="col-lg-8">
            <x-card :title="$threadTitle" description="Riwayat pesan pada percakapan terpilih.">
                @if ($messages->isEmpty())
                    <x-empty-state
                        title="Belum ada pesan"
                        description="Tulis pertanyaan pertama Anda pada formulir di bawah untuk memulai percakapan."
                    />
                @else
                    <ul class="list-unstyled mb-0 d-grid gap-3">
                        @if (! empty($messagesTruncated))
                            <li class="alert alert-warning mb-0">
                                Menampilkan {{ number_format($messages->count(), 0, ',', '.') }} pesan terbaru. Percakapan yang lebih panjang dipangkas agar halaman tetap cepat — riwayat penuh tidak ditampilkan.
                            </li>
                        @endif
                        @foreach ($messages as $message)
                            @php
                                $role = strtolower((string) ($message->role ?? 'user'));
                                $isUser = $role === 'user';
                                $evidence = (array) ($message->evidence ?? []);
                            @endphp
                            <li class="d-flex {{ $isUser ? 'justify-content-end' : 'justify-content-start' }}">
                                <div class="card w-100 {{ $isUser ? 'bg-primary-lt' : '' }}">
                                    <div class="card-body">
                                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                            <span class="text-secondary small text-uppercase fw-bold">
                                                {{ $roleLabels[$role] ?? \Illuminate\Support\Str::headline($role) }}
                                            </span>
                                            @if ($message->steps)
                                                <x-badge variant="neutral">{{ number_format((int) $message->steps, 0, ',', '.') }} langkah</x-badge>
                                            @endif
                                        </div>

                                        @if (filled($message->content))
                                            <p class="text-secondary mb-0" style="white-space: pre-line;">{{ $message->content }}</p>
                                        @else
                                            <p class="text-secondary mb-0">
                                                Balasan kosong. Mesin AI mungkin sedang offline; coba kirim ulang pertanyaan.
                                            </p>
                                        @endif

                                        @if ($evidence !== [])
                                            <div class="mt-3 pt-3 border-top">
                                                <p class="text-secondary small text-uppercase fw-bold mb-2">Bukti</p>
                                                <ul class="list-unstyled mb-0 d-grid gap-2">
                                                    {{-- Evidence comes from the agent, one {source, data} row per tool
                                                         that ran. A tool that raised still produces a row whose data is
                                                         {"error": ...}: that is a degraded result to display, not a page
                                                         error. The cast keeps a non-array row from being indexed. --}}
                                                    @foreach ($evidence as $row)
                                                        @php
                                                            $item = (array) $row;
                                                            $data = (array) ($item['data'] ?? []);
                                                        @endphp
                                                        <li class="card card-body bg-light-lt py-2 px-3">
                                                            <p class="small fw-bold mb-1">{{ $item['source'] ?? 'Sumber tidak diketahui' }}</p>
                                                            <p class="small text-secondary text-break mb-0">
                                                                @if ($data === [])
                                                                    <span class="text-secondary">Tidak ada data yang dikembalikan.</span>
                                                                @elseif (isset($data['error']))
                                                                    <span class="text-warning">Sumber ini gagal: {{ $data['error'] }}</span>
                                                                @else
                                                                    {{ json_encode($data, JSON_UNESCAPED_UNICODE) }}
                                                                @endif
                                                            </p>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($canWrite)
                    <form method="POST" action="{{ route('assistant.store') }}" class="mt-3 pt-3 border-top">
                        @csrf

                        @if ($thread)
                            <input type="hidden" name="thread_id" value="{{ $thread->getKey() }}">
                        @endif

                        <x-field label="Pertanyaan" for="message" name="message" required>
                            <textarea
                                id="message"
                                name="message"
                                rows="3"
                                required
                                placeholder="mis. Cabang mana yang paling turun pendapatannya bulan ini?"
                                @error('message') aria-invalid="true" @enderror
                                class="form-control"
                            >{{ old('message') }}</textarea>
                        </x-field>

                        <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                            <button
                                type="submit"
                                class="btn btn-primary"
                            >Kirim pertanyaan</button>
                            <a
                                href="{{ route('assistant.index') }}"
                                @if (! $thread || $thread->getKey() === $threads->first()?->getKey()) aria-current="page" @endif
                                class="btn"
                            >Kembali ke percakapan terbaru</a>
                        </div>
                    </form>
                @else
                    <div class="mt-3 pt-3 border-top">
                        <p class="text-secondary mb-0">
                            Peran <strong>{{ auth()->user()->role()->localizedLabel() }}</strong> hanya dapat membaca percakapan.
                            Mengirim pertanyaan tersedia untuk {{ \App\Enums\UserRole::Admin->localizedLabel() }} dan {{ \App\Enums\UserRole::Analyst->localizedLabel() }}.
                        </p>
                    </div>
                @endif
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card title="Riwayat percakapan" description="{{ number_format($threads->count(), 0, ',', '.') }} percakapan tersimpan.">
                @if ($threads->isEmpty())
                    <x-empty-state
                        title="Belum ada percakapan"
                        description="Percakapan yang Anda buat akan tersimpan di sini untuk dibaca kembali."
                    />
                @else
                    <ul class="list-group list-group-flush">
                        @foreach ($threads as $item)
                            @php $isCurrent = $thread && $item->getKey() === $thread->getKey(); @endphp
                            <li class="list-group-item px-0">
                                <div class="d-flex align-items-start justify-content-between gap-2">
                                    <div class="text-truncate">
                                        <a
                                            href="{{ route('assistant.threads.show', $item) }}"
                                            @if ($isCurrent) aria-current="page" @endif
                                            class="d-block text-truncate fw-medium {{ $isCurrent ? 'text-primary' : '' }}"
                                        >{{ $item->title ?: 'Percakapan tanpa judul' }}</a>
                                        <p class="small text-secondary mt-1 mb-0">
                                            {{ number_format((int) $item->message_count, 0, ',', '.') }} pesan
                                            @if ($item->last_message_at)
                                                &middot; {{ $item->last_message_at->locale('id')->translatedFormat('d M Y H:i') }}
                                            @endif
                                        </p>
                                    </div>

                                    @if ($canWrite)
                                        <form
                                            method="POST"
                                            action="{{ route('assistant.threads.destroy', $item) }}"
                                            x-on:submit.confirm="Hapus percakapan ini? Seluruh pesannya akan hilang."
                                        >
                                            @csrf
                                            @method('DELETE')
                                            <button
                                                type="submit"
                                                aria-label="Hapus percakapan {{ $item->title ?: 'tanpa judul' }}"
                                                class="btn btn-outline-danger btn-sm"
                                            >Hapus</button>
                                        </form>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>
    </div>
@endsection
