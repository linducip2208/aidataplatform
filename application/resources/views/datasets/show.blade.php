@extends('layouts.app')

@section('title', $dataset->name)

@section('content')
    @php
        $canWrite = auth()->user()->isAnalyst();
        $status = $dataset->status();

        $hasJob = (bool) $dataset->import_job_id;
        $columnProfiles = (array) $dataset->columns;
        $columns = $dataset->columnNames();
        $hasProfile = $columns !== [];
        $mappings = (array) $dataset->mappings;
        $hasMappings = $mappings !== [];

        // Session old input is stored flat as `mappings[kolom]`, so dot notation
        // never resolves it. Index the array once instead.
        $oldMappings = (array) old('mappings', []);

        $score = isset($quality['score']) ? (float) $quality['score'] : null;
        $breakdown = (array) ($quality['breakdown'] ?? []);
        $issues = (array) ($quality['issues'] ?? []);
        $passed = (bool) ($quality['passed'] ?? false);
        $isCommitted = $status->value === 'committed' || $dataset->committed_at !== null;

        // The union of every sample row's keys, so a ragged payload cannot shift
        // the value of a column under its own header.
        $sampleColumns = [];
        foreach ($sampleRows as $row) {
            foreach (array_keys((array) $row) as $key) {
                $sampleColumns[$key] = true;
            }
        }
        $sampleColumns = array_keys($sampleColumns);

        $breakdownLabels = [
            'completeness' => 'Kelengkapan',
            'uniqueness' => 'Keunikan',
            'validity' => 'Validitas',
            'consistency' => 'Konsistensi',
        ];

        // Mirrors CANONICAL_FIELDS in ai-engine/app/ingestion/mapper.py. A new
        // canonical field must be added on both sides or the grid will offer a
        // target the ETL never produces.
        $canonicalFields = [
            'sales' => ['transaction_date', 'customer_code', 'customer_name', 'product_code', 'product_name', 'branch_name', 'quantity', 'selling_price', 'discount', 'revenue'],
            'inventory' => ['snapshot_date', 'product_code', 'product_name', 'warehouse_name', 'stock_qty'],
            'purchases' => ['purchase_date', 'supplier_code', 'supplier_name', 'product_code', 'quantity', 'cost'],
            'expenses' => ['expense_date', 'department_name', 'category', 'amount'],
            'customers' => ['customer_code', 'customer_name', 'segment', 'city'],
            'products' => ['product_code', 'product_name', 'category', 'unit', 'cost_price', 'selling_price'],
        ];
        $targets = $canonicalFields[$dataset->dataset_type] ?? $canonicalFields['sales'];

        $steps = [
            ['label' => 'Pratinjau', 'done' => $hasProfile, 'ready' => $hasJob],
            ['label' => 'Pemetaan kolom', 'done' => $hasMappings, 'ready' => $hasProfile],
            ['label' => 'Kualitas', 'done' => $score !== null, 'ready' => $hasJob && $hasMappings],
            ['label' => 'Komit', 'done' => $isCommitted, 'ready' => $hasJob && $passed && ! $isCommitted],
        ];

        $typeLabels = config('ai_engine.dataset_type_labels', []);
    @endphp

    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <nav aria-label="Remah roti">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item">
                            <a
                                href="{{ route('datasets.index') }}"
                            >Kumpulan data</a>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ $dataset->name }}</li>
                    </ol>
                </nav>

                <h1 class="page-title">{{ $dataset->name }}</h1>
                <div class="page-subtitle d-flex flex-wrap align-items-center gap-2">
                    <x-badge :class="$status->badgeClass()">{{ $status->localizedLabel() }}</x-badge>
                    <span>{{ $dataset->source_filename ?: 'Tanpa nama berkas' }}</span>
                    <span aria-hidden="true">&middot;</span>
                    <span>{{ $dataset->sizeForHumans() }}</span>
                </div>
            </div>

            <div class="col-auto ms-auto">
                <div class="d-flex flex-wrap gap-2">
                    <a
                        href="{{ route('datasets.index') }}"
                        class="btn"
                    >Kembali ke daftar</a>

                    @if ($hasJob)
                        <a
                            href="{{ route('imports.show', $dataset) }}"
                            class="btn"
                        >Lihat job impor</a>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <ol class="row row-cards" aria-label="Tahapan pemrosesan dataset">
        @foreach ($steps as $index => $step)
            <li class="col-sm-6 col-lg-3">
                <div class="card card-body">
                    <p class="text-secondary small fw-bold text-uppercase">Tahap {{ $index + 1 }}</p>
                    <p class="fw-bold">{{ $step['label'] }}</p>
                    <p>
                        @if ($step['done'])
                            <x-badge variant="success">Selesai</x-badge>
                        @elseif ($step['ready'])
                            <x-badge variant="info">Siap dijalankan</x-badge>
                        @else
                            <x-badge variant="neutral">Menunggu langkah sebelumnya</x-badge>
                        @endif
                    </p>
                </div>
            </li>
        @endforeach
    </ol>

    @unless ($canWrite)
        <x-card class="mb-3" title="Akses baca saja">
            <p class="text-secondary">
                Peran <strong>{{ auth()->user()->role()->localizedLabel() }}</strong> hanya dapat melihat dataset.
                Menjalankan pratinjau, pemetaan, kualitas, dan komit terbatas untuk
                {{ \App\Enums\UserRole::Admin->localizedLabel() }} dan {{ \App\Enums\UserRole::Analyst->localizedLabel() }}.
            </p>
        </x-card>
    @endunless

    <div class="row row-cards">
        <div class="col-lg-8">
            <x-card
                title="Tahap 1: Pratinjau data"
                description="Membaca berkas dan memprofil jumlah baris, kolom, serta contoh data."
            >
                @if (! $hasJob)
                    <p class="text-secondary">
                        Dataset ini belum memiliki job import, jadi mesin AI tidak bisa memprofilnya. Unggah ulang berkasnya untuk memulai dari awal.
                    </p>
                @endif

                <dl class="datagrid mb-3">
                    <div class="datagrid-item">
                        <dt class="datagrid-title">Jumlah baris</dt>
                        <dd class="datagrid-content tabular-nums">{{ number_format((int) $dataset->row_count, 0, ',', '.') }}</dd>
                    </div>
                    <div class="datagrid-item">
                        <dt class="datagrid-title">Jumlah kolom</dt>
                        <dd class="datagrid-content tabular-nums">{{ number_format((int) $dataset->column_count, 0, ',', '.') }}</dd>
                    </div>
                    <div class="datagrid-item">
                        <dt class="datagrid-title">Job impor</dt>
                        <dd class="datagrid-content tabular-nums">{{ $dataset->import_job_id ? number_format((int) $dataset->import_job_id, 0, ',', '.') : 'Belum ada' }}</dd>
                    </div>
                    <div class="datagrid-item">
                        <dt class="datagrid-title">Diunggah</dt>
                        <dd class="datagrid-content">
                            {{ $dataset->created_at ? $dataset->created_at->locale('id')->translatedFormat('d M Y H:i') : 'Tidak diketahui' }}
                        </dd>
                    </div>
                </dl>

                @if ($canWrite)
                    <form method="POST" action="{{ route('datasets.preview', $dataset) }}">
                        @csrf
                        <button
                            type="submit"
                            @disabled(! $hasJob)
                            class="btn btn-primary"
                        >{{ $hasProfile ? 'Jalankan pratinjau ulang' : 'Jalankan pratinjau' }}</button>

                        @unless ($hasJob)
                            <p class="text-secondary small">Tombol dinonaktifkan karena dataset belum memiliki job import.</p>
                        @endunless
                    </form>
                @endif
            </x-card>

            <x-card
                title="Tahap 2: Pemetaan kolom"
                description="Setiap kolom sumber dipetakan ke kolom kanonik gudang analytics. Minimal satu kolom wajib dipetakan."
            >
                @if (! $hasProfile)
                    <x-empty-state
                        title="Profil kolom belum tersedia"
                        description="Jalankan pratinjau pada tahap 1 supaya mesin AI dapat membaca nama kolom berkas."
                    />
                @else
                    @if ($canWrite)
                        <form method="POST" action="{{ route('datasets.mapping', $dataset) }}">
                            @csrf

                            <x-table-wrapper label="Pemetaan kolom" class="mb-3">
                                <table class="table table-vcenter card-table">
                                    <thead>
                                        <tr>
                                            <th scope="col">Kolom sumber</th>
                                            <th scope="col">Kolom kanonik</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($columns as $column)
                                            @php
                                                $inputName = 'mappings['.$column.']';
                                                $selected = $mappings[$column] ?? $oldMappings[$column] ?? null;

                                                // A mapping saved before the canonical list changed
                                                // would be wiped by a plain re-submit, so keep
                                                // the stored target selectable.
                                                $options = in_array((string) $selected, $targets, true) || $selected === null
                                                    ? $targets
                                                    : array_merge([(string) $selected], $targets);
                                            @endphp
                                            <tr>
                                                <td>
                                                    <span>{{ $column }}</span>
                                                    @php $profile = $columnProfiles[$loop->index] ?? null; @endphp
                                                    @if (is_array($profile))
                                                        <span class="d-block text-secondary small">
                                                            @if (! empty($profile['dtype']))
                                                                <span class="text-uppercase">{{ $profile['dtype'] }}</span>
                                                                &middot;
                                                            @endif
                                            kosong {{ number_format((int) ($profile['missing'] ?? 0), 0, ',', '.') }}
                                            &middot;
                                            {{ number_format((int) ($profile['unique'] ?? 0), 0, ',', '.') }} nilai unik
                                                        </span>
                                                    @endif
                                                </td>
                                                <td>
                                                    <label for="mapping-{{ $loop->index }}" class="visually-hidden">Target untuk kolom {{ $column }}</label>
                                                    <select
                                                        id="mapping-{{ $loop->index }}"
                                                        name="{{ $inputName }}"
                                                        class="form-select"
                                                    >
                                                        <option value="">Tidak dipetakan</option>
                                                        @foreach ($options as $target)
                                                            <option value="{{ $target }}" @selected($selected === $target)>{{ $target }}</option>
                                                        @endforeach
                                                    </select>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </x-table-wrapper>

                            @error('mappings')
                                <p class="text-danger small fw-bold">{{ $message }}</p>
                            @enderror

                            <x-field
                                label="Simpan sebagai template"
                                for="save_as_template"
                                name="save_as_template"
                                hint="Opsional. Nama template untuk dipakai ulang pada dataset berikutnya."
                                class="mb-3"
                            >
                                <input
                                    id="save_as_template"
                                    name="save_as_template"
                                    type="text"
                                    value="{{ old('save_as_template') }}"
                                    maxlength="120"
                                    placeholder="mis. template_penjualan_retail"
                                    @error('save_as_template') aria-invalid="true" @enderror
                                    class="form-control"
                                >
                            </x-field>

                            <button
                                type="submit"
                                class="btn btn-primary"
                            >Simpan pemetaan</button>
                        </form>
                    @else
                        <dl class="datagrid">
                            @foreach ($mappings as $source => $target)
                                <div class="datagrid-item">
                                    <dt class="datagrid-title">{{ $source }}</dt>
                                    <dd class="datagrid-content">{{ $target }}</dd>
                                </div>
                            @endforeach

                            @if ($mappings === [])
                                <x-empty-state title="Belum ada pemetaan" description="Pemetaan kolom belum disimpan untuk dataset ini." />
                            @endif
                        </dl>
                    @endif
                @endif
            </x-card>

            <x-card
                title="Tahap 3: Pemeriksaan kualitas"
                description="Menghitung kelengkapan, keunikan, validitas, dan konsistensi data sebelum masuk gudang."
            >
                @if ($score === null)
                    <p class="text-secondary">
                        Belum ada laporan kualitas. Jalankan pemeriksaan setelah pemetaan kolom tersimpan.
                    </p>
                @else
                    <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
                        <p class="tabular-nums">
                            {{ \Illuminate\Support\Number::percentage($score * 100, precision: 1, locale: 'id') }}
                        </p>
                        <x-badge variant="{{ $passed ? 'success' : 'danger' }}">{{ $passed ? \App\Enums\QualityVerdict::Pass->localizedLabel() : 'Belum lolos' }}</x-badge>
                        <span class="text-secondary">Ambang minimum {{ \Illuminate\Support\Number::percentage($threshold * 100, precision: 1, locale: 'id') }}</span>
                    </div>

                    <dl class="mb-3">
                        @foreach ($breakdownLabels as $key => $label)
                            @php
                                $value = (float) ($breakdown[$key] ?? 0);
                                $percent = max(0.0, min(1.0, $value)) * 100;
                                $barClass = $value >= $threshold ? 'bg-success' : 'bg-warning';
                            @endphp
                            <div class="datagrid-item">
                                <div class="d-flex align-items-center justify-content-between gap-2">
                                    <dt class="datagrid-title">{{ $label }}</dt>
                                    <dd class="datagrid-content tabular-nums">{{ \Illuminate\Support\Number::percentage($value * 100, precision: 1, locale: 'id') }}</dd>
                                </div>
                                <div class="progress">
                                    <div class="progress-bar {{ $barClass }}" role="progressbar" style="width: {{ number_format($percent, 1, '.', '') }}%" aria-valuenow="{{ number_format($percent, 1, '.', '') }}" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                            </div>
                        @endforeach
                    </dl>
                @endif

                @if ($issues !== [])
                    <x-table-wrapper label="Temuan pemeriksaan kualitas" class="mb-3">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th scope="col">Aturan</th>
                                    <th scope="col">Kolom</th>
                                    <th scope="col" class="text-end">Jumlah</th>
                                    <th scope="col">Keterangan</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($issues as $issue)
                                    <tr>
                                        <td><code>{{ $issue['rule'] ?? 'tidak diketahui' }}</code></td>
                                        <td>{{ $issue['column'] ?? 'seluruh tabel' }}</td>
                                        <td class="text-end tabular-nums">{{ number_format((int) ($issue['count'] ?? 0), 0, ',', '.') }}</td>
                                        <td class="text-secondary">{{ $issue['message'] ?? 'Tanpa keterangan' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-table-wrapper>
                @endif

                @if ($canWrite)
                    <form method="POST" action="{{ route('datasets.quality', $dataset) }}">
                        @csrf
                        <button
                            type="submit"
                            @disabled(! $hasJob || ! $hasMappings)
                            class="btn btn-primary"
                        >{{ $score === null ? 'Jalankan pemeriksaan kualitas' : 'Jalankan ulang pemeriksaan' }}</button>

                        @unless ($hasJob && $hasMappings)
                            <p class="text-secondary small">
                                Tombol dinonaktifkan karena
                                @if (! $hasJob)
                                    job impor belum tersedia.
                                @else
                                    pemetaan kolom belum disimpan.
                                @endif
                            </p>
                        @endunless
                    </form>
                @endif
            </x-card>

            <x-card
                title="Tahap 4: Komit ke gudang data"
                description="Baris yang lolos ambang kualitas dikirim ke gudang data untuk dianalisis."
            >
                @if ($isCommitted)
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                        <x-badge :class="$status->badgeClass()">{{ $status->localizedLabel() }}</x-badge>
                        <p class="text-secondary">
                            Komit terakhir
                            @if ($dataset->committed_at)
                                pada {{ $dataset->committed_at->locale('id')->translatedFormat('d M Y H:i') }}.
                            @else
                                .
                            @endif
                        </p>
                    </div>
                @elseif (! $passed)
                    <p class="text-secondary">
                        Komit baru bisa dijalankan setelah skor kualitas mencapai ambang minimum
                        {{ \Illuminate\Support\Number::percentage($threshold * 100, precision: 1, locale: 'id') }}.
                    </p>
                @endif

                @if ($canWrite)
                    <form method="POST" action="{{ route('datasets.commit', $dataset) }}">
                        @csrf

                        <div class="d-flex align-items-start gap-2 mb-3">
                            <input
                                id="run_async"
                                name="run_async"
                                type="checkbox"
                                value="1"
                                @checked(old('run_async', '1'))
                                @disabled($isCommitted)
                                class="form-check-input"
                            >
                            <label for="run_async" class="text-secondary">
                                Proses di latar belakang (disarankan)
                                <span class="d-block text-secondary small">
                                    Impor diproses oleh worker. Nonaktifkan untuk menjalankan sinkron dan menunggu sampai selesai.
                                </span>
                            </label>
                        </div>

                        <button
                            type="submit"
                            @disabled($isCommitted || ! $hasJob || ! $passed)
                            class="btn btn-primary"
                        >Komit dataset</button>

                        @if ($isCommitted || ! $hasJob || ! $passed)
                            <p class="text-secondary small">
                                Tombol dinonaktifkan karena
                                @if ($isCommitted)
                                    dataset sudah dikomit.
                                @elseif (! $hasJob)
                                    job impor belum tersedia.
                                @else
                                    kualitas belum mencapai ambang minimum.
                                @endif
                            </p>
                        @endif
                    </form>
                @endif
            </x-card>

            <x-card title="Contoh data" description="Maksimal 20 baris pertama dari berkas yang diunggah.">
                @if ($sampleRows === [] || $sampleColumns === [])
                    <x-empty-state
                        title="Contoh data belum tersedia"
                        description="Jalankan pratinjau pada tahap 1 agar mesin AI mengambil contoh baris."
                    />
                @else
                    <x-table-wrapper label="Contoh data">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    @foreach ($sampleColumns as $column)
                                        <th scope="col">{{ $column }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($sampleRows as $row)
                                    @php $row = (array) $row; @endphp
                                    <tr>
                                        @foreach ($sampleColumns as $column)
                                            @php $value = $row[$column] ?? null; @endphp
                                            <td class="text-secondary">
                                                @php
                                                    $text = is_scalar($value) || $value === null
                                                        ? (string) ($value ?? '')
                                                        : json_encode($value, JSON_UNESCAPED_UNICODE);
                                                @endphp
                                                {{ $text === '' ? '—' : \Illuminate\Support\Str::limit($text, 60) }}
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-table-wrapper>
                @endif
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card title="Informasi dataset" description="Metadata berkas dan status pemrosesan.">
                <dl class="datagrid">
                    <div class="datagrid-item">
                        <dt class="datagrid-title">Tipe dataset</dt>
                        <dd class="datagrid-content">{{ $typeLabels[$dataset->dataset_type] ?? $dataset->dataset_type }}</dd>
                    </div>
                    <div class="datagrid-item">
                        <dt class="datagrid-title">Berkas sumber</dt>
                        <dd class="datagrid-content">{{ $dataset->source_filename ?: 'Tidak diketahui' }}</dd>
                    </div>
                    <div class="datagrid-item">
                        <dt class="datagrid-title">Ukuran</dt>
                        <dd class="datagrid-content">{{ $dataset->sizeForHumans() }}</dd>
                    </div>
                    <div class="datagrid-item">
                        <dt class="datagrid-title">UUID</dt>
                        <dd class="datagrid-content font-monospace text-secondary small">{{ $dataset->uuid }}</dd>
                    </div>
                    @if ($dataset->checksum_sha256)
                        <div class="datagrid-item">
                            <dt class="datagrid-title">Checksum SHA-256</dt>
                            <dd class="datagrid-content font-monospace text-secondary small">{{ $dataset->checksum_sha256 }}</dd>
                        </div>
                    @endif
                    <div class="datagrid-item">
                        <dt class="datagrid-title">Skor kualitas</dt>
                        <dd class="datagrid-content">
                            @if ($score !== null)
                                {{ \Illuminate\Support\Number::percentage($score * 100, precision: 1, locale: 'id') }}
                            @else
                                belum dinilai
                            @endif
                        </dd>
                    </div>
                    <div class="datagrid-item">
                        <dt class="datagrid-title">Diperbarui</dt>
                        <dd class="datagrid-content">
                            {{ $dataset->updated_at ? $dataset->updated_at->locale('id')->translatedFormat('d M Y H:i') : 'Tidak diketahui' }}
                        </dd>
                    </div>
                </dl>
            </x-card>

            @if ($canWrite)
                <x-card title="Hapus dataset" description="Tindakan ini menghapus berkas dan seluruh metadatanya.">
                    <p class="text-secondary">
                        Job impor yang sedang berjalan tidak dapat dibatalkan. Hapus hanya dilakukan bila Anda yakin tidak lagi membutuhkan data ini.
                    </p>

                    <form
                        method="POST"
                        action="{{ route('datasets.destroy', $dataset) }}"
                        x-on:submit.confirm="Hapus dataset ini? Tindakan ini tidak dapat dibatalkan."
                    >
                        @csrf
                        @method('DELETE')
                        <button
                            type="submit"
                            class="btn btn-outline-danger"
                        >Hapus dataset</button>
                    </form>
                </x-card>
            @endif
        </div>
    </div>
@endsection
