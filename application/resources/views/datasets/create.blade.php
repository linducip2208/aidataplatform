@extends('layouts.app')

@section('title', 'Unggah dataset')

@section('content')
    @php
        $typeLabels = config('ai_engine.dataset_type_labels', []);
    @endphp

    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">{{ __('datasets.create_header_title') }}</h1>
                <div class="page-subtitle">
                    {{ __('datasets.create_header_subtitle') }}
                </div>
            </div>
        </div>
    </div>

    <div class="row row-cards">
        <div class="col-lg-8">
            <x-card class="mb-3" :title="__('datasets.create_form_title')" description="{{ __('datasets.create_form_desc') }}">
                <form
                    method="POST"
                    action="{{ route('datasets.store') }}"
                    enctype="multipart/form-data"
                >
                    @csrf

                    <x-field
                        :label="__('datasets.create_file_label')"
                        for="file"
                        name="file"
                        required
                        hint="{{ __('datasets.create_file_hint', ['formats' => implode(', ', array_map(fn (string $extension): string => '.'.$extension, $allowedExtensions)), 'max' => number_format($maxUploadMb, 0, ',', '.')]) }}"
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
                        :label="__('datasets.create_name_label')"
                        for="name"
                        name="name"
                        hint="{{ __('datasets.create_name_hint') }}"
                    >
                        <input
                            id="name"
                            name="name"
                            type="text"
                            value="{{ old('name') }}"
                            maxlength="150"
                            placeholder="{{ __('datasets.create_name_placeholder') }}"
                            @error('name') aria-invalid="true" @enderror
                            class="form-control"
                        >
                    </x-field>

                    <x-field :label="__('datasets.create_type_label')" for="dataset_type" name="dataset_type" required>
                        <select
                            id="dataset_type"
                            name="dataset_type"
                            required
                            @error('dataset_type') aria-invalid="true" @enderror
                            class="form-select"
                        >
                            <option value="">{{ __('datasets.create_type_placeholder') }}</option>
                            @foreach ($datasetTypes as $type)
                                <option value="{{ $type }}" @selected(old('dataset_type') === $type)>{{ $typeLabels[$type] ?? $type }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <div class="d-flex flex-wrap gap-2">
                        <button
                            type="submit"
                            class="btn btn-primary"
                        >{{ __('datasets.create_submit') }}</button>
                        <a
                            href="{{ route('datasets.index') }}"
                            class="btn"
                        >{{ __('datasets.create_cancel') }}</a>
                    </div>
                </form>
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card :title="__('datasets.create_rules_title')" description="{{ __('datasets.create_rules_desc') }}">
                <ul class="list-group list-group-flush text-secondary">
                    <li class="list-group-item">
                        <span class="fw-bold">1.</span>
                        <span>{{ __('datasets.create_rule_1', ['max' => number_format($maxUploadMb, 0, ',', '.')]) }}</span>
                    </li>
                    <li class="list-group-item">
                        <span class="fw-bold">2.</span>
                        <span>{{ __('datasets.create_rule_2', ['formats' => implode(', ', array_map(fn (string $extension): string => '.'.$extension, $allowedExtensions))]) }}</span>
                    </li>
                    <li class="list-group-item">
                        <span class="fw-bold">3.</span>
                        <span>{{ __('datasets.create_rule_3') }}</span>
                    </li>
                    <li class="list-group-item">
                        <span class="fw-bold">4.</span>
                        <span>{{ __('datasets.create_rule_4') }}</span>
                    </li>
                </ul>
            </x-card>
        </div>
    </div>
@endsection
