@extends('layouts.guest')

@section('title', 'Mesin AI tidak dapat dihubungi')

@section('content')
    <div class="card card-md">
        <div class="card-body">
            <h1 class="card-title text-danger">Mesin AI tidak dapat dihubungi</h1>

            <div class="alert alert-danger" role="alert">
                <p class="mb-0">{{ $message }}</p>
            </div>

            <p class="small text-secondary">
                Operasi yang gagal: <code>{{ $operation }}</code>.
                Periksa apakah service <code>fastapi</code> berjalan dan
                <code>SERVICE_API_KEY</code> sama di kedua layanan.
            </p>

            <div class="d-flex flex-wrap gap-2 mt-3">
                <a href="{{ route('dashboard') }}"
                   class="btn btn-danger">Kembali ke dashboard</a>
                <a href="{{ route('datasets.index') }}"
                   class="btn btn-outline-danger">Daftar dataset</a>
            </div>
        </div>
    </div>
@endsection
