@extends('layouts.app')

@section('title', 'Profil organisasi')

@section('content')
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">Profil organisasi</h1>
                <p class="page-subtitle">
                    Nama dan logo tampil di bilah navigasi seluruh aplikasi (white-label satu perusahaan).
                </p>
            </div>
        </div>
    </div>

    <x-card title="Identitas" description="Perubahan langsung terlihat setelah disimpan.">
        <form method="POST" action="{{ route('admin.organization.update') }}" enctype="multipart/form-data" class="row row-cards">
            @csrf
            @method('PUT')

            <div class="col-md-6">
                <x-field label="Nama organisasi" for="org_name" name="name" required>
                    <input
                        id="org_name"
                        name="name"
                        type="text"
                        value="{{ old('name', $organization->name) }}"
                        required
                        maxlength="150"
                        @error('name') aria-invalid="true" @enderror
                        class="form-control"
                    >
                </x-field>
            </div>

            <div class="col-md-6">
                <x-field label="Tagline" for="org_tagline" name="tagline">
                    <input
                        id="org_tagline"
                        name="tagline"
                        type="text"
                        value="{{ old('tagline', $organization->tagline) }}"
                        maxlength="255"
                        placeholder="mis. Platform data perusahaan"
                        @error('tagline') aria-invalid="true" @enderror
                        class="form-control"
                    >
                </x-field>
            </div>

            <div class="col-md-6">
                <x-field
                    label="Logo"
                    for="org_logo"
                    name="logo"
                    hint="PNG, JPG, atau SVG maksimal 1 MB. Dibiarkan kosong untuk memakai inisial."
                >
                    <input
                        id="org_logo"
                        name="logo"
                        type="file"
                        accept=".png,.jpg,.jpeg,.svg"
                        @error('logo') aria-invalid="true" @enderror
                        class="form-control"
                    >
                </x-field>

                @if (is_string($organization->logo_path) && $organization->logo_path !== '')
                    <div class="d-flex align-items-center gap-2 mt-2">
                        <span class="avatar avatar-sm bg-white border" aria-hidden="true">
                            <img src="{{ Storage::url($organization->logo_path) }}" alt="" width="32" height="32">
                        </span>
                        <div class="form-check">
                            <input id="remove_logo" name="remove_logo" type="checkbox" value="1" class="form-check-input">
                            <label for="remove_logo" class="form-check-label">Hapus logo saat ini</label>
                        </div>
                    </div>
                @endif
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary">Simpan profil</button>
            </div>
        </form>
    </x-card>
@endsection
