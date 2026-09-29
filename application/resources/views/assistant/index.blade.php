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

    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-slate-900">Asisten AI</h1>
        <p class="mt-1 text-sm text-slate-500">
            Tanyakan apa saja tentang data Anda. Jawaban dilengkapi jejak alat dan bukti baris yang dipakai.
        </p>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-card class="lg:col-span-2" :title="$threadTitle" description="Riwayat pesan pada percakapan terpilih.">
            @if ($messages->isEmpty())
                <x-empty-state
                    title="Belum ada pesan"
                    description="Tulis pertanyaan pertama Anda pada formulir di bawah untuk memulai percakapan."
                />
            @else
                <ul class="space-y-4">
                    @if (! empty($messagesTruncated))
                        <li class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                            Menampilkan {{ number_format($messages->count(), 0, ',', '.') }} pesan terbaru. Percakapan yang lebih panjang dipangkas agar halaman tetap cepat — riwayat penuh tidak ditampilkan.
                        </li>
                    @endif
                    @foreach ($messages as $message)
                        @php
                            $role = strtolower((string) ($message->role ?? 'user'));
                            $isUser = $role === 'user';
                            $evidence = (array) ($message->evidence ?? []);
                        @endphp
                        <li class="flex {{ $isUser ? 'justify-end' : 'justify-start' }}">
                            <div class="max-w-full rounded-lg border px-4 py-3 sm:max-w-2xl {{ $isUser ? 'border-brand-200 bg-brand-50' : 'border-slate-200 bg-white' }}">
                                <div class="mb-2 flex flex-wrap items-center gap-2">
                                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        {{ $roleLabels[$role] ?? \Illuminate\Support\Str::headline($role) }}
                                    </span>
                                    @if ($message->steps)
                                        <x-badge variant="neutral">{{ number_format((int) $message->steps, 0, ',', '.') }} langkah</x-badge>
                                    @endif
                                </div>

                                @if (filled($message->content))
                                    <p class="whitespace-pre-line text-sm text-slate-800">{{ $message->content }}</p>
                                @else
                                    <p class="text-sm text-slate-500">
                                        Balasan kosong. Mesin AI mungkin sedang offline; coba kirim ulang pertanyaan.
                                    </p>
                                @endif

                                @if ($evidence !== [])
                                    <div class="mt-3 border-t border-slate-200 pt-3">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Bukti</p>
                                        <ul class="mt-2 space-y-2">
                                            {{-- Evidence comes from the agent, one {source, data} row per tool
                                                 that ran. A tool that raised still produces a row whose data is
                                                 {"error": ...}: that is a degraded result to display, not a page
                                                 error. The cast keeps a non-array row from being indexed. --}}
                                            @foreach ($evidence as $row)
                                                @php
                                                    $item = (array) $row;
                                                    $data = (array) ($item['data'] ?? []);
                                                @endphp
                                                <li class="rounded border border-slate-200 bg-slate-50 px-3 py-2">
                                                    <p class="text-xs font-semibold text-slate-700">{{ $item['source'] ?? 'Sumber tidak diketahui' }}</p>
                                                    <p class="mt-1 break-words text-xs text-slate-600">
                                                        @if ($data === [])
                                                            <span class="text-slate-400">Tidak ada data yang dikembalikan.</span>
                                                        @elseif (isset($data['error']))
                                                            <span class="text-amber-800">Sumber ini gagal: {{ $data['error'] }}</span>
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
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($canWrite)
                <form method="POST" action="{{ route('assistant.store') }}" class="mt-6 border-t border-slate-200 pt-4">
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
                            class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                        >{{ old('message') }}</textarea>
                    </x-field>

                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <button
                            type="submit"
                            class="inline-flex items-center rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                        >Kirim pertanyaan</button>
                        <a
                            href="{{ route('assistant.index') }}"
                            @if (! $thread || $thread->getKey() === $threads->first()?->getKey()) aria-current="page" @endif
                            class="inline-flex items-center rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                        >Kembali ke percakapan terbaru</a>
                    </div>
                </form>
            @else
                <div class="mt-6 border-t border-slate-200 pt-4">
                    <p class="text-sm text-slate-600">
                        Peran <strong>{{ auth()->user()->role()->localizedLabel() }}</strong> hanya dapat membaca percakapan.
                        Mengirim pertanyaan tersedia untuk {{ \App\Enums\UserRole::Admin->localizedLabel() }} dan {{ \App\Enums\UserRole::Analyst->localizedLabel() }}.
                    </p>
                </div>
            @endif
        </x-card>

        <x-card title="Riwayat percakapan" description="{{ number_format($threads->count(), 0, ',', '.') }} percakapan tersimpan.">
            @if ($threads->isEmpty())
                <x-empty-state
                    title="Belum ada percakapan"
                    description="Percakapan yang Anda buat akan tersimpan di sini untuk dibaca kembali."
                />
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($threads as $item)
                        @php $isCurrent = $thread && $item->getKey() === $thread->getKey(); @endphp
                        <li class="py-3 first:pt-0 last:pb-0">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <a
                                        href="{{ route('assistant.threads.show', $item) }}"
                                        @if ($isCurrent) aria-current="page" @endif
                                        class="block truncate rounded text-sm font-medium {{ $isCurrent ? 'text-brand-800' : 'text-slate-900 hover:text-brand-700' }} focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                                    >{{ $item->title ?: 'Percakapan tanpa judul' }}</a>
                                    <p class="mt-1 text-xs text-slate-500">
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
                                            class="rounded-md border border-rose-600 px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2"
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
@endsection
