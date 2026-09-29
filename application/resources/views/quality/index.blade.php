@extends('layouts.app')

@section('title', 'Kualitas data')

@section('content')
    @php
        $typeLabels = config('ai_engine.dataset_type_labels', []);
    @endphp

    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-slate-900">Kualitas data</h1>
        <p class="mt-1 text-sm text-slate-500">
            Riwayat pemeriksaan kualitas dataset dan dataset yang belum dinilai.
        </p>
    </div>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat
            :label="\App\Enums\QualityVerdict::Pass->localizedLabel()"
            :value="number_format((int) ($breakdown['pass'] ?? 0), 0, ',', '.')"
            hint="Dataset di atas ambang minimum"
        />
        <x-stat
            :label="\App\Enums\QualityVerdict::Quarantine->localizedLabel()"
            :value="number_format((int) ($breakdown['quarantine'] ?? 0), 0, ',', '.')"
            hint="Dataset di bawah ambang minimum"
        />
        <x-stat label="Belum dinilai" :value="number_format((int) ($breakdown['unscored'] ?? 0), 0, ',', '.')" hint="Dataset tanpa laporan kualitas" />
        <x-stat
            label="Ambang minimum"
            :value="\Illuminate\Support\Number::percentage($threshold * 100, precision: 1, locale: 'id')"
            hint="Dataset dengan skor di bawah nilai ini dikarantina"
        />
    </div>

    <x-card class="mb-6" title="Filter" description="Saring berdasarkan tipe dataset atau vonis kualitas.">
        <form method="GET" action="{{ route('quality.index') }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-field label="Tipe dataset" for="dataset_type">
                <select
                    id="dataset_type"
                    name="dataset_type"
                    class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                >
                    <option value="">Semua tipe</option>
                    @foreach (array_keys($typeLabels) as $type)
                        <option value="{{ $type }}" @selected(($filters['dataset_type'] ?? '') === $type)>{{ $typeLabels[$type] }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field label="Vonis kualitas" for="verdict">
                <select
                    id="verdict"
                    name="verdict"
                    class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                >
                    <option value="">Semua vonis</option>
                    @foreach ($verdicts as $verdict)
                        <option value="{{ $verdict->value }}" @selected(($filters['verdict'] ?? '') === $verdict->value)>
                            {{ $verdict->localizedLabel() }}
                        </option>
                    @endforeach
                </select>
            </x-field>

            <div class="flex items-end gap-2 sm:col-span-2">
                <button
                    type="submit"
                    class="inline-flex flex-1 items-center justify-center rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                >Terapkan</button>
                <a
                    href="{{ route('quality.index') }}"
                    class="inline-flex items-center justify-center rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                >Atur ulang</a>
            </div>
        </form>
    </x-card>

    <x-card title="Hasil pemeriksaan" description="{{ number_format($datasets->total(), 0, ',', '.') }} dataset punya laporan kualitas.">
        @if ($datasets->isEmpty())
            <x-empty-state
                title="Belum ada laporan kualitas"
                description="Jalankan pemeriksaan kualitas pada halaman dataset untuk melihat hasilnya di sini."
            >
                <x-slot:action>
                    <a
                        href="{{ route('datasets.index') }}"
                        class="inline-flex rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                    >Lihat dataset</a>
                </x-slot:action>
            </x-empty-state>
        @else
            <x-table-wrapper label="Hasil pemeriksaan kualitas">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th scope="col">Dataset</th>
                            <th scope="col">Tipe</th>
                            <th scope="col" class="text-right">Skor</th>
                            <th scope="col">Vonis</th>
                            <th scope="col">Status alur</th>
                            <th scope="col" class="text-right">Diperiksa</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($datasets as $dataset)
                            @php
                                $verdict = $dataset->qualityVerdict();
                                $status = $dataset->status();
                                $meetsThreshold = $dataset->quality_score !== null && $dataset->quality_score >= $threshold;
                            @endphp
                            <tr>
                                <td>
                                    <a
                                        href="{{ route('datasets.show', $dataset) }}"
                                        class="rounded font-medium text-slate-900 hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                                    >{{ $dataset->name }}</a>
                                    <span class="mt-1 block text-xs text-slate-500">{{ $dataset->source_filename ?: 'Tanpa nama berkas' }}</span>
                                </td>
                                <td>{{ $typeLabels[$dataset->dataset_type] ?? $dataset->dataset_type }}</td>
                                <td class="text-right">
                                    <span class="tabular-nums font-medium text-slate-900">
                                        {{ \Illuminate\Support\Number::percentage((float) $dataset->quality_score * 100, precision: 1, locale: 'id') }}
                                    </span>
                                    <span class="mt-1 block text-xs {{ $meetsThreshold ? 'text-emerald-700' : 'text-rose-700' }}">
                                        {{ $meetsThreshold ? 'Di atas ambang' : 'Di bawah ambang' }}
                                    </span>
                                </td>
                                <td>
                                    <x-badge :class="$verdict ? $verdict->badgeClass() : 'badge-neutral'">
                                        {{ $verdict ? $verdict->localizedLabel() : 'Tidak diketahui' }}
                                    </x-badge>
                                </td>
                                <td><x-badge :class="$status->badgeClass()">{{ $status->localizedLabel() }}</x-badge></td>
                                <td class="text-right">
                                    @if ($dataset->quality_checked_at)
                                        <span class="text-xs text-slate-500">{{ $dataset->quality_checked_at->locale('id')->translatedFormat('d M Y H:i') }}</span>
                                    @else
                                        <span class="text-xs text-slate-400">Tidak diketahui</span>
                                    @endif
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
