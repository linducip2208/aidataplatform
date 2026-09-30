@extends('layouts.app')

@section('title', 'Profil organisasi')

@section('content')
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">{{ __('admin.org_header_title') }}</h1>
                <p class="page-subtitle">
                    {{ __('admin.org_header_sub') }}
                </p>
            </div>
        </div>
    </div>

    <x-card :title="__('admin.org_card_title')" :description="__('admin.org_card_desc')">
        <form method="POST" action="{{ route('admin.organization.update') }}" enctype="multipart/form-data" class="row row-cards">
            @csrf
            @method('PUT')

            <div class="col-md-6">
                <x-field :label="__('admin.org_name_label')" for="org_name" name="name" required>
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
                <x-field :label="__('admin.org_tagline_label')" for="org_tagline" name="tagline">
                    <input
                        id="org_tagline"
                        name="tagline"
                        type="text"
                        value="{{ old('tagline', $organization->tagline) }}"
                        maxlength="255"
                        placeholder="{{ __('admin.org_tagline_placeholder') }}"
                        @error('tagline') aria-invalid="true" @enderror
                        class="form-control"
                    >
                </x-field>
            </div>

            <div class="col-md-6">
                <x-field
                    :label="__('admin.org_logo_label')"
                    for="org_logo"
                    name="logo"
                    :hint="__('admin.org_logo_hint')"
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
                            <label for="remove_logo" class="form-check-label">{{ __('admin.org_remove_logo') }}</label>
                        </div>
                    </div>
                @endif
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary">{{ __('admin.org_save') }}</button>
            </div>
        </form>
    </x-card>
@endsection
