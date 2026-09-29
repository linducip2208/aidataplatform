@extends('layouts.app')

@section('title', 'Biaya AI')

@section('content')
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">Biaya AI</h1>
                <p class="page-subtitle">
                    Token dan estimasi biaya dari buku besar pemakaian AI. Estimasi berlabel, baris tanpa tarif dihitung terpisah.
                </p>
            </div>
        </div>
    </div>

    @unless ($engineAvailable)
        <x-card class="mb-3">
            <div role="status" class="alert alert-warning">
                <x-badge variant="warning">Mesin AI tidak tersedia</x-badge>
                <p class="text-secondary">
                    Ringkasan biaya belum dapat dimuat. Periksa layanan <code>fastapi</code>
                    lalu muat ulang halaman ini.
                </p>
            </div>
        </x-card>
    @endunless

    <x-card class="mb-3" title="Rentang" description="Pilih jendela agregasi 1–365 hari.">
        <form method="GET" action="{{ route('ai.usage') }}" class="row row-cards">
            <div class="col-md-6">
                <x-field label="Hari terakhir" for="days">
                    <select id="days" name="days" class="form-select">
                        @foreach ([7, 30, 90, 365] as $option)
                            <option value="{{ $option }}" @selected($days === $option)>{{ $option }} hari</option>
                        @endforeach
                    </select>
                </x-field>
            </div>
            <div class="col-md-6 d-flex align-items-end">
                <button type="submit" class="btn btn-primary">Terapkan</button>
            </div>
        </form>
    </x-card>

    <div class="row row-cards mb-3">
        <div class="col-sm-6 col-lg-3">
            <x-stat label="Total percakapan" :value="number_format((int) ($totals['turns'] ?? 0), 0, ',', '.')" :hint="$days.' hari terakhir'" />
        </div>
        <div class="col-sm-6 col-lg-3">
            <x-stat label="Total token" :value="number_format((int) ($totals['total_tokens'] ?? 0), 0, ',', '.')" hint="Prompt + completion" />
        </div>
        <div class="col-sm-6 col-lg-3">
            <x-stat
                label="Estimasi biaya (USD)"
                :value="number_format((float) ($totals['estimated_cost_total'] ?? 0), 4, ',', '.')"
                :hint="(int) ($totals['unpriced_rows'] ?? 0).' baris tanpa tarif'"
            />
        </div>
        <div class="col-sm-6 col-lg-3">
            <x-stat label="Baris tanpa tarif" :value="number_format((int) ($totals['unpriced_rows'] ?? 0), 0, ',', '.')" hint="Model tak dikenal, tak ikut total" />
        </div>
    </div>

    <x-card title="Per model" description="Diurutkan dari token terbanyak.">
        @if ($byModel === [])
            <x-empty-state
                title="Belum ada pemakaian"
                description="Ajak asisten bicara supaya buku besar mencatat pemakaian model."
            />
        @else
            <x-table-wrapper label="Biaya per model">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">Model</th>
                            <th scope="col" class="text-end">Percakapan</th>
                            <th scope="col" class="text-end">Token</th>
                            <th scope="col" class="text-end">Estimasi (USD)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($byModel as $row)
                            <tr>
                                <td>
                                    <div class="fw-bold text-secondary">{{ $row['model'] !== '' ? $row['model'] : 'Tidak diketahui' }}</div>
                                    <div class="small text-secondary">{{ $row['provider'] !== '' ? $row['provider'] : 'tanpa provider' }}</div>
                                </td>
                                <td class="text-end">{{ number_format((int) ($row['turns'] ?? 0), 0, ',', '.') }}</td>
                                <td class="text-end">{{ number_format((int) ($row['total_tokens'] ?? 0), 0, ',', '.') }}</td>
                                <td class="text-end">{{ number_format((float) ($row['estimated_cost_total'] ?? 0), 4, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>
        @endif
    </x-card>

    <x-card title="Per hari" description="Urutan tanggal menaik.">
        @if ($byDay === [])
            <x-empty-state
                title="Belum ada data harian"
                description="Belum ada pemakaian tercatat pada rentang ini."
            />
        @else
            <x-table-wrapper label="Biaya per hari">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">Tanggal</th>
                            <th scope="col" class="text-end">Percakapan</th>
                            <th scope="col" class="text-end">Token</th>
                            <th scope="col" class="text-end">Estimasi (USD)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($byDay as $row)
                            <tr>
                                <td class="fw-bold text-secondary">{{ $row['day'] ?? '?' }}</td>
                                <td class="text-end">{{ number_format((int) ($row['turns'] ?? 0), 0, ',', '.') }}</td>
                                <td class="text-end">{{ number_format((int) ($row['total_tokens'] ?? 0), 0, ',', '.') }}</td>
                                <td class="text-end">{{ number_format((float) ($row['estimated_cost_total'] ?? 0), 4, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>
        @endif
    </x-card>
@endsection
