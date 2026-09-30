@extends('layouts.app')

@section('title', 'Basis pengetahuan')

@section('content')
    @php
        $visibilityVariants = ['public' => 'success', 'internal' => 'info', 'confidential' => 'warning', 'private' => 'danger'];
        $visibilityLabels = ['public' => __('knowledge.visibility_public'), 'internal' => __('knowledge.visibility_internal'), 'confidential' => __('knowledge.visibility_confidential'), 'private' => __('knowledge.visibility_private')];
        $canWrite = auth()->user()->isAnalyst();
    @endphp

    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">{{ __('knowledge.header_title') }}</h1>
                <p class="page-subtitle">
                    {{ __('knowledge.header_subtitle') }}
                </p>
            </div>
        </div>
    </div>

    @unless ($engineAvailable)
        <x-card class="mb-3">
            <div role="status" class="alert alert-warning">
                <x-badge variant="warning">{{ __('knowledge.engine_unavailable') }}</x-badge>
                <p class="text-secondary">
                    {{ __('knowledge.engine_unavailable_lead') }} <code>fastapi</code>
                    {{ __('knowledge.engine_unavailable_trail') }}
                </p>
            </div>
        </x-card>
    @endunless

    <x-card :title="__('knowledge.list_title')" :description="__('knowledge.list_desc')">
        @if ($documents === [])
            <x-empty-state
                :title="__('knowledge.list_empty_title')"
                :description="__('knowledge.list_empty_desc')"
            />
        @else
            <x-table-wrapper :label="__('knowledge.list_table_label')">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('knowledge.col_document') }}</th>
                            <th scope="col">{{ __('knowledge.col_source') }}</th>
                            <th scope="col">{{ __('knowledge.col_visibility') }}</th>
                            <th scope="col" class="text-end">{{ __('knowledge.col_chunks') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($documents as $document)
                            @php $visibility = strtolower((string) ($document['visibility'] ?? 'public')); @endphp
                            <tr>
                                <td>
                                    <div class="fw-bold text-secondary">{{ $document['title'] ?? __('knowledge.doc_untitled') }}</div>
                                    <div class="small text-secondary">{{ __('knowledge.doc_id_ref', ['id' => $document['id'] ?? '?']) }} &middot; {{ $document['doc_type'] ?? 'txt' }}</div>
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
        <x-card :title="__('knowledge.index_title')" :description="__('knowledge.index_desc')">
            <form method="POST" action="{{ route('knowledge.store') }}" class="row row-cards">
                @csrf
                <div class="col-md-6">
                    <x-field :label="__('knowledge.field_title')" for="doc_title" name="title" required>
                        <input
                            id="doc_title"
                            name="title"
                            type="text"
                            value="{{ old('title') }}"
                            required
                            maxlength="500"
                            placeholder="{{ __('knowledge.field_title_placeholder') }}"
                            @error('title') aria-invalid="true" @enderror
                            class="form-control"
                        >
                    </x-field>
                </div>
                <div class="col-md-6">
                    <x-field :label="__('knowledge.field_visibility')" for="doc_visibility" name="visibility" :hint="__('knowledge.field_visibility_hint')">
                        <select id="doc_visibility" name="visibility" @error('visibility') aria-invalid="true" @enderror class="form-select">
                            <option value="">{{ __('knowledge.visibility_default') }}</option>
                            @foreach (['public' => __('knowledge.visibility_public'), 'internal' => __('knowledge.visibility_internal'), 'confidential' => __('knowledge.visibility_confidential'), 'private' => __('knowledge.visibility_private')] as $value => $label)
                                <option value="{{ $value }}" @selected(old('visibility') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-field>
                </div>
                <div class="col-12">
                    <x-field :label="__('knowledge.field_content')" for="doc_content" name="content" required>
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
                    <button type="submit" class="btn btn-primary">{{ __('knowledge.index_submit') }}</button>
                </div>
            </form>
        </x-card>
    @endif
@endsection
