@extends('layouts.app')

@section('title', 'Ubah password')

@section('content')
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">Ubah password</h1>
                <div class="page-subtitle">
                    Password baru minimal 8 karakter dan harus memuat huruf serta angka.
                </div>
            </div>
        </div>
    </div>

    <div class="row row-cards">
        <div class="col-lg-8">
            <x-card title="Password akun" description="Masukkan password lama untuk memverifikasi perubahan.">
                <form method="POST" action="{{ route('password.update') }}">
                    @csrf
                    @method('PUT')

                    <x-field label="Password saat ini" for="current_password" name="current_password" required>
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
                        label="Password baru"
                        for="password"
                        name="password"
                        required
                        hint="Minimal 8 karakter, berisi huruf dan angka."
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

                    <x-field label="Konfirmasi password baru" for="password_confirmation" name="password_confirmation" required>
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
                        >Perbarui password</button>
                    </div>
                </form>
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card title="Akun Anda" description="Informasi akun yang sedang digunakan.">
                <dl class="datagrid">
                    <div class="datagrid-item">
                        <dt class="datagrid-title">Nama</dt>
                        <dd class="datagrid-content">{{ $user->name }}</dd>
                    </div>
                    <div class="datagrid-item">
                        <dt class="datagrid-title">Email</dt>
                        <dd class="datagrid-content text-break">{{ $user->email }}</dd>
                    </div>
                    <div class="datagrid-item">
                        <dt class="datagrid-title">Peran</dt>
                        <dd class="datagrid-content">
                            <x-badge variant="info">{{ $user->role()->localizedLabel() }}</x-badge>
                        </dd>
                    </div>
                    <div class="datagrid-item">
                        <dt class="datagrid-title">Login terakhir</dt>
                        <dd class="datagrid-content">
                            {{ $user->last_login_at ? $user->last_login_at->locale('id')->translatedFormat('d M Y H:i') : 'Belum pernah' }}
                        </dd>
                    </div>
                </dl>

                <p class="small text-secondary mt-3 mb-0">
                    Setelah password diubah, gunakan password baru pada login berikutnya di perangkat ini maupun perangkat lain.
                </p>
            </x-card>
        </div>
    </div>
@endsection
