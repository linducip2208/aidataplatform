@extends('layouts.app')

@section('title', $provider->exists ? 'Ubah provider' : 'Tambah provider')

@section('content')
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <nav aria-label="{{ __('admin.providers_form_breadcrumb_aria') }}" class="mb-2">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('admin.providers.index') }}">{{ __('admin.providers_form_breadcrumb') }}</a></li>
                        <li class="breadcrumb-item active" aria-current="page">{{ $provider->exists ? __('admin.providers_form_edit_crumb') : __('admin.providers_form_add_crumb') }}</li>
                    </ol>
                </nav>
                <h1 class="page-title">{{ $provider->exists ? __('admin.providers_form_edit_title') : __('admin.providers_form_add_title') }}</h1>
                <p class="page-subtitle">{{ __('admin.providers_form_sub') }}</p>
            </div>
        </div>
    </div>

    <x-card :title="__('admin.providers_form_card_title')">
        <form method="POST" action="{{ $action }}" class="row row-cards">
            @csrf
            @if ($method !== 'POST')
                @method($method)
            @endif

            <div class="col-md-6">
                <x-field :label="__('admin.providers_form_name_label')" for="provider_name" name="name" required>
                    <input id="provider_name" name="name" type="text" value="{{ old('name', $provider->name) }}" required maxlength="128" placeholder="{{ __('admin.providers_form_name_placeholder') }}" @error('name') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>

            <div class="col-md-6">
                <x-field :label="__('admin.providers_form_type_label')" for="provider_type" name="provider_type" required>
                    <select id="provider_type" name="provider_type" required @error('provider_type') aria-invalid="true" @enderror class="form-select">
                        @foreach (\App\Models\AiProvider::TYPES as $value => $label)
                            <option value="{{ $value }}" @selected(old('provider_type', $provider->provider_type) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>

            <div class="col-12">
                <x-field :label="__('admin.providers_form_base_url_label')" for="provider_base_url" name="base_url" required :hint="__('admin.providers_form_base_url_hint')">
                    <input id="provider_base_url" name="base_url" type="url" value="{{ old('base_url', $provider->base_url) }}" required maxlength="512" placeholder="{{ __('admin.providers_form_base_url_placeholder') }}" @error('base_url') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>

            <div class="col-md-6">
                <x-field :label="__('admin.providers_form_model_label')" for="provider_model" name="model" required>
                    <input id="provider_model" name="model" type="text" value="{{ old('model', $provider->model) }}" required maxlength="256" placeholder="{{ __('admin.providers_form_model_placeholder') }}" @error('model') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>

            <div class="col-md-6">
                <x-field :label="__('admin.providers_form_embedding_label')" for="provider_embedding" name="embedding_model" :hint="__('admin.providers_form_embedding_hint')">
                    <input id="provider_embedding" name="embedding_model" type="text" value="{{ old('embedding_model', $provider->embedding_model) }}" maxlength="256" @error('embedding_model') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>

            <div class="col-12">
                <x-field :label="__('admin.providers_form_capabilities_label')" for="provider_capabilities" name="capabilities" :hint="__('admin.providers_form_capabilities_hint')">
                    <input id="provider_capabilities" name="capabilities" type="text" value="{{ old('capabilities', is_array($provider->capabilities) ? implode(', ', $provider->capabilities) : '') }}" maxlength="2000" @error('capabilities') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>

            <div class="col-md-4">
                <x-field :label="__('admin.providers_form_in_price_label')" for="provider_in_price" name="input_price_per_million">
                    <input id="provider_in_price" name="input_price_per_million" type="number" step="any" min="0" value="{{ old('input_price_per_million', $provider->input_price_per_million) }}" @error('input_price_per_million') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>

            <div class="col-md-4">
                <x-field :label="__('admin.providers_form_out_price_label')" for="provider_out_price" name="output_price_per_million">
                    <input id="provider_out_price" name="output_price_per_million" type="number" step="any" min="0" value="{{ old('output_price_per_million', $provider->output_price_per_million) }}" @error('output_price_per_million') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>

            <div class="col-md-4">
                <x-field :label="__('admin.providers_form_priority_label')" for="provider_priority" name="priority" :hint="__('admin.providers_form_priority_hint')">
                    <input id="provider_priority" name="priority" type="number" min="0" max="100000" value="{{ old('priority', $provider->priority ?? 100) }}" @error('priority') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary">{{ $provider->exists ? __('admin.providers_form_save') : __('admin.providers_form_create') }}</button>
                <a href="{{ route('admin.providers.index') }}" class="btn">{{ __('admin.providers_form_cancel') }}</a>
            </div>
        </form>
    </x-card>
@endsection
