@extends('layouts.guest')

@section('title', 'Mesin AI tidak dapat dihubungi')

@section('content')
    <div class="w-full max-w-lg rounded-xl border border-red-200 bg-red-50 p-6">
        <h1 class="text-lg font-semibold text-red-900">Mesin AI tidak dapat dihubungi</h1>

        <p class="mt-2 text-sm text-red-800">{{ $message }}</p>

        <p class="mt-4 text-xs text-red-700">
            Operasi yang gagal: <code class="font-mono">{{ $operation }}</code>.
            Periksa apakah service <code class="font-mono">fastapi</code> berjalan dan
            <code class="font-mono">SERVICE_API_KEY</code> sama di kedua layanan.
        </p>

        <div class="mt-6 flex flex-wrap gap-3">
            <a href="{{ route('dashboard') }}"
               class="rounded-lg bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-700 focus-visible:ring-offset-2">Kembali ke dashboard</a>
            <a href="{{ route('datasets.index') }}"
               class="rounded-lg border border-red-300 px-4 py-2 text-sm font-medium text-red-800 hover:bg-red-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-700 focus-visible:ring-offset-2">Daftar dataset</a>
        </div>
    </div>
@endsection
