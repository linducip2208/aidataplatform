@extends('layouts.app')

@section('title', 'Glosarium bisnis')

@section('content')
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">{{ __('glossary.header_title') }}</h1>
                <p class="page-subtitle">
                    {{ __('glossary.header_subtitle') }}
                    @if ($version)
                        <x-badge variant="info">{{ __('glossary.version_badge', ['version' => $version]) }}</x-badge>
                    @endif
                </p>
            </div>
        </div>
    </div>

    @unless ($engineAvailable)
        <x-card class="mb-3">
            <div role="status" class="alert alert-warning">
                <x-badge variant="warning">{{ __('glossary.engine_unavailable') }}</x-badge>
                <p class="text-secondary">
                    {{ __('glossary.engine_unavailable_lead') }} <code>fastapi</code>
                    {{ __('glossary.engine_unavailable_trail') }}
                </p>
            </div>
        </x-card>
    @endunless

    <x-card :title="__('glossary.list_title')" :description="__('glossary.list_desc')">
        @if ($metrics === [])
            <x-empty-state
                :title="__('glossary.list_empty_title')"
                :description="__('glossary.list_empty_desc')"
            />
        @else
            <x-table-wrapper :label="__('glossary.list_table_label')">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('glossary.col_metric') }}</th>
                            <th scope="col">{{ __('glossary.col_definition') }}</th>
                            <th scope="col">{{ __('glossary.col_formula') }}</th>
                            <th scope="col">{{ __('glossary.col_source') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($metrics as $metric)
                            <tr>
                                <td>
                                    <div class="fw-bold text-secondary"><code>{{ $metric['name'] ?? '?' }}</code></div>
                                    <div class="small text-secondary">{{ collect((array) ($metric['aliases'] ?? []))->take(4)->implode(', ') }}</div>
                                </td>
                                <td class="small text-secondary">{{ $metric['definition'] ?? '' }}</td>
                                <td><code>{{ $metric['formula'] ?? '' }}</code></td>
                                <td class="small text-secondary">{{ $metric['source'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>
        @endif
    </x-card>
@endsection
