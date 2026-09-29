@extends('layouts.app')

@section('title', 'Kualitas data')

@section('content')
    @php
        $typeLabels = config('ai_engine.dataset_type_labels', []);
    @endphp

    <div class="page-header">
        <h1 class="page-title">Kualitas data</h1>
        <p class="page-subtitle">
            Riwayat pemeriksaan kualitas dataset dan dataset yang belum dinilai.
        </p>
    </div>

    <div class="row row-cards">
        <div class="col-sm-6 col-lg-3">
            <x-stat
                :label="\App\Enums\QualityVerdict::Pass->localizedLabel()"
                :value="number_format((int) ($breakdown['pass'] ?? 0), 0, ',', '.')"
                hint="Dataset di atas ambang minimum"
            />
        </div>
        <div class="col-sm-6 col-lg-3">
            <x-stat
                :label="\App\Enums\QualityVerdict::Quarantine->localizedLabel()"
                :value="number_format((int) ($breakdown['quarantine'] ?? 0), 0, ',', '.')"
                hint="Dataset di bawah ambang minimum"
            />
        </div>
        <div class="col-sm-6 col-lg-3">
            <x-stat label="Belum dinilai" :value="number_format((int) ($breakdown['unscored'] ?? 0), 0, ',', '.')" hint="Dataset tanpa laporan kualitas" />
        </div>
        <div class="col-sm-6 col-lg-3">
            <x-stat
                label="Ambang minimum"
                :value="\Illuminate\Support\Number::percentage($threshold * 100, precision: 1, locale: 'id')"
                hint="Dataset dengan skor di bawah nilai ini dikarantina"
            />
        </div>
    </div>

    <x-card title="Filter" description="Saring berdasarkan tipe dataset atau vonis kualitas.">
        <form method="GET" action="{{ route('quality.index') }}" class="row row-cards">
            <div class="col-md-6">
                <x-field label="Tipe dataset" for="dataset_type">
                    <select
                        id="dataset_type"
                        name="dataset_type"
                        class="form-select"
                    >
                        <option value="">Semua tipe</option>
                        @foreach (array_keys($typeLabels) as $type)
                            <option value="{{ $type }}" @selected(($filters['dataset_type'] ?? '') === $type)>{{ $typeLabels[$type] }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>

            <div class="col-md-6">
                <x-field label="Vonis kualitas" for="verdict">
                    <select
                        id="verdict"
                        name="verdict"
                        class="form-select"
                    >
                        <option value="">Semua vonis</option>
                        @foreach ($verdicts as $verdict)
                            <option value="{{ $verdict->value }}" @selected(($filters['verdict'] ?? '') === $verdict->value)>
                                {{ $verdict->localizedLabel() }}
                            </option>
                        @endforeach
                    </select>
                </x-field>
            </div>

            <div class="col-md-6">
                <button
                    type="submit"
                    class="btn btn-primary"
                >Terapkan</button>
                <a
                    href="{{ route('quality.index') }}"
                    class="btn"
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
                        class="btn"
                    >Lihat dataset</a>
                </x-slot:action>
            </x-empty-state>
        @else
            <x-table-wrapper label="Hasil pemeriksaan kualitas">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">Dataset</th>
                            <th scope="col">Tipe</th>
                            <th scope="col" class="text-end">Skor</th>
                            <th scope="col">Vonis</th>
                            <th scope="col">Status alur</th>
                            <th scope="col" class="text-end">Diperiksa</th>
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
                                        class="fw-bold text-secondary"
                                    >{{ $dataset->name }}</a>
                                    <span class="text-secondary">{{ $dataset->source_filename ?: 'Tanpa nama berkas' }}</span>
                                </td>
                                <td>{{ $typeLabels[$dataset->dataset_type] ?? $dataset->dataset_type }}</td>
                                <td class="text-end">
                                    <span class="fw-bold text-secondary">
                                        {{ \Illuminate\Support\Number::percentage((float) $dataset->quality_score * 100, precision: 1, locale: 'id') }}
                                    </span>
                                    <span class="text-secondary">
                                        {{ $meetsThreshold ? 'Di atas ambang' : 'Di bawah ambang' }}
                                    </span>
                                </td>
                                <td>
                                    <x-badge :class="$verdict ? $verdict->badgeClass() : 'badge-neutral'">
                                        {{ $verdict ? $verdict->localizedLabel() : 'Tidak diketahui' }}
                                    </x-badge>
                                </td>
                                <td><x-badge :class="$status->badgeClass()">{{ $status->localizedLabel() }}</x-badge></td>
                                <td class="text-end">
                                    @if ($dataset->quality_checked_at)
                                        <span class="text-secondary">{{ $dataset->quality_checked_at->locale('id')->translatedFormat('d M Y H:i') }}</span>
                                    @else
                                        <span class="text-secondary">Tidak diketahui</span>
                                    @endif
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
