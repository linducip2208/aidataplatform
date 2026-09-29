@extends('layouts.app')

@section('title', 'Pembelajaran mesin')

@section('content')
    @php
        $typeLabels = config('ai_engine.model_type_labels', []);

        // One entry per status the registry can report, carrying both the word
        // a user reads and the badge it paints, so the two cannot drift apart.
        $statuses = config('ai_engine.model_version_statuses', []);

        $promoteTargets = array_intersect_key($statuses, array_flip(['PRODUCTION', 'STAGED', 'ARCHIVED']));

        $selectedId = isset($selected['id']) ? (int) $selected['id'] : null;
        $versions = (array) ($selected['versions'] ?? []);
    @endphp

    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-slate-900">Pembelajaran mesin</h1>
        <p class="mt-1 text-sm text-slate-500">
            Latih model, tinjau metrik setiap versi, dan promosikan versi terbaik ke produksi sesuai tata kelola model.
        </p>
    </div>

    @if (filled($error))
        <x-card class="mb-6">
            <div role="alert" class="flex flex-wrap items-center gap-2">
                <x-badge variant="danger">Gagal</x-badge>
                <p class="text-sm text-slate-700">{{ $error }}</p>
            </div>
        </x-card>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-card title="Daftar model" description="Model yang terdaftar di registry mesin AI.">
                @if ($models === [])
                    <x-empty-state
                        title="Belum ada model"
                        description="Jalankan pelatihan pertama melalui formulir di samping untuk membuat model baru."
                    />
                @else
                    <x-table-wrapper label="Daftar model">
                        <table class="app-table">
                            <thead>
                                <tr>
                                    <th scope="col">Model</th>
                                    <th scope="col">Tipe</th>
                                    <th scope="col">Status</th>
                                    <th scope="col" class="text-right">Versi produksi</th>
                                    <th scope="col"><span class="sr-only">Aksi</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($models as $model)
                                    @php
                                        $statusKey = strtoupper((string) ($model['status'] ?? 'DRAFT'));
                                        $modelId = (int) ($model['id'] ?? 0);
                                    @endphp
                                    <tr @if ($selectedId === $modelId) class="bg-brand-50" @endif>
                                        <td>
                                            <span class="font-medium text-slate-900">{{ $model['name'] ?? 'Tanpa nama' }}</span>
                                            <span class="mt-1 block text-xs text-slate-500">ID {{ $modelId }}</span>
                                        </td>
                                        <td>{{ $typeLabels[$model['model_type'] ?? ''] ?? ($model['model_type'] ?? 'Tidak diketahui') }}</td>
                                        <td>
                                            <x-badge :class="$statuses[$statusKey]['badge'] ?? 'badge-neutral'">
                                                {{ $statuses[$statusKey]['label'] ?? 'Status tidak dikenal' }}
                                            </x-badge>
                                        </td>
                                        <td class="text-right tabular-nums">
                                            {{ ! empty($model['production_version_id']) ? number_format((int) $model['production_version_id'], 0, ',', '.') : 'Belum ada' }}
                                        </td>
                                        <td class="text-right">
                                            <form method="GET" action="{{ route('ml.index') }}">
                                                <input type="hidden" name="model" value="{{ $modelId }}">
                                                <button
                                                    type="submit"
                                                    class="rounded-md border border-slate-300 px-3 py-1 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                                                >{{ $selectedId === $modelId ? 'Sedang dibuka' : 'Lihat versi' }}</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-table-wrapper>
                @endif
            </x-card>

            <x-card
                title="Versi model"
                :description="$selectedId ? 'Riwayat versi untuk model yang dipilih beserta metrik evaluasinya.' : 'Pilih salah satu model di tabel untuk melihat riwayat versinya.'"
            >
                @if ($versions === [])
                    <x-empty-state
                        title="Belum ada versi"
                        description="Model ini belum memiliki versi. Latih model untuk membuat versi pertama."
                    />
                @else
                    <x-table-wrapper label="Versi model">
                        <table class="app-table">
                            <thead>
                                <tr>
                                    <th scope="col">Versi</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Metrik</th>
                                    @if ($canApprove)
                                        <th scope="col"><span class="sr-only">Promosi</span></th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($versions as $version)
                                    @php
                                        $versionStatus = strtoupper((string) ($version['status'] ?? 'DRAFT'));
                                        $versionId = (int) ($version['id'] ?? 0);
                                        $metrics = (array) ($version['metrics'] ?? []);
                                    @endphp
                                    <tr>
                                        <td>
                                            <span class="font-medium text-slate-900">{{ $version['version'] ?? 'v'.$versionId }}</span>
                                            <span class="mt-1 block text-xs text-slate-500">ID {{ $versionId }}</span>
                                        </td>
                                        <td>
                                            <x-badge :class="$statuses[$versionStatus]['badge'] ?? 'badge-neutral'">
                                                {{ $statuses[$versionStatus]['label'] ?? 'Status tidak dikenal' }}
                                            </x-badge>
                                        </td>
                                        <td>
                                            @if ($metrics === [])
                                                <span class="text-sm text-slate-500">Tidak ada metrik</span>
                                            @else
                                                <dl class="space-y-1 text-sm">
                                                    @foreach ($metrics as $metricKey => $metricValue)
                                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                                            <dt class="text-slate-500">{{ \Illuminate\Support\Str::headline((string) $metricKey) }}</dt>
                                                            <dd class="tabular-nums text-slate-900">
                                                                @if (is_bool($metricValue))
                                                                    {{ $metricValue ? 'Ya' : 'Tidak' }}
                                                                @elseif (is_scalar($metricValue) || $metricValue === null)
                                                                    {{ \Illuminate\Support\Str::limit((string) $metricValue, 40) }}
                                                                @else
                                                                    {{ \Illuminate\Support\Str::limit((string) json_encode($metricValue, JSON_UNESCAPED_UNICODE), 60) }}
                                                                @endif
                                                            </dd>
                                                        </div>
                                                    @endforeach
                                                </dl>
                                            @endif
                                        </td>
                                        @if ($canApprove)
                                            <td>
                                                <form method="POST" action="{{ route('ml.promote', ['modelId' => $selectedId]) }}" class="space-y-2">
                                                    @csrf
                                                    <input type="hidden" name="version_id" value="{{ $versionId }}">

                                                    <label for="to_status-{{ $versionId }}" class="sr-only">Status tujuan untuk versi {{ $version['version'] ?? $versionId }}</label>
                                                    <select
                                                        id="to_status-{{ $versionId }}"
                                                        name="to_status"
                                                        class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                                                    >
                                                        @foreach ($promoteTargets as $value => $target)
                                                            <option value="{{ $value }}" @selected(old('to_status', 'PRODUCTION') === $value)>{{ $target['label'] }}</option>
                                                        @endforeach
                                                    </select>

                                                    <button
                                                        type="submit"
                                                        class="inline-flex w-full items-center justify-center rounded-md bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                                                    >Promosikan</button>
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
        </div>

        <div class="space-y-6">
            @if (auth()->user()->isAnalyst())
                <x-card title="Latih model baru" description="Pelatihan dijalankan mesin AI dan menghasilkan versi tervalidasi.">
                    <form method="POST" action="{{ route('ml.train') }}" class="space-y-4">
                        @csrf

                        <x-field label="Tipe model" for="model_type" name="model_type" required>
                            <select
                                id="model_type"
                                name="model_type"
                                required
                                @error('model_type') aria-invalid="true" @enderror
                                class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                            >
                                <option value="">Pilih tipe model</option>
                                @foreach ($modelTypes as $type)
                                    <option value="{{ $type }}" @selected(old('model_type') === $type)>{{ $typeLabels[$type] ?? $type }}</option>
                                @endforeach
                            </select>
                        </x-field>

                        <x-field label="Nama model" for="name" name="name" required>
                            <input
                                id="name"
                                name="name"
                                type="text"
                                value="{{ old('name') }}"
                                required
                                maxlength="100"
                                placeholder="mis. forecast_penjualan_harian"
                                @error('name') aria-invalid="true" @enderror
                                class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                            >
                        </x-field>

                        <x-field
                            label="Parameter JSON"
                            for="params"
                            name="params"
                            hint="Objek JSON kosong {}, misalnya {&quot;horizon&quot;: 30, &quot;n_clusters&quot;: 4}."
                        >
                            <textarea
                                id="params"
                                name="params"
                                rows="5"
                                spellcheck="false"
                                @error('params') aria-invalid="true" @enderror
                                class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 font-mono text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                            >{{ old('params', '{}') }}</textarea>
                        </x-field>

                        <button
                            type="submit"
                            class="inline-flex w-full items-center justify-center rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                        >Mulai pelatihan</button>
                    </form>
                </x-card>
            @else
                <x-card title="Akses baca saja">
                    <p class="text-sm text-slate-600">
                        Peran <strong>{{ auth()->user()->role()->localizedLabel() }}</strong> tidak dapat menjalankan pelatihan model.
                        Pelatihan tersedia untuk {{ \App\Enums\UserRole::Admin->localizedLabel() }} dan {{ \App\Enums\UserRole::Analyst->localizedLabel() }}.
                    </p>
                </x-card>
            @endif

            <x-card title="Tata kelola model" description="Aturan promosi versi model.">
                <ul class="space-y-2 text-sm text-slate-600">
                    <li>Hanya {{ \App\Enums\UserRole::Admin->localizedLabel() }} yang dapat menaikkan versi model ke produksi.</li>
                    <li>Setiap versi menyimpan metrik evaluasi hasil validasi.</li>
                    <li>Model produksi dipakai untuk prediksi dan analitik otomatis.</li>
                </ul>
            </x-card>
        </div>
    </div>

    @php
        $experiments = $experiments ?? [];
        $experimentsError = $experimentsError ?? null;
        $events = $events ?? [];
        $eventsError = $eventsError ?? null;
    @endphp

    <div class="mt-6 space-y-6">
        <x-card
            title="Eksperimen"
            description="Jejak pelatihan dengan pembagian train/validasi/test, metrik per split, dan promosi versi terbaik."
        >
            @if (filled($experimentsError))
                <div role="alert" class="flex flex-wrap items-center gap-2">
                    <x-badge variant="danger">Gagal</x-badge>
                    <p class="text-sm text-slate-700">{{ $experimentsError }}</p>
                </div>
            @elseif ($experiments === [])
                <x-empty-state
                    title="Belum ada eksperimen"
                    description="Buat eksperimen pertama melalui formulir di bawah untuk mencatat split dan metrik pelatihan."
                />
            @else
                <x-table-wrapper label="Daftar eksperimen">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th scope="col">Eksperimen</th>
                                <th scope="col">Tipe</th>
                                <th scope="col">Status</th>
                                <th scope="col">Split</th>
                                <th scope="col">Metrik validasi</th>
                                @if ($canApprove)
                                    <th scope="col"><span class="sr-only">Promosi eksperimen</span></th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($experiments as $experiment)
                                @php
                                    $experimentId = (int) ($experiment['id'] ?? 0);
                                    $experimentStatus = strtoupper((string) ($experiment['status'] ?? 'PLANNED'));
                                    $splitConfig = (array) ($experiment['split_config'] ?? []);
                                    $splitSizes = (array) ($splitConfig['sizes'] ?? []);
                                    $validateMetrics = (array) (($experiment['metrics']['validate'] ?? []) ?? []);
                                @endphp
                                <tr>
                                    <td>
                                        <span class="font-medium text-slate-900">{{ $experiment['name'] ?? 'Tanpa nama' }}</span>
                                        <span class="mt-1 block text-xs text-slate-500">ID {{ $experimentId }}</span>
                                    </td>
                                    <td>{{ $typeLabels[$experiment['model_type'] ?? ''] ?? ($experiment['model_type'] ?? 'Tidak diketahui') }}</td>
                                    <td>
                                        <x-badge :class="$statuses[$experimentStatus]['badge'] ?? 'badge-neutral'">
                                            {{ $statuses[$experimentStatus]['label'] ?? $experimentStatus }}
                                        </x-badge>
                                    </td>
                                    <td class="text-sm text-slate-600">
                                        {{ $splitConfig['strategy'] ?? 'belum dibagi' }}
                                        @if ($splitSizes !== [])
                                            <span class="block text-xs tabular-nums">
                                                latih {{ $splitSizes['train'] ?? 0 }} /
                                                validasi {{ $splitSizes['validate'] ?? 0 }} /
                                                uji {{ $splitSizes['test'] ?? 0 }}
                                            </span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($validateMetrics === [])
                                            <span class="text-sm text-slate-500">Tidak ada metrik</span>
                                        @else
                                            <dl class="space-y-1 text-sm">
                                                @foreach ($validateMetrics as $metricKey => $metricValue)
                                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                                        <dt class="text-slate-500">{{ \Illuminate\Support\Str::headline((string) $metricKey) }}</dt>
                                                        <dd class="tabular-nums text-slate-900">
                                                            @if (is_scalar($metricValue) || $metricValue === null)
                                                                {{ \Illuminate\Support\Str::limit((string) $metricValue, 40) }}
                                                            @else
                                                                {{ \Illuminate\Support\Str::limit((string) json_encode($metricValue, JSON_UNESCAPED_UNICODE), 60) }}
                                                            @endif
                                                        </dd>
                                                    </div>
                                                @endforeach
                                            </dl>
                                        @endif
                                    </td>
                                    @if ($canApprove)
                                        <td>
                                            <form method="POST" action="{{ url('/ml/experiments/'.$experimentId.'/promote') }}">
                                                @csrf
                                                <button
                                                    type="submit"
                                                    class="inline-flex w-full items-center justify-center rounded-md bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                                                >Promosikan versi</button>
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

        @if (isset($selected['id']))
            <x-card
                title="Jejak audit model"
                description="Riwayat promosi, rollback, dan status deployment untuk model yang dipilih."
            >
                @if (filled($eventsError))
                    <div role="alert" class="flex flex-wrap items-center gap-2">
                        <x-badge variant="danger">Gagal</x-badge>
                        <p class="text-sm text-slate-700">{{ $eventsError }}</p>
                    </div>
                @elseif ($events === [])
                    <x-empty-state
                        title="Belum ada peristiwa"
                        description="Promosi atau rollback pertama akan tercatat di sini."
                    />
                @else
                    <x-table-wrapper label="Jejak audit model">
                        <table class="app-table">
                            <thead>
                                <tr>
                                    <th scope="col">Waktu</th>
                                    <th scope="col">Peristiwa</th>
                                    <th scope="col">Versi</th>
                                    <th scope="col">Perubahan</th>
                                    <th scope="col">Catatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($events as $event)
                                    <tr>
                                        <td class="text-sm text-slate-600">{{ $event['created_at'] ?? '-' }}</td>
                                        <td class="text-sm text-slate-900">{{ $event['event_type'] ?? '-' }}</td>
                                        <td class="tabular-nums text-sm text-slate-900">{{ $event['version_id'] ?? '-' }}</td>
                                        <td class="text-sm text-slate-600">{{ $event['from_status'] ?? '' }} &rarr; {{ $event['to_status'] ?? '' }}</td>
                                        <td class="text-sm text-slate-600">{{ \Illuminate\Support\Str::limit((string) ($event['note'] ?? ''), 80) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-table-wrapper>
                @endif

                @if ($canApprove)
                    <form method="POST" action="{{ url('/ml/'.((int) $selected['id']).'/rollback') }}" class="mt-4 flex flex-wrap items-end gap-3">
                        @csrf
                        <div class="min-w-52 flex-1">
                            <label for="rollback-note" class="mb-1 block text-sm font-medium text-slate-700">Catatan rollback</label>
                            <input
                                id="rollback-note"
                                name="note"
                                type="text"
                                maxlength="1024"
                                placeholder="mis. versi bermasalah di produksi"
                                class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                            >
                        </div>
                        <button
                            type="submit"
                            class="inline-flex items-center justify-center rounded-md border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2"
                        >Kembalikan ke versi sebelumnya</button>
                    </form>
                @endif
            </x-card>
        @endif

        @if (auth()->user()->isAnalyst())
            <div class="grid gap-6 lg:grid-cols-2">
                <x-card title="Buat eksperimen" description="Catat split train/validasi/test beserta metrik per split.">
                    <form method="POST" action="{{ url('/ml/experiments') }}" class="space-y-4">
                        @csrf

                        <x-field label="Tipe model" for="experiment_model_type" name="model_type" required>
                            <select
                                id="experiment_model_type"
                                name="model_type"
                                required
                                class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                            >
                                <option value="">Pilih tipe model</option>
                                @foreach ($modelTypes as $type)
                                    <option value="{{ $type }}">{{ $typeLabels[$type] ?? $type }}</option>
                                @endforeach
                            </select>
                        </x-field>

                        <x-field label="Nama eksperimen" for="experiment_name" name="name">
                            <input
                                id="experiment_name"
                                name="name"
                                type="text"
                                maxlength="128"
                                placeholder="mis. churn_q1_baseline"
                                class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                            >
                        </x-field>

                        <button
                            type="submit"
                            class="inline-flex w-full items-center justify-center rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                        >Jalankan eksperimen</button>
                    </form>
                </x-card>

                <x-card title="Batch prediksi" description="Nilai dataset baris demi chunk dengan versi produksi dan simpan riwayatnya.">
                    <form method="POST" action="{{ url('/ml/batch-predict') }}" class="space-y-4">
                        @csrf

                        <x-field label="Tipe model" for="batch_model_type" name="model_type" required>
                            <select
                                id="batch_model_type"
                                name="model_type"
                                required
                                class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                            >
                                <option value="">Pilih tipe model</option>
                                @foreach ($modelTypes as $type)
                                    <option value="{{ $type }}">{{ $typeLabels[$type] ?? $type }}</option>
                                @endforeach
                            </select>
                        </x-field>

                        <x-field label="Nama model" for="batch_model_name" name="model_name">
                            <input
                                id="batch_model_name"
                                name="model_name"
                                type="text"
                                maxlength="128"
                                placeholder="mis. churn-model"
                                class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                            >
                        </x-field>

                        <x-field
                            label="Dataset JSON"
                            for="batch_dataset"
                            name="dataset"
                            hint="Array JSON dari baris data, misalnya [{&quot;recency&quot;: 12}]."
                        >
                            <textarea
                                id="batch_dataset"
                                name="dataset"
                                rows="4"
                                spellcheck="false"
                                class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 font-mono text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                            >[]</textarea>
                        </x-field>

                        <button
                            type="submit"
                            class="inline-flex w-full items-center justify-center rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                        >Jalankan batch prediksi</button>
                    </form>
                </x-card>
            </div>
        @endif
    </div>
@endsection
