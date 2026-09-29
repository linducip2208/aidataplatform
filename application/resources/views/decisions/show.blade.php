@extends('layouts.app')

@section('title', 'Kasus keputusan #'.($case['id'] ?? '?'))

@section('content')
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <nav aria-label="Remah roti" class="mb-2">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('decisions.index') }}">Keputusan</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Kasus #{{ $case['id'] ?? '?' }}</li>
                    </ol>
                </nav>
                <h1 class="page-title">Kasus keputusan #{{ $case['id'] ?? '?' }}</h1>
                <p class="page-subtitle">
                    <x-badge variant="info">{{ $case['status'] ?? '?' }}</x-badge>
                    <span class="ms-2">{{ $case['created_at'] ?? '' }}</span>
                </p>
            </div>
            <div class="col-auto ms-auto">
                <a href="{{ route('decisions.index') }}" class="btn">Kembali ke daftar</a>
            </div>
        </div>
    </div>

    <x-card title="Rekomendasi" description="Setiap rekomendasi membawa bukti, keyakinan, dan dampak yang diharapkan.">
        @if ((($case['recommendations'] ?? []) === []))
            <x-empty-state
                title="Belum ada rekomendasi"
                description="Tidak ada aturan yang terpicu untuk subjek ini; bukti tetap tercatat di bawah."
            />
        @else
            <div class="row row-cards">
                @foreach ((array) $case['recommendations'] as $recommendation)
                    <div class="col-md-6">
                        <x-card title="{{ $recommendation['action'] ?? 'Rekomendasi' }}">
                            <dl class="datagrid">
                                <div class="datagrid-item">
                                    <dt class="datagrid-title">Keyakinan</dt>
                                    <dd class="datagrid-content">{{ number_format((float) ($recommendation['confidence'] ?? 0), 2, ',', '.') }}</dd>
                                </div>
                                <div class="datagrid-item">
                                    <dt class="datagrid-title">Skor</dt>
                                    <dd class="datagrid-content">{{ number_format((float) ($recommendation['score'] ?? 0), 1, ',', '.') }}</dd>
                                </div>
                                <div class="datagrid-item">
                                    <dt class="datagrid-title">Aturan</dt>
                                    <dd class="datagrid-content"><code>{{ $recommendation['rule'] ?? '?' }}</code></dd>
                                </div>
                            </dl>
                            @if (is_array($recommendation['explanation'] ?? null) && ($recommendation['explanation'] ?? []) !== [])
                                <p class="text-secondary small">{{ $recommendation['explanation']['summary'] ?? '' }}</p>
                            @endif
                        </x-card>
                    </div>
                @endforeach
            </div>
        @endif
    </x-card>

    <x-card title="Jejak audit" description="Persetujuan manusia tercatat di sini — AI tidak mengeksekusi sendiri.">
        @if ((($case['audits'] ?? []) === []))
            <x-empty-state title="Belum ada audit" description="Catat keputusan pertama melalui formulir di bawah." />
        @else
            <ul class="list-group list-group-flush">
                @foreach ((array) $case['audits'] as $audit)
                    <li class="list-group-item">
                        <div class="fw-bold">{{ $audit['decision'] ?? '?' }}</div>
                        <div class="small text-secondary">
                            oleh {{ $audit['actor'] ?? '?' }} &middot; {{ $audit['created_at'] ?? '' }}
                        </div>
                        @if (! empty($audit['rationale']))
                            <p class="mt-1 mb-0 text-secondary">{{ $audit['rationale'] }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if (auth()->user()->isAnalyst())
            <form method="POST" action="{{ route('decisions.audit', ['id' => (int) ($case['id'] ?? 0)]) }}" class="row row-cards mt-3">
                @csrf
                <div class="col-md-6">
                    <x-field label="Keputusan" for="audit_decision" name="decision" required>
                        <input
                            id="audit_decision"
                            name="decision"
                            type="text"
                            required
                            maxlength="64"
                            value="{{ old('decision') }}"
                            placeholder="mis. setuju, tolak, ubah"
                            @error('decision') aria-invalid="true" @enderror
                            class="form-control"
                        >
                    </x-field>
                </div>
                <div class="col-md-6">
                    <x-field label="Alasan" for="audit_rationale" name="rationale">
                        <textarea
                            id="audit_rationale"
                            name="rationale"
                            rows="2"
                            maxlength="2000"
                            placeholder="Alasan keputusan manusia"
                            @error('rationale') aria-invalid="true" @enderror
                            class="form-control"
                        >{{ old('rationale') }}</textarea>
                    </x-field>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">Catat keputusan</button>
                </div>
            </form>
        @endif
    </x-card>
@endsection
