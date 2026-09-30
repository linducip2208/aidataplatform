@extends('layouts.app')

@section('title', 'Basis pengetahuan')

@section('content')
    @php
        $visibilityVariants = ['public' => 'success', 'internal' => 'info', 'confidential' => 'warning', 'private' => 'danger'];
        $visibilityLabels = ['public' => 'Publik', 'internal' => 'Internal', 'confidential' => 'Rahasia', 'private' => 'Pribadi'];
        $canWrite = auth()->user()->isAnalyst();
    @endphp

    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">Basis pengetahuan</h1>
                <p class="page-subtitle">
                    Dokumen yang diindeks mesin RAG beserta audiensnya. Dokumen pribadi hanya terlihat oleh pemiliknya.
                </p>
            </div>
        </div>
    </div>

    @unless ($engineAvailable)
        <x-card class="mb-3">
            <div role="status" class="alert alert-warning">
                <x-badge variant="warning">Mesin AI tidak tersedia</x-badge>
                <p class="text-secondary">
                    Daftar dokumen belum dapat dimuat. Periksa layanan <code>fastapi</code>
                    lalu muat ulang halaman ini.
                </p>
            </div>
        </x-card>
    @endunless

    <x-card title="Dokumen terindeks" description="Terbaru lebih dulu.">
        @if ($documents === [])
            <x-empty-state
                title="Belum ada dokumen"
                description="Indeks dokumen pertama melalui formulir di bawah."
            />
        @else
            <x-table-wrapper label="Dokumen terindeks">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">Dokumen</th>
                            <th scope="col">Sumber</th>
                            <th scope="col">Visibilitas</th>
                            <th scope="col" class="text-end">Chunk</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($documents as $document)
                            @php $visibility = strtolower((string) ($document['visibility'] ?? 'public')); @endphp
                            <tr>
                                <td>
                                    <div class="fw-bold text-secondary">{{ $document['title'] ?? 'Tanpa judul' }}</div>
                                    <div class="small text-secondary">ID {{ $document['id'] ?? '?' }} &middot; {{ $document['doc_type'] ?? 'txt' }}</div>
                                </td>
                                <td class="small text-secondary">{{ $document['source'] ?? '?' }}</td>
                                <td><x-badge variant="{{ $visibilityVariants[$visibility] ?? 'neutral' }}">{{ $visibilityLabels[$visibility] ?? $visibility }}</x-badge></td>
                                <td class="text-end">{{ number_format((int) ($document['n_chunks'] ?? 0), 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>
        @endif
    </x-card>

    @if ($canWrite)
        <x-card title="Indeks dokumen" description="Teks dipecah, di-embedding, dan siap ditanya lewat RAG.">
            <form method="POST" action="{{ route('knowledge.store') }}" class="row row-cards">
                @csrf
                <div class="col-md-6">
                    <x-field label="Judul" for="doc_title" name="title" required>
                        <input
                            id="doc_title"
                            name="title"
                            type="text"
                            value="{{ old('title') }}"
                            required
                            maxlength="500"
                            placeholder="mis. Panduan refund"
                            @error('title') aria-invalid="true" @enderror
                            class="form-control"
                        >
                    </x-field>
                </div>
                <div class="col-md-6">
                    <x-field label="Visibilitas" for="doc_visibility" name="visibility" hint="Pribadi berarti hanya Anda.">
                        <select id="doc_visibility" name="visibility" @error('visibility') aria-invalid="true" @enderror class="form-select">
                            <option value="">Publik (bawaan)</option>
                            @foreach (['public' => 'Publik', 'internal' => 'Internal', 'confidential' => 'Rahasia', 'private' => 'Pribadi'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('visibility') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-field>
                </div>
                <div class="col-12">
                    <x-field label="Isi dokumen" for="doc_content" name="content" required>
                        <textarea
                            id="doc_content"
                            name="content"
                            rows="6"
                            required
                            @error('content') aria-invalid="true" @enderror
                            class="form-control"
                        >{{ old('content') }}</textarea>
                    </x-field>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">Indeks dokumen</button>
                </div>
            </form>
        </x-card>
    @endif
@endsection
