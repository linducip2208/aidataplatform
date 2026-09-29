@extends('layouts.app')

@section('title', 'Pusat peringatan')

@section('content')
    @php
        $severityVariants = ['critical' => 'danger', 'high' => 'warning', 'medium' => 'info', 'low' => 'neutral'];
        $severityLabels = ['critical' => 'Kritis', 'high' => 'Tinggi', 'medium' => 'Sedang', 'low' => 'Rendah'];
        $statusVariants = ['open' => 'info', 'acknowledged' => 'warning', 'resolved' => 'success'];
        $statusLabels = ['open' => 'Terbuka', 'acknowledged' => 'Sudah dibaca', 'resolved' => 'Selesai'];
        $canWrite = auth()->user()->isAnalyst();
        $metricNames = collect($metrics)->map(fn ($metric): string => is_array($metric) ? (string) ($metric['name'] ?? '') : (string) $metric)->filter()->unique()->sort()->values();
    @endphp

    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">Pusat peringatan</h1>
                <p class="page-subtitle">
                    Peringatan ambang KPI dari mesin AI (dievaluasi tiap menit) beserta aturan yang memicunya.
                </p>
            </div>
        </div>
    </div>

    @unless ($engineAvailable)
        <x-card class="mb-3">
            <div role="status" class="alert alert-warning">
                <x-badge variant="warning">Mesin AI tidak tersedia</x-badge>
                <p class="text-secondary">
                    Daftar peringatan belum dapat dimuat. Periksa layanan <code>fastapi</code>
                    lalu muat ulang halaman ini.
                </p>
            </div>
        </x-card>
    @endunless

    <x-card title="Filter" description="Saring peringatan berdasarkan status atau aturan.">
        <form method="GET" action="{{ route('alerts.index') }}" class="row row-cards">
            <div class="col-md-6">
                <x-field label="Status" for="status">
                    <select id="status" name="status" class="form-select">
                        <option value="">Semua status</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>

            <div class="col-md-6">
                <x-field label="ID aturan" for="rule_id" hint="Kosongkan untuk seluruh aturan.">
                    <input
                        id="rule_id"
                        name="rule_id"
                        type="number"
                        min="1"
                        value="{{ $filters['rule_id'] ?? '' }}"
                        placeholder="mis. 3"
                        @error('rule_id') aria-invalid="true" @enderror
                        class="form-control"
                    >
                </x-field>
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary">Terapkan</button>
                <a href="{{ route('alerts.index') }}" class="btn">Atur ulang</a>
            </div>
        </form>
    </x-card>

    <x-card title="Daftar peringatan" description="Terbaru lebih dulu. Mengakui bukan menyelesaikan.">
        @if ($alerts === [])
            <x-empty-state
                title="Belum ada peringatan"
                description="Tidak ada peringatan untuk filter ini. Aturan yang aktif dievaluasi mesin AI setiap menit."
            />
        @else
            <x-table-wrapper label="Daftar peringatan">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">Peringatan</th>
                            <th scope="col">Tingkat</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-end">Dipicu</th>
                            <th scope="col"><span class="visually-hidden">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($alerts as $alert)
                            @php
                                $severity = strtolower((string) ($alert['severity'] ?? 'low'));
                                $status = strtolower((string) ($alert['status'] ?? 'open'));
                            @endphp
                            <tr>
                                <td>
                                    <div class="fw-bold text-secondary">{{ $alert['message'] ?? 'Peringatan #'.($alert['id'] ?? '?') }}</div>
                                    <div class="small text-secondary">Aturan #{{ $alert['rule_id'] ?? '?' }}</div>
                                </td>
                                <td><x-badge variant="{{ $severityVariants[$severity] ?? 'neutral' }}">{{ $severityLabels[$severity] ?? $severity }}</x-badge></td>
                                <td><x-badge variant="{{ $statusVariants[$status] ?? 'neutral' }}">{{ $statusLabels[$status] ?? $status }}</x-badge></td>
                                <td class="text-end">
                                    <span class="small text-secondary">{{ $alert['triggered_at'] ?? 'Tidak diketahui' }}</span>
                                </td>
                                <td class="text-end">
                                    @if ($canWrite && $status !== 'resolved' && isset($alert['id']))
                                        <form method="POST" action="{{ route('alerts.ack', ['id' => (int) $alert['id']]) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm">Tandai dibaca</button>
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

    <x-card title="Aturan peringatan" description="Ambang yang dievaluasi terhadap metrik setiap menit.">
        @if ($rules === [])
            <x-empty-state
                title="Belum ada aturan"
                description="Buat aturan pertama melalui formulir di bawah."
            />
        @else
            <x-table-wrapper label="Aturan peringatan">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">Aturan</th>
                            <th scope="col">Metrik</th>
                            <th scope="col" class="text-end">Ambang</th>
                            <th scope="col">Aktif</th>
                            @if ($canWrite)
                                <th scope="col"><span class="visually-hidden">Aksi</span></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rules as $rule)
                            <tr>
                                <td>
                                    <div class="fw-bold text-secondary">{{ $rule['name'] ?? 'Aturan #'.($rule['id'] ?? '?') }}</div>
                                    <div class="small text-secondary">ID {{ $rule['id'] ?? '?' }}</div>
                                </td>
                                <td><code>{{ $rule['metric'] ?? '?' }} {{ $rule['operator'] ?? '' }}</code></td>
                                <td class="text-end">{{ $rule['threshold'] ?? '?' }}</td>
                                <td>
                                    <x-badge variant="{{ ! empty($rule['is_active']) ? 'success' : 'neutral' }}">
                                        {{ ! empty($rule['is_active']) ? 'Aktif' : 'Nonaktif' }}
                                    </x-badge>
                                </td>
                                @if ($canWrite && isset($rule['id']))
                                    <td class="text-end">
                                        <form method="POST" action="{{ route('alerts.rules.toggle', ['id' => (int) $rule['id']]) }}">
                                            @csrf
                                            <input type="hidden" name="is_active" value="{{ empty($rule['is_active']) ? '1' : '0' }}">
                                            <button type="submit" class="btn btn-sm">{{ empty($rule['is_active']) ? 'Aktifkan' : 'Nonaktifkan' }}</button>
                                        </form>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>
        @endif
    </x-card>

    @if ($canWrite)
        <x-card title="Buat aturan" description="Metrik yang tidak dikenal ditolak mesin dengan 400.">
            <form method="POST" action="{{ route('alerts.rules.store') }}" class="row row-cards">
                @csrf

                <div class="col-md-6">
                    <x-field label="Nama aturan" for="name" name="name" required>
                        <input
                            id="name"
                            name="name"
                            type="text"
                            value="{{ old('name') }}"
                            required
                            maxlength="128"
                            placeholder="mis. Pendapatan turun"
                            @error('name') aria-invalid="true" @enderror
                            class="form-control"
                        >
                    </x-field>
                </div>

                <div class="col-md-6">
                    <x-field label="Metrik" for="metric" name="metric" required>
                        <select id="metric" name="metric" required @error('metric') aria-invalid="true" @enderror class="form-select">
                            <option value="">Pilih metrik</option>
                            @foreach ($metricNames as $metric)
                                <option value="{{ $metric }}" @selected(old('metric') === $metric)>{{ $metric }}</option>
                            @endforeach
                        </select>
                    </x-field>
                </div>

                <div class="col-md-6">
                    <x-field label="Operator" for="operator" name="operator" required hint="Contoh: &gt;, &gt;=, &lt;, &lt;=, ==, !=">
                        <input
                            id="operator"
                            name="operator"
                            type="text"
                            value="{{ old('operator') }}"
                            required
                            maxlength="16"
                            placeholder="mis. <"
                            @error('operator') aria-invalid="true" @enderror
                            class="form-control"
                        >
                    </x-field>
                </div>

                <div class="col-md-6">
                    <x-field label="Ambang" for="threshold" name="threshold" required>
                        <input
                            id="threshold"
                            name="threshold"
                            type="number"
                            step="any"
                            value="{{ old('threshold') }}"
                            required
                            @error('threshold') aria-invalid="true" @enderror
                            class="form-control"
                        >
                    </x-field>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary">Buat aturan</button>
                </div>
            </form>
        </x-card>
    @endif
@endsection
