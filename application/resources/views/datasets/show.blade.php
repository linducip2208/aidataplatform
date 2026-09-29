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

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <nav aria-label="Remah roti" class="mb-2 text-sm text-slate-500">
                <a
                    href="{{ route('datasets.index') }}"
                    class="rounded hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                >Kumpulan data</a>
                <span aria-hidden="true"> / </span>
                <span class="text-slate-700">{{ $dataset->name }}</span>
            </nav>

            <h1 class="text-2xl font-semibold text-slate-900">{{ $dataset->name }}</h1>
            <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                <x-badge :class="$status->badgeClass()">{{ $status->localizedLabel() }}</x-badge>
                <span>{{ $dataset->source_filename ?: 'Tanpa nama berkas' }}</span>
                <span aria-hidden="true">&middot;</span>
                <span>{{ $dataset->sizeForHumans() }}</span>
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <a
                href="{{ route('datasets.index') }}"
                class="inline-flex items-center rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
            >Kembali ke daftar</a>

            @if ($hasJob)
                <a
                    href="{{ route('imports.show', $dataset) }}"
                    class="inline-flex items-center rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                >Lihat job impor</a>
            @endif
        </div>
    </div>

    <ol class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Tahapan pemrosesan dataset">
        @foreach ($steps as $index => $step)
            <li class="app-card p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Tahap {{ $index + 1 }}</p>
                <p class="mt-1 text-sm font-semibold text-slate-900">{{ $step['label'] }}</p>
                <p class="mt-2">
                    @if ($step['done'])
                        <x-badge variant="success">Selesai</x-badge>
                    @elseif ($step['ready'])
                        <x-badge variant="info">Siap dijalankan</x-badge>
                    @else
                        <x-badge variant="neutral">Menunggu langkah sebelumnya</x-badge>
                    @endif
                </p>
            </li>
        @endforeach
    </ol>

    @unless ($canWrite)
        <x-card class="mb-6" title="Akses baca saja">
            <p class="text-sm text-slate-600">
                Peran <strong>{{ auth()->user()->role()->localizedLabel() }}</strong> hanya dapat melihat dataset.
                Menjalankan pratinjau, pemetaan, kualitas, dan komit terbatas untuk
                {{ \App\Enums\UserRole::Admin->localizedLabel() }} dan {{ \App\Enums\UserRole::Analyst->localizedLabel() }}.
            </p>
        </x-card>
    @endunless

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-card
                title="Tahap 1: Pratinjau data"
                description="Membaca berkas dan memprofil jumlah baris, kolom, serta contoh data."
            >
                @if (! $hasJob)
                    <p class="mb-4 text-sm text-slate-600">
                        Dataset ini belum memiliki job import, jadi mesin AI tidak bisa memprofilnya. Unggah ulang berkasnya untuk memulai dari awal.
                    </p>
                @endif

                <dl class="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-sm font-medium text-slate-500">Jumlah baris</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-slate-900">{{ number_format((int) $dataset->row_count, 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-slate-500">Jumlah kolom</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-slate-900">{{ number_format((int) $dataset->column_count, 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-slate-500">Job impor</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-slate-900">{{ $dataset->import_job_id ? number_format((int) $dataset->import_job_id, 0, ',', '.') : 'Belum ada' }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-slate-500">Diunggah</dt>
                        <dd class="mt-1 text-sm text-slate-700">
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
                            class="inline-flex items-center rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-500"
                        >{{ $hasProfile ? 'Jalankan pratinjau ulang' : 'Jalankan pratinjau' }}</button>

                        @unless ($hasJob)
                            <p class="mt-2 text-xs text-slate-500">Tombol dinonaktifkan karena dataset belum memiliki job import.</p>
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

                            <x-table-wrapper label="Pemetaan kolom" class="mb-4">
                                <table class="app-table">
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
                                                    <span class="font-medium text-slate-900">{{ $column }}</span>
                                                    @php $profile = $columnProfiles[$loop->index] ?? null; @endphp
                                                    @if (is_array($profile))
                                                        <span class="mt-1 block text-xs text-slate-500">
                                                            @if (! empty($profile['dtype']))
                                                                <span class="uppercase">{{ $profile['dtype'] }}</span>
                                                                &middot;
                                                            @endif
                                            kosong {{ number_format((int) ($profile['missing'] ?? 0), 0, ',', '.') }}
                                            &middot;
                                            {{ number_format((int) ($profile['unique'] ?? 0), 0, ',', '.') }} nilai unik
                                                        </span>
                                                    @endif
                                                </td>
                                                <td>
                                                    <label for="mapping-{{ $loop->index }}" class="sr-only">Target untuk kolom {{ $column }}</label>
                                                    <select
                                                        id="mapping-{{ $loop->index }}"
                                                        name="{{ $inputName }}"
                                                        class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
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
                                <p class="mb-4 text-xs font-medium text-rose-700">{{ $message }}</p>
                            @enderror

                            <x-field
                                label="Simpan sebagai template"
                                for="save_as_template"
                                name="save_as_template"
                                hint="Opsional. Nama template untuk dipakai ulang pada dataset berikutnya."
                                class="mb-4"
                            >
                                <input
                                    id="save_as_template"
                                    name="save_as_template"
                                    type="text"
                                    value="{{ old('save_as_template') }}"
                                    maxlength="120"
                                    placeholder="mis. template_penjualan_retail"
                                    @error('save_as_template') aria-invalid="true" @enderror
                                    class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                                >
                            </x-field>

                            <button
                                type="submit"
                                class="inline-flex items-center rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                            >Simpan pemetaan</button>
                        </form>
                    @else
                        <dl class="divide-y divide-slate-100">
                            @foreach ($mappings as $source => $target)
                                <div class="flex flex-wrap items-center justify-between gap-2 py-2">
                                    <dt class="text-sm font-medium text-slate-900">{{ $source }}</dt>
                                    <dd class="text-sm text-slate-600">{{ $target }}</dd>
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
                    <p class="mb-4 text-sm text-slate-600">
                        Belum ada laporan kualitas. Jalankan pemeriksaan setelah pemetaan kolom tersimpan.
                    </p>
                @else
                    <div class="mb-4 flex flex-wrap items-center gap-3">
                        <p class="text-3xl font-semibold tabular-nums text-slate-900">
                            {{ \Illuminate\Support\Number::percentage($score * 100, precision: 1, locale: 'id') }}
                        </p>
                        <x-badge variant="{{ $passed ? 'success' : 'danger' }}">{{ $passed ? \App\Enums\QualityVerdict::Pass->localizedLabel() : 'Belum lolos' }}</x-badge>
                        <span class="text-sm text-slate-500">Ambang minimum {{ \Illuminate\Support\Number::percentage($threshold * 100, precision: 1, locale: 'id') }}</span>
                    </div>

                    <dl class="mb-4 space-y-3">
                        @foreach ($breakdownLabels as $key => $label)
                            @php
                                $value = (float) ($breakdown[$key] ?? 0);
                                $percent = max(0.0, min(1.0, $value)) * 100;
                                $barClass = $value >= $threshold ? 'bg-emerald-600' : 'bg-amber-500';
                            @endphp
                            <div>
                                <div class="flex items-center justify-between gap-2 text-sm">
                                    <dt class="font-medium text-slate-700">{{ $label }}</dt>
                                    <dd class="tabular-nums text-slate-600">{{ \Illuminate\Support\Number::percentage($value * 100, precision: 1, locale: 'id') }}</dd>
                                </div>
                                <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-slate-200">
                                    <div class="h-2 rounded-full {{ $barClass }}" style="width: {{ number_format($percent, 1, '.', '') }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </dl>
                @endif

                @if ($issues !== [])
                    <x-table-wrapper label="Temuan pemeriksaan kualitas" class="mb-4">
                        <table class="app-table">
                            <thead>
                                <tr>
                                    <th scope="col">Aturan</th>
                                    <th scope="col">Kolom</th>
                                    <th scope="col" class="text-right">Jumlah</th>
                                    <th scope="col">Keterangan</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($issues as $issue)
                                    <tr>
                                        <td><code class="rounded bg-slate-100 px-1 text-xs">{{ $issue['rule'] ?? 'tidak diketahui' }}</code></td>
                                        <td>{{ $issue['column'] ?? 'seluruh tabel' }}</td>
                                        <td class="text-right tabular-nums">{{ number_format((int) ($issue['count'] ?? 0), 0, ',', '.') }}</td>
                                        <td class="text-sm text-slate-600">{{ $issue['message'] ?? 'Tanpa keterangan' }}</td>
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
                            class="inline-flex items-center rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-500"
                        >{{ $score === null ? 'Jalankan pemeriksaan kualitas' : 'Jalankan ulang pemeriksaan' }}</button>

                        @unless ($hasJob && $hasMappings)
                            <p class="mt-2 text-xs text-slate-500">
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
                    <div class="mb-4 flex flex-wrap items-center gap-2">
                        <x-badge :class="$status->badgeClass()">{{ $status->localizedLabel() }}</x-badge>
                        <p class="text-sm text-slate-600">
                            Komit terakhir
                            @if ($dataset->committed_at)
                                pada {{ $dataset->committed_at->locale('id')->translatedFormat('d M Y H:i') }}.
                            @else
                                .
                            @endif
                        </p>
                    </div>
                @elseif (! $passed)
                    <p class="mb-4 text-sm text-slate-600">
                        Komit baru bisa dijalankan setelah skor kualitas mencapai ambang minimum
                        {{ \Illuminate\Support\Number::percentage($threshold * 100, precision: 1, locale: 'id') }}.
                    </p>
                @endif

                @if ($canWrite)
                    <form method="POST" action="{{ route('datasets.commit', $dataset) }}">
                        @csrf

                        <div class="mb-4 flex items-start gap-2">
                            <input
                                id="run_async"
                                name="run_async"
                                type="checkbox"
                                value="1"
                                @checked(old('run_async', '1'))
                                @disabled($isCommitted)
                                class="mt-1 h-4 w-4 rounded border-slate-300 text-brand-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                            >
                            <label for="run_async" class="text-sm text-slate-700">
                                Proses di latar belakang (disarankan)
                                <span class="mt-1 block text-xs text-slate-500">
                                    Impor diproses oleh worker. Nonaktifkan untuk menjalankan sinkron dan menunggu sampai selesai.
                                </span>
                            </label>
                        </div>

                        <button
                            type="submit"
                            @disabled($isCommitted || ! $hasJob || ! $passed)
                            class="inline-flex items-center rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-500"
                        >Komit dataset</button>

                        @if ($isCommitted || ! $hasJob || ! $passed)
                            <p class="mt-2 text-xs text-slate-500">
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
                        <table class="app-table">
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
                                            <td class="whitespace-nowrap text-sm text-slate-600">
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

        <div class="space-y-6">
            <x-card title="Informasi dataset" description="Metadata berkas dan status pemrosesan.">
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="font-medium text-slate-500">Tipe dataset</dt>
                        <dd class="text-slate-900">{{ $typeLabels[$dataset->dataset_type] ?? $dataset->dataset_type }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-slate-500">Berkas sumber</dt>
                        <dd class="break-all text-slate-900">{{ $dataset->source_filename ?: 'Tidak diketahui' }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-slate-500">Ukuran</dt>
                        <dd class="text-slate-900">{{ $dataset->sizeForHumans() }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-slate-500">UUID</dt>
                        <dd class="break-all font-mono text-xs text-slate-700">{{ $dataset->uuid }}</dd>
                    </div>
                    @if ($dataset->checksum_sha256)
                        <div>
                            <dt class="font-medium text-slate-500">Checksum SHA-256</dt>
                            <dd class="break-all font-mono text-xs text-slate-700">{{ $dataset->checksum_sha256 }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="font-medium text-slate-500">Skor kualitas</dt>
                        <dd class="text-slate-900">
                            @if ($score !== null)
                                {{ \Illuminate\Support\Number::percentage($score * 100, precision: 1, locale: 'id') }}
                            @else
                                belum dinilai
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="font-medium text-slate-500">Diperbarui</dt>
                        <dd class="text-slate-900">
                            {{ $dataset->updated_at ? $dataset->updated_at->locale('id')->translatedFormat('d M Y H:i') : 'Tidak diketahui' }}
                        </dd>
                    </div>
                </dl>
            </x-card>

            @if ($canWrite)
                <x-card title="Hapus dataset" description="Tindakan ini menghapus berkas dan seluruh metadatanya.">
                    <p class="mb-4 text-sm text-slate-600">
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
                            class="inline-flex items-center rounded-md border border-rose-600 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2"
                        >Hapus dataset</button>
                    </form>
                </x-card>
            @endif
        </div>
    </div>
@endsection
