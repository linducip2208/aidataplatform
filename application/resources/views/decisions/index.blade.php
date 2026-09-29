@extends('layouts.app')

@section('title', 'Pusat keputusan')

@section('content')
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">Pusat keputusan</h1>
                <p class="page-subtitle">
                    Rekomendasi AI berbasis bukti, simulasi skenario, dan persetujuan manusia yang teraudit.
                </p>
            </div>
        </div>
    </div>

    @unless ($engineAvailable)
        <x-card class="mb-3">
            <div role="status" class="alert alert-warning">
                <x-badge variant="warning">Mesin AI tidak tersedia</x-badge>
                <p class="text-secondary">
                    Kasus keputusan belum dapat dimuat. Periksa layanan <code>fastapi</code>
                    lalu muat ulang halaman ini.
                </p>
            </div>
        </x-card>
    @endunless

    @if (is_array($scenarioResult) && $scenarioResult !== [])
        <x-card class="mb-3" title="Hasil skenario" description="Angka simulasi dari mesin, bukan rekomendasi final.">
            @if (! ($scenarioResult['supported'] ?? true))
                <x-empty-state
                    title="Skenario tidak didukung"
                    description="{{ collect((array) ($scenarioResult['reasons'] ?? []))->implode('; ') ?: 'Bentuk skenario ini tidak dapat dihitung dari data yang ada.' }}"
                />
            @else
                <x-table-wrapper label="Dampak skenario">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th scope="col">Ukuran</th>
                                <th scope="col" class="text-end">Nilai</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ((array) ($scenarioResult['deltas'] ?? []) as $key => $value)
                                <tr>
                                    <td class="fw-bold text-secondary">{{ $key }}</td>
                                    <td class="text-end">
                                        {{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-table-wrapper>
            @endif
        </x-card>
    @endif

    <div class="row row-cards">
        <div class="col-md-6">
            <x-card title="Minta rekomendasi" description="AI mengumpulkan bukti, menilai aturan, dan menyimpan kasus.">
                @if (auth()->user()->isAnalyst())
                    <form method="POST" action="{{ route('decisions.recommend') }}" class="row row-cards">
                        @csrf
                        <div class="col-md-6">
                            <x-field label="Cabang" for="subject_branch" hint="Kosongkan untuk seluruh cabang.">
                                <input id="subject_branch" name="branch" type="text" maxlength="128" value="{{ old('branch') }}" placeholder="mis. BR-01" class="form-control">
                            </x-field>
                        </div>
                        <div class="col-md-6">
                            <x-field label="Periode" for="subject_period">
                                <input id="subject_period" name="period" type="text" maxlength="32" value="{{ old('period') }}" placeholder="mis. 2026-09" class="form-control">
                            </x-field>
                        </div>
                        <div class="col-md-6">
                            <x-field label="Granularitas" for="subject_granularity">
                                <select id="subject_granularity" name="granularity" class="form-select">
                                    <option value="">Bawaan</option>
                                    @foreach (['daily' => 'Harian', 'weekly' => 'Mingguan', 'monthly' => 'Bulanan'] as $value => $label)
                                        <option value="{{ $value }}" @selected(old('granularity') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </x-field>
                        </div>
                        <div class="col-md-6">
                            <x-field label="Horizon (hari)" for="subject_horizon">
                                <input id="subject_horizon" name="horizon" type="number" min="1" max="365" value="{{ old('horizon') }}" class="form-control">
                            </x-field>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">Minta rekomendasi</button>
                        </div>
                    </form>
                @else
                    <p class="text-secondary">Peran Anda hanya dapat membaca kasus yang ada.</p>
                @endif
            </x-card>
        </div>

        <div class="col-md-6">
            <x-card title="Simulasi skenario" description="Hitung dampak numerik sebelum memutuskan.">
                @if (auth()->user()->isAnalyst())
                    <form method="POST" action="{{ route('decisions.scenarios.run') }}" class="row row-cards">
                        @csrf
                        <div class="col-md-6">
                            <x-field label="Jenis skenario" for="scenario_type" required>
                                <select id="scenario_type" name="type" required class="form-select">
                                    <option value="">Pilih jenis</option>
                                    <option value="price_change_pct" @selected(old('type') === 'price_change_pct')>Perubahan harga (%)</option>
                                    <option value="inventory_change_pct" @selected(old('type') === 'inventory_change_pct')>Perubahan inventaris (%)</option>
                                    <option value="churn_rise_pp" @selected(old('type') === 'churn_rise_pp')>Kenaikan churn (pp)</option>
                                </select>
                            </x-field>
                        </div>
                        <div class="col-md-6">
                            <x-field label="Nilai" for="scenario_value" required>
                                <input id="scenario_value" name="value" type="number" step="any" required value="{{ old('value') }}" placeholder="mis. -5" class="form-control">
                            </x-field>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">Jalankan skenario</button>
                        </div>
                    </form>
                @else
                    <p class="text-secondary">Peran Anda hanya dapat membaca kasus yang ada.</p>
                @endif
            </x-card>
        </div>
    </div>

    <x-card title="Riwayat kasus" description="Terbaru lebih dulu. Buka untuk rekomendasi, bukti, dan audit.">
        @if ($cases === [])
            <x-empty-state
                title="Belum ada kasus keputusan"
                description="Minta rekomendasi pertama melalui formulir di atas."
            />
        @else
            <x-table-wrapper label="Riwayat kasus keputusan">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col" class="text-end">ID</th>
                            <th scope="col">Subjek</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-end">Dibuat</th>
                            <th scope="col"><span class="visually-hidden">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cases as $case)
                            <tr>
                                <td class="text-end">{{ $case['id'] ?? '?' }}</td>
                                <td>
                                    <span class="fw-bold text-secondary">{{ $case['subject']['branch'] ?? 'Semua cabang' }}</span>
                                    <span class="d-block small text-secondary">{{ $case['subject']['period'] ?? '' }}</span>
                                </td>
                                <td><x-badge variant="info">{{ $case['status'] ?? '?' }}</x-badge></td>
                                <td class="text-end"><span class="small text-secondary">{{ $case['created_at'] ?? '?' }}</span></td>
                                <td class="text-end">
                                    <a href="{{ route('decisions.show', ['id' => (int) ($case['id'] ?? 0)]) }}" class="btn btn-sm">Buka</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>
        @endif
    </x-card>
@endsection
