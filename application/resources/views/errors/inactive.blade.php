@extends('layouts.guest')

@section('content')
    <div class="w-full max-w-lg rounded-xl border border-slate-200 bg-white p-6">
        <h1 class="text-lg font-semibold text-slate-900">Akun dinonaktifkan</h1>

        <p class="mt-2 text-sm text-slate-600">
            Akun ini sudah dinonaktifkan, jadi tidak dapat dipakai lagi. Hubungi administrator
            untuk mengaktifkannya kembali.
        </p>

        <a href="{{ route('login') }}"
           class="mt-6 inline-block rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
            Kembali ke halaman masuk
        </a>
    </div>
@endsection
