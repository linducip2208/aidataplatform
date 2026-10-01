@extends('layouts.app')

@section('title', 'Kumpulan data')

@section('content')
    @php
        $typeLabels = config('ai_engine.dataset_type_labels', []);
    @endphp

    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">{{ __('datasets.index_header_title') }}</h1>
                <div class="page-subtitle">{{ __('datasets.index_header_subtitle') }}</div>
            </div>
            @if (auth()->user()->isAnalyst())
                <div class="col-auto ms-auto">
                    <a
                        href="{{ route('datasets.create') }}"
                        class="btn btn-primary"
                    ><x-icon name="plus" />{{ __('datasets.index_upload') }}</a>
                </div>
            @endif
        </div>
    </div>

    <x-card class="mb-3" :title="__('datasets.index_filter_title')" description="{{ __('datasets.index_filter_desc') }}">
        <form method="GET" action="{{ route('datasets.index') }}" class="row row-cards">
            <div class="col-sm-6 col-lg-3">
                <x-field :label="__('datasets.index_search_label')" for="q" hint="{{ __('datasets.index_search_hint') }}">
                    <input
                        id="q"
                        name="q"
                        type="search"
                        value="{{ $filters['q'] ?? '' }}"
                        placeholder="{{ __('datasets.index_search_placeholder') }}"
                        class="form-control"
                    >
                </x-field>
            </div>

            <div class="col-sm-6 col-lg-3">
                <x-field :label="__('datasets.index_type_label')" for="dataset_type">
                    <select
                        id="dataset_type"
                        name="dataset_type"
                        class="form-select"
                    >
                        <option value="">{{ __('datasets.index_type_all') }}</option>
                        @foreach ($datasetTypes as $type)
                            <option value="{{ $type }}" @selected(($filters['dataset_type'] ?? '') === $type)>{{ $typeLabels[$type] ?? $type }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>

            <div class="col-sm-6 col-lg-3">
                <x-field :label="__('datasets.index_status_label')" for="status">
                    <select
                        id="status"
                        name="status"
                        class="form-select"
                    >
                        <option value="">{{ __('datasets.index_status_all') }}</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->localizedLabel() }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>

            <div class="col-sm-6 col-lg-3">
                <div class="d-flex align-items-end gap-2">
                    <button
                        type="submit"
                        class="btn btn-primary"
                    >{{ __('datasets.index_apply') }}</button>
                    <a
                        href="{{ route('datasets.index') }}"
                        class="btn"
                    >{{ __('datasets.index_reset') }}</a>
                </div>
            </div>
        </form>
    </x-card>

    <x-card :title="__('datasets.index_list_title')" description="{{ __('datasets.index_list_desc', ['count' => number_format($datasets->total(), 0, ',', '.')]) }}">
        @if ($datasets->isEmpty())
            <x-empty-state
                :title="__('datasets.index_empty_title')"
                description="{{ __('datasets.index_empty_desc') }}"
            />
        @else
            <x-table-wrapper :label="__('datasets.index_list_title')">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('datasets.index_th_dataset') }}</th>
                            <th scope="col">{{ __('datasets.index_th_type') }}</th>
                            <th scope="col">{{ __('datasets.index_th_status') }}</th>
                            <th scope="col">{{ __('datasets.index_th_quality') }}</th>
                            <th scope="col" class="text-end">{{ __('datasets.index_th_rows') }}</th>
                            <th scope="col" class="text-end">{{ __('datasets.index_th_size') }}</th>
                            <th scope="col" class="text-end">{{ __('datasets.index_th_uploaded') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($datasets as $dataset)
                            @php
                                $status = $dataset->status();
                                $verdict = $dataset->qualityVerdict();
                            @endphp
                            <tr>
                                <td>
                                    <a
                                        href="{{ route('datasets.show', $dataset) }}"
                                    >{{ $dataset->name }}</a>
                                    <span class="d-block text-secondary small">{{ $dataset->source_filename ?: __('datasets.index_no_file') }}</span>
                                </td>
                                <td>{{ $typeLabels[$dataset->dataset_type] ?? $dataset->dataset_type }}</td>
                                <td><x-badge :class="$status->badgeClass()">{{ $status->localizedLabel() }}</x-badge></td>
                                <td>
                                    @if ($dataset->quality_score !== null)
                                        {{-- The score is stored 0-1 while Number::percentage()
                                             expects percentage points, hence the x100. --}}
                                        <span class="tabular-nums">{{ \Illuminate\Support\Number::percentage((float) $dataset->quality_score * 100, precision: 1, locale: 'id') }}</span>
                                        <span class="d-block">
                                            <x-badge :class="$verdict ? $verdict->badgeClass() : 'badge-neutral'">
                                                {{ $verdict ? $verdict->localizedLabel() : __('datasets.index_verdict_unknown') }}
                                            </x-badge>
                                        </span>
                                    @else
                                        <x-badge variant="neutral">{{ __('datasets.index_not_rated') }}</x-badge>
                                    @endif
                                </td>
                                <td class="text-end tabular-nums">{{ number_format((int) $dataset->row_count, 0, ',', '.') }}</td>
                                <td class="text-end tabular-nums">{{ $dataset->sizeForHumans() }}</td>
                                <td class="text-end">
                                    @if ($dataset->created_at)
                                        <span class="text-secondary small">{{ $dataset->created_at->locale('id')->translatedFormat('d M Y') }}</span>
                                    @else
                                        <span class="text-secondary small">{{ __('datasets.index_uploaded_unknown') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>

            <div class="mt-3">
                {{ $datasets->links() }}
            </div>
        @endif
    </x-card>
@endsection
