@extends('layouts.app')

@section('title', 'Kualitas data')

@section('content')
    @php
        $typeLabels = config('ai_engine.dataset_type_labels', []);
    @endphp

    <div class="page-header">
        <h1 class="page-title">{{ __('quality.title') }}</h1>
        <p class="page-subtitle">
            {{ __('quality.subtitle') }}
        </p>
    </div>

    <div class="row row-cards">
        <div class="col-sm-6 col-lg-3">
            <x-stat
                :label="\App\Enums\QualityVerdict::Pass->localizedLabel()"
                :value="number_format((int) ($breakdown['pass'] ?? 0), 0, ',', '.')"
                hint="{{ __('quality.pass_hint') }}"
            />
        </div>
        <div class="col-sm-6 col-lg-3">
            <x-stat
                :label="\App\Enums\QualityVerdict::Quarantine->localizedLabel()"
                :value="number_format((int) ($breakdown['quarantine'] ?? 0), 0, ',', '.')"
                hint="{{ __('quality.quarantine_hint') }}"
            />
        </div>
        <div class="col-sm-6 col-lg-3">
            <x-stat label="{{ __('quality.unscored_label') }}" :value="number_format((int) ($breakdown['unscored'] ?? 0), 0, ',', '.')" hint="{{ __('quality.unscored_hint') }}" />
        </div>
        <div class="col-sm-6 col-lg-3">
            <x-stat
                label="{{ __('quality.threshold_label') }}"
                :value="\Illuminate\Support\Number::percentage($threshold * 100, precision: 1, locale: 'id')"
                hint="{{ __('quality.threshold_hint') }}"
            />
        </div>
    </div>

    <x-card title="{{ __('quality.filter_title') }}" description="{{ __('quality.filter_description') }}">
        <form method="GET" action="{{ route('quality.index') }}" class="row row-cards">
            <div class="col-md-6">
                <x-field label="{{ __('quality.field_dataset_type') }}" for="dataset_type">
                    <select
                        id="dataset_type"
                        name="dataset_type"
                        class="form-select"
                    >
                        <option value="">{{ __('quality.all_types') }}</option>
                        @foreach (array_keys($typeLabels) as $type)
                            <option value="{{ $type }}" @selected(($filters['dataset_type'] ?? '') === $type)>{{ $typeLabels[$type] }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>

            <div class="col-md-6">
                <x-field label="{{ __('quality.field_verdict') }}" for="verdict">
                    <select
                        id="verdict"
                        name="verdict"
                        class="form-select"
                    >
                        <option value="">{{ __('quality.all_verdicts') }}</option>
                        @foreach ($verdicts as $verdict)
                            <option value="{{ $verdict->value }}" @selected(($filters['verdict'] ?? '') === $verdict->value)>
                                {{ $verdict->localizedLabel() }}
                            </option>
                        @endforeach
                    </select>
                </x-field>
            </div>

            <div class="col-md-6">
                <button
                    type="submit"
                    class="btn btn-primary"
                >{{ __('quality.apply') }}</button>
                <a
                    href="{{ route('quality.index') }}"
                    class="btn"
                >{{ __('quality.reset') }}</a>
            </div>
        </form>
    </x-card>

    <x-card title="{{ __('quality.results_title') }}" description="{{ __('quality.results_description', ['count' => number_format($datasets->total(), 0, ',', '.')]) }}">
        @if ($datasets->isEmpty())
            <x-empty-state
                title="{{ __('quality.results_empty_title') }}"
                description="{{ __('quality.results_empty_description') }}"
            >
                <x-slot:action>
                    <a
                        href="{{ route('datasets.index') }}"
                        class="btn"
                    >{{ __('quality.view_datasets') }}</a>
                </x-slot:action>
            </x-empty-state>
        @else
            <x-table-wrapper label="{{ __('quality.table_label') }}">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('quality.col_dataset') }}</th>
                            <th scope="col">{{ __('quality.col_type') }}</th>
                            <th scope="col" class="text-end">{{ __('quality.col_score') }}</th>
                            <th scope="col">{{ __('quality.col_verdict') }}</th>
                            <th scope="col">{{ __('quality.col_flow_status') }}</th>
                            <th scope="col" class="text-end">{{ __('quality.col_checked') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($datasets as $dataset)
                            @php
                                $verdict = $dataset->qualityVerdict();
                                $status = $dataset->status();
                                $meetsThreshold = $dataset->quality_score !== null && $dataset->quality_score >= $threshold;
                            @endphp
                            <tr>
                                <td>
                                    <a
                                        href="{{ route('datasets.show', $dataset) }}"
                                        class="fw-bold text-secondary"
                                    >{{ $dataset->name }}</a>
                                    <span class="text-secondary">{{ $dataset->source_filename ?: __('quality.no_filename') }}</span>
                                </td>
                                <td>{{ $typeLabels[$dataset->dataset_type] ?? $dataset->dataset_type }}</td>
                                <td class="text-end">
                                    <span class="fw-bold text-secondary">
                                        {{ \Illuminate\Support\Number::percentage((float) $dataset->quality_score * 100, precision: 1, locale: 'id') }}
                                    </span>
                                    <span class="text-secondary">
                                        {{ $meetsThreshold ? __('quality.above_threshold') : __('quality.below_threshold') }}
                                    </span>
                                </td>
                                <td>
                                    <x-badge :class="$verdict ? $verdict->badgeClass() : 'badge-neutral'">
                                        {{ $verdict ? $verdict->localizedLabel() : __('quality.unknown') }}
                                    </x-badge>
                                </td>
                                <td><x-badge :class="$status->badgeClass()">{{ $status->localizedLabel() }}</x-badge></td>
                                <td class="text-end">
                                    @if ($dataset->quality_checked_at)
                                        <span class="text-secondary">{{ $dataset->quality_checked_at->locale('id')->translatedFormat('d M Y H:i') }}</span>
                                    @else
                                        <span class="text-secondary">{{ __('quality.unknown') }}</span>
                                    @endif
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
