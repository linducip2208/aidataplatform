@extends('layouts.app')

@section('title', 'Unggah dataset')

@section('content')
    @php
        $typeLabels = config('ai_engine.dataset_type_labels', []);
    @endphp

    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-slate-900">Unggah dataset</h1>
        <p class="mt-1 text-sm text-slate-500">
            Berkas diteruskan ke mesin AI untuk diprofil. Setelah unggah, lanjutkan dengan pratinjau, pemetaan kolom, pemeriksaan kualitas, dan komit.
        </p>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-card class="lg:col-span-2" title="Formulir unggah" description="Semua kolom wajib diisi kecuali nama dataset.">
            <form
                method="POST"
                action="{{ route('datasets.store') }}"
                enctype="multipart/form-data"
                class="space-y-5"
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
                        class="block w-full cursor-pointer rounded-md border border-slate-300 bg-white text-sm text-slate-700 file:mr-4 file:cursor-pointer file:border-0 file:bg-slate-100 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-slate-700 hover:file:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
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
                        class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                    >
                </x-field>

                <x-field label="Tipe dataset" for="dataset_type" name="dataset_type" required>
                    <select
                        id="dataset_type"
                        name="dataset_type"
                        required
                        @error('dataset_type') aria-invalid="true" @enderror
                        class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                    >
                        <option value="">Pilih tipe dataset</option>
                        @foreach ($datasetTypes as $type)
                            <option value="{{ $type }}" @selected(old('dataset_type') === $type)>{{ $typeLabels[$type] ?? $type }}</option>
                        @endforeach
                    </select>
                </x-field>

                <div class="flex flex-wrap gap-2 border-t border-slate-200 pt-4">
                    <button
                        type="submit"
                        class="inline-flex items-center rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                    >Unggah dan lanjutkan</button>
                    <a
                        href="{{ route('datasets.index') }}"
                        class="inline-flex items-center rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
                    >Batal</a>
                </div>
            </form>
        </x-card>

        <x-card title="Ketentuan" description="Yang perlu dipenuhi sebelum unggah diproses.">
            <ul class="space-y-3 text-sm text-slate-600">
                <li class="flex gap-2">
                    <span class="font-semibold text-slate-900">1.</span>
                    <span>Ukuran berkas maksimal {{ number_format($maxUploadMb, 0, ',', '.') }} MB.</span>
                </li>
                <li class="flex gap-2">
                    <span class="font-semibold text-slate-900">2.</span>
                    <span>Ekstensi yang diterima: {{ implode(', ', array_map(fn (string $extension): string => '.'.$extension, $allowedExtensions)) }}.</span>
                </li>
                <li class="flex gap-2">
                    <span class="font-semibold text-slate-900">3.</span>
                    <span>Tipe dataset menentukan kamus kolom kanonik saat pemetaan.</span>
                </li>
                <li class="flex gap-2">
                    <span class="font-semibold text-slate-900">4.</span>
                    <span>Data hanya dikomit ke gudang data setelah lolos ambang kualitas.</span>
                </li>
            </ul>
        </x-card>
    </div>
@endsection
