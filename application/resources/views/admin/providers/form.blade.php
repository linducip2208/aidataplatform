@extends('layouts.app')

@section('title', $provider->exists ? 'Ubah provider' : 'Tambah provider')

@section('content')
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <nav aria-label="Remah roti" class="mb-2">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('admin.providers.index') }}">Provider AI</a></li>
                        <li class="breadcrumb-item active" aria-current="page">{{ $provider->exists ? 'Ubah' : 'Tambah' }}</li>
                    </ol>
                </nav>
                <h1 class="page-title">{{ $provider->exists ? 'Ubah provider' : 'Tambah provider' }}</h1>
                <p class="page-subtitle">Metadata tersimpan di database. API key hanya diminta saat uji/terbit.</p>
            </div>
        </div>
    </div>

    <x-card title="Detail provider">
        <form method="POST" action="{{ $action }}" class="row row-cards">
            @csrf
            @if ($method !== 'POST')
                @method($method)
            @endif

            <div class="col-md-6">
                <x-field label="Nama" for="provider_name" name="name" required>
                    <input id="provider_name" name="name" type="text" value="{{ old('name', $provider->name) }}" required maxlength="128" placeholder="mis. OpenRouter utama" @error('name') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>

            <div class="col-md-6">
                <x-field label="Tipe provider" for="provider_type" name="provider_type" required>
                    <select id="provider_type" name="provider_type" required @error('provider_type') aria-invalid="true" @enderror class="form-select">
                        @foreach (\App\Models\AiProvider::TYPES as $value => $label)
                            <option value="{{ $value }}" @selected(old('provider_type', $provider->provider_type) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>

            <div class="col-12">
                <x-field label="Base URL" for="provider_base_url" name="base_url" required hint="https saja. Contoh Ollama lokal: http://127.0.0.1:11434/v1.">
                    <input id="provider_base_url" name="base_url" type="url" value="{{ old('base_url', $provider->base_url) }}" required maxlength="512" placeholder="https://openrouter.ai/api/v1" @error('base_url') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>

            <div class="col-md-6">
                <x-field label="Model" for="provider_model" name="model" required>
                    <input id="provider_model" name="model" type="text" value="{{ old('model', $provider->model) }}" required maxlength="256" placeholder="mis. anthropic/claude-3.5-sonnet" @error('model') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>

            <div class="col-md-6">
                <x-field label="Model embedding" for="provider_embedding" name="embedding_model" hint="Opsional.">
                    <input id="provider_embedding" name="embedding_model" type="text" value="{{ old('embedding_model', $provider->embedding_model) }}" maxlength="256" @error('embedding_model') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>

            <div class="col-12">
                <x-field label="Kapabilitas" for="provider_capabilities" name="capabilities" hint="Pisahkan koma, mis. chat, sql, embedding.">
                    <input id="provider_capabilities" name="capabilities" type="text" value="{{ old('capabilities', is_array($provider->capabilities) ? implode(', ', $provider->capabilities) : '') }}" maxlength="2000" @error('capabilities') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>

            <div class="col-md-4">
                <x-field label="Harga input /1jt token" for="provider_in_price" name="input_price_per_million">
                    <input id="provider_in_price" name="input_price_per_million" type="number" step="any" min="0" value="{{ old('input_price_per_million', $provider->input_price_per_million) }}" @error('input_price_per_million') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>

            <div class="col-md-4">
                <x-field label="Harga output /1jt token" for="provider_out_price" name="output_price_per_million">
                    <input id="provider_out_price" name="output_price_per_million" type="number" step="any" min="0" value="{{ old('output_price_per_million', $provider->output_price_per_million) }}" @error('output_price_per_million') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>

            <div class="col-md-4">
                <x-field label="Prioritas" for="provider_priority" name="priority" hint="Lebih kecil = lebih utama.">
                    <input id="provider_priority" name="priority" type="number" min="0" max="100000" value="{{ old('priority', $provider->priority ?? 100) }}" @error('priority') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary">{{ $provider->exists ? 'Simpan' : 'Daftarkan' }}</button>
                <a href="{{ route('admin.providers.index') }}" class="btn">Batal</a>
            </div>
        </form>
    </x-card>
@endsection
