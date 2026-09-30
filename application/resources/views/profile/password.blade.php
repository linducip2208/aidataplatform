@extends('layouts.app')

@section('title', 'Ubah password')

@section('content')
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">{{ __('profile.password_header_title') }}</h1>
                <div class="page-subtitle">
                    {{ __('profile.password_header_sub') }}
                </div>
            </div>
        </div>
    </div>

    <div class="row row-cards">
        <div class="col-lg-8">
            <x-card :title="__('profile.password_card_title')" :description="__('profile.password_card_desc')">
                <form method="POST" action="{{ route('password.update') }}">
                    @csrf
                    @method('PUT')

                    <x-field :label="__('profile.password_current_label')" for="current_password" name="current_password" required>
                        <input
                            id="current_password"
                            name="current_password"
                            type="password"
                            autocomplete="current-password"
                            required
                            @error('current_password') aria-invalid="true" @enderror
                            class="form-control"
                        >
                    </x-field>

                    <x-field
                        :label="__('profile.password_new_label')"
                        for="password"
                        name="password"
                        required
                        :hint="__('profile.password_new_hint')"
                    >
                        <input
                            id="password"
                            name="password"
                            type="password"
                            autocomplete="new-password"
                            required
                            @error('password') aria-invalid="true" aria-describedby="password-error" @enderror
                            class="form-control"
                        >
                    </x-field>

                    <x-field :label="__('profile.password_confirm_label')" for="password_confirmation" name="password_confirmation" required>
                        <input
                            id="password_confirmation"
                            name="password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            required
                            class="form-control"
                        >
                    </x-field>

                    <div class="pt-3 mt-3 border-top">
                        <button
                            type="submit"
                            class="btn btn-primary"
                        >{{ __('profile.password_submit') }}</button>
                    </div>
                </form>
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card :title="__('profile.password_account_title')" :description="__('profile.password_account_desc')">
                <dl class="datagrid">
                    <div class="datagrid-item">
                        <dt class="datagrid-title">{{ __('profile.password_name_label') }}</dt>
                        <dd class="datagrid-content">{{ $user->name }}</dd>
                    </div>
                    <div class="datagrid-item">
                        <dt class="datagrid-title">{{ __('profile.password_email_label') }}</dt>
                        <dd class="datagrid-content text-break">{{ $user->email }}</dd>
                    </div>
                    <div class="datagrid-item">
                        <dt class="datagrid-title">{{ __('profile.password_role_label') }}</dt>
                        <dd class="datagrid-content">
                            <x-badge variant="info">{{ $user->role()->localizedLabel() }}</x-badge>
                        </dd>
                    </div>
                    <div class="datagrid-item">
                        <dt class="datagrid-title">{{ __('profile.password_last_login_label') }}</dt>
                        <dd class="datagrid-content">
                            {{ $user->last_login_at ? $user->last_login_at->locale('id')->translatedFormat('d M Y H:i') : __('profile.password_never') }}
                        </dd>
                    </div>
                </dl>

                <p class="small text-secondary mt-3 mb-0">
                    {{ __('profile.password_note') }}
                </p>
            </x-card>
        </div>
    </div>
@endsection
