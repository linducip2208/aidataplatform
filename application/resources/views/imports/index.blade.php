@extends('layouts.app')

@section('title', 'Impor')

@section('content')
    @php
        $typeLabels = config('ai_engine.dataset_type_labels', []);
    @endphp

    <div class="page-header">
        <h1 class="page-title">{{ __('imports.title') }}</h1>
        <p class="page-subtitle">
            {{ __('imports.subtitle') }}
        </p>
    </div>

    <x-card :title="__('imports.jobs_title')" description="{{ __('imports.jobs_description', ['count' => number_format($datasets->total(), 0, ',', '.')]) }}">
        @if ($datasets->isEmpty())
            <x-empty-state
                :title="__('imports.empty_title')"
                description="{{ __('imports.empty_description') }}"
            >
                <x-slot:action>
                    <a
                        href="{{ route('datasets.index') }}"
                        class="btn"
                    >{{ __('imports.view_datasets') }}</a>
                </x-slot:action>
            </x-empty-state>
        @else
            <x-table-wrapper :label="__('imports.table_label')">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('imports.col_dataset') }}</th>
                            <th scope="col">{{ __('imports.col_flow_status') }}</th>
                            <th scope="col" class="text-end">{{ __('imports.col_job_id') }}</th>
                            <th scope="col" class="text-end">{{ __('imports.col_rows') }}</th>
                            <th scope="col" class="text-end">{{ __('imports.col_updated') }}</th>
                            <th scope="col"><span class="visually-hidden">{{ __('imports.col_actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($datasets as $dataset)
                            @php $status = $dataset->status(); @endphp
                            <tr>
                                <td>
                                    <a
                                        href="{{ route('imports.show', $dataset) }}"
                                        class="fw-bold text-secondary"
                                    >{{ $dataset->name }}</a>
                                    <span class="text-secondary">{{ $typeLabels[$dataset->dataset_type] ?? $dataset->dataset_type }}</span>
                                </td>
                                <td><x-badge :class="$status->badgeClass()">{{ $status->localizedLabel() }}</x-badge></td>
                                <td class="text-end">{{ number_format((int) $dataset->import_job_id, 0, ',', '.') }}</td>
                                <td class="text-end">{{ number_format((int) $dataset->row_count, 0, ',', '.') }}</td>
                                <td class="text-end">
                                    @if ($dataset->updated_at)
                                        <span class="text-secondary">{{ $dataset->updated_at->locale('id')->translatedFormat('d M Y H:i') }}</span>
                                    @else
                                        <span class="text-secondary">{{ __('imports.unknown') }}</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a
                                        href="{{ route('imports.show', $dataset) }}"
                                        class="btn"
                                    >{{ __('imports.detail') }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>

            <div>
                {{ $datasets->links() }}
            </div>
        @endif
    </x-card>
@endsection
