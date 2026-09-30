@extends('layouts.guest')

@section('title', 'Akun dinonaktifkan')

@section('content')
    <div class="card card-md">
        <div class="card-body">
            <h1 class="card-title">{{ __('errors.inactive_title') }}</h1>

            <p class="text-secondary">
                {{ __('errors.inactive_body') }}
            </p>

            <a href="{{ route('login') }}"
               class="btn btn-primary mt-3">
                {{ __('errors.inactive_back') }}
            </a>
        </div>
    </div>
@endsection
