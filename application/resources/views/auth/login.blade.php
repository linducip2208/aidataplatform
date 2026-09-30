@extends('layouts.guest')

@section('title', __('auth.sign_in'))

@section('content')
    <div class="card card-md">
        <div class="card-body">
            <div class="mb-3">
                <h1 class="card-title">{{ __('auth.sign_in_to_platform') }}</h1>
                <p class="text-secondary mb-0">{{ __('auth.sign_in_hint') }}</p>
            </div>

            <form method="POST" action="{{ route('login.store') }}">
                @csrf

                <x-field label="{{ __('auth.email') }}" for="email" name="email" required>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        value="{{ old('email') }}"
                        autocomplete="username"
                        required
                        autofocus
                        placeholder="{{ __('auth.email_placeholder') }}"
                        @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                        class="form-control"
                    >
                </x-field>

                <x-field label="{{ __('auth.password') }}" for="password" name="password" required>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        autocomplete="current-password"
                        required
                        placeholder="{{ __('auth.password_placeholder') }}"
                        @error('password') aria-invalid="true" @enderror
                        class="form-control"
                    >
                </x-field>

                <div class="form-check mb-3">
                    <input
                        id="remember"
                        name="remember"
                        type="checkbox"
                        value="1"
                        @checked(old('remember'))
                        class="form-check-input"
                    >
                    <label for="remember" class="form-check-label">{{ __('auth.remember_me') }}</label>
                </div>

                <button
                    type="submit"
                    class="btn btn-primary w-100"
                >{{ __('auth.sign_in') }}</button>
            </form>
        </div>
    </div>

    @if (app()->environment(['local', 'development', 'testing']))
        <div class="alert alert-warning mt-3 mb-0" role="note">
            <p class="fw-bold mb-1">{{ __('auth.demo_accounts') }}</p>
            <p class="small mb-3">{{ __('auth.demo_hint') }}</p>

            <ul class="list-unstyled mb-0 d-grid gap-2">
                <li class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <span>{{ \App\Enums\UserRole::Admin->localizedLabel() }}</span>
                    <code>admin@example.com / Admin123!</code>
                </li>
                <li class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <span>{{ \App\Enums\UserRole::Analyst->localizedLabel() }}</span>
                    <code>analyst@example.com / Analyst123!</code>
                </li>
                <li class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <span>{{ \App\Enums\UserRole::Viewer->localizedLabel() }}</span>
                    <code>viewer@example.com / Viewer123!</code>
                </li>
            </ul>
        </div>
    @endif
@endsection
