@extends('layouts.guest')

@section('title', 'Akun dinonaktifkan')

@section('content')
    <div class="card card-md">
        <div class="card-body">
            <h1 class="card-title">Akun dinonaktifkan</h1>

            <p class="text-secondary">
                Akun ini sudah dinonaktifkan, jadi tidak dapat dipakai lagi. Hubungi administrator
                untuk mengaktifkannya kembali.
            </p>

            <a href="{{ route('login') }}"
               class="btn btn-primary mt-3">
                Kembali ke halaman masuk
            </a>
        </div>
    </div>
@endsection
