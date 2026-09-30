@extends('layouts.guest')

@section('title', 'Mesin AI tidak dapat dihubungi')

@section('content')
    <div class="card card-md">
        <div class="card-body">
            <h1 class="card-title text-danger">{{ __('errors.engine_title') }}</h1>

            <div class="alert alert-danger" role="alert">
                <p class="mb-0">{{ $message }}</p>
            </div>

            <p class="small text-secondary">
                {{ __('errors.engine_failed_op') }}: <code>{{ $operation }}</code>.
                {{ __('errors.engine_check') }} <code>fastapi</code> {{ __('errors.engine_running_and') }}
                <code>SERVICE_API_KEY</code> {{ __('errors.engine_same') }}
            </p>

            <div class="d-flex flex-wrap gap-2 mt-3">
                <a href="{{ route('dashboard') }}"
                   class="btn btn-danger">{{ __('errors.engine_back_dashboard') }}</a>
                <a href="{{ route('datasets.index') }}"
                   class="btn btn-outline-danger">{{ __('errors.engine_list_datasets') }}</a>
            </div>
        </div>
    </div>
@endsection
