@extends('layouts.app')

@section('title', 'Unggah dataset')

@section('content')
    @php
        $typeLabels = config('ai_engine.dataset_type_labels', []);
    @endphp

    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">Unggah dataset</h1>
                <div class="page-subtitle">
                    Berkas diteruskan ke mesin AI untuk diprofil. Setelah unggah, lanjutkan dengan pratinjau, pemetaan kolom, pemeriksaan kualitas, dan komit.
                </div>
            </div>
        </div>
    </div>

    <div class="row row-cards">
        <div class="col-lg-8">
            <x-card class="mb-3" title="Formulir unggah" description="Semua kolom wajib diisi kecuali nama dataset.">
                <form
                    method="POST"
                    action="{{ route('datasets.store') }}"
                    enctype="multipart/form-data"
                >
                    @csrf

                    <x-field
                        label="Berkas data"
                        for="file"
                        name="file"
                        required
                        hint="Format yang diterima: {{ implode(', ', array_map(fn (string $extension): string => '.'.$extension, $allowedExtensions)) }}. Maksimal {{ number_format($maxUploadMb, 0, ',', '.') }} MB."
                    >
                        <input
                            id="file"
                            name="file"
                            type="file"
                            required
                            accept="{{ collect($allowedExtensions)->map(fn (string $extension): string => '.'.$extension)->implode(',') }}"
                            @error('file') aria-invalid="true" @enderror
                            class="form-control"
                        >
                    </x-field>

                    <x-field
                        label="Nama dataset"
                        for="name"
                        name="name"
                        hint="Kosongkan untuk memakai nama berkas sebagai nama dataset."
                    >
                        <input
                            id="name"
                            name="name"
                            type="text"
                            value="{{ old('name') }}"
                            maxlength="150"
                            placeholder="mis. Penjualan Retail Jakarta 2026"
                            @error('name') aria-invalid="true" @enderror
                            class="form-control"
                        >
                    </x-field>

                    <x-field label="Tipe dataset" for="dataset_type" name="dataset_type" required>
                        <select
                            id="dataset_type"
                            name="dataset_type"
                            required
                            @error('dataset_type') aria-invalid="true" @enderror
                            class="form-select"
                        >
                            <option value="">Pilih tipe dataset</option>
                            @foreach ($datasetTypes as $type)
                                <option value="{{ $type }}" @selected(old('dataset_type') === $type)>{{ $typeLabels[$type] ?? $type }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <div class="d-flex flex-wrap gap-2">
                        <button
                            type="submit"
                            class="btn btn-primary"
                        >Unggah dan lanjutkan</button>
                        <a
                            href="{{ route('datasets.index') }}"
                            class="btn"
                        >Batal</a>
                    </div>
                </form>
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card title="Ketentuan" description="Yang perlu dipenuhi sebelum unggah diproses.">
                <ul class="list-group list-group-flush text-secondary">
                    <li class="list-group-item">
                        <span class="fw-bold">1.</span>
                        <span>Ukuran berkas maksimal {{ number_format($maxUploadMb, 0, ',', '.') }} MB.</span>
                    </li>
                    <li class="list-group-item">
                        <span class="fw-bold">2.</span>
                        <span>Ekstensi yang diterima: {{ implode(', ', array_map(fn (string $extension): string => '.'.$extension, $allowedExtensions)) }}.</span>
                    </li>
                    <li class="list-group-item">
                        <span class="fw-bold">3.</span>
                        <span>Tipe dataset menentukan kamus kolom kanonik saat pemetaan.</span>
                    </li>
                    <li class="list-group-item">
                        <span class="fw-bold">4.</span>
                        <span>Data hanya dikomit ke gudang data setelah lolos ambang kualitas.</span>
                    </li>
                </ul>
            </x-card>
        </div>
    </div>
@endsection
