@extends('layouts.app')

@section('title', 'Pusat keputusan')

@section('content')
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">{{ __('decisions.index_header_title') }}</h1>
                <p class="page-subtitle">
                    {{ __('decisions.index_header_subtitle') }}
                </p>
            </div>
        </div>
    </div>

    @unless ($engineAvailable)
        <x-card class="mb-3">
            <div role="status" class="alert alert-warning">
                <x-badge variant="warning">{{ __('decisions.engine_unavailable') }}</x-badge>
                <p class="text-secondary">
                    {{ __('decisions.engine_unavailable_lead') }} <code>fastapi</code>
                    {{ __('decisions.engine_unavailable_trail') }}
                </p>
            </div>
        </x-card>
    @endunless

    @if (is_array($scenarioResult) && $scenarioResult !== [])
        <x-card class="mb-3" :title="__('decisions.scenario_title')" :description="__('decisions.scenario_desc')">
            @if (! ($scenarioResult['supported'] ?? true))
                <x-empty-state
                    :title="__('decisions.scenario_unsupported_title')"
                    :description="collect((array) ($scenarioResult['reasons'] ?? []))->implode('; ') ?: __('decisions.scenario_unsupported_fallback')"
                />
            @else
                <x-table-wrapper :label="__('decisions.scenario_table_label')">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('decisions.col_measure') }}</th>
                                <th scope="col" class="text-end">{{ __('decisions.col_value') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ((array) ($scenarioResult['deltas'] ?? []) as $key => $value)
                                <tr>
                                    <td class="fw-bold text-secondary">{{ $key }}</td>
                                    <td class="text-end">
                                        {{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-table-wrapper>
            @endif
        </x-card>
    @endif

    <div class="row row-cards">
        <div class="col-md-6">
            <x-card :title="__('decisions.recommend_title')" :description="__('decisions.recommend_desc')">
                @if (auth()->user()->isAnalyst())
                    <form method="POST" action="{{ route('decisions.recommend') }}" class="row row-cards">
                        @csrf
                        <div class="col-md-6">
                            <x-field :label="__('decisions.field_branch')" for="subject_branch" :hint="__('decisions.field_branch_hint')">
                                <input id="subject_branch" name="branch" type="text" maxlength="128" value="{{ old('branch') }}" placeholder="{{ __('decisions.field_branch_placeholder') }}" class="form-control">
                            </x-field>
                        </div>
                        <div class="col-md-6">
                            <x-field :label="__('decisions.field_period')" for="subject_period">
                                <input id="subject_period" name="period" type="text" maxlength="32" value="{{ old('period') }}" placeholder="{{ __('decisions.field_period_placeholder') }}" class="form-control">
                            </x-field>
                        </div>
                        <div class="col-md-6">
                            <x-field :label="__('decisions.field_granularity')" for="subject_granularity">
                                <select id="subject_granularity" name="granularity" class="form-select">
                                    <option value="">{{ __('decisions.granularity_default') }}</option>
                                    @foreach (['daily' => __('decisions.granularity_daily'), 'weekly' => __('decisions.granularity_weekly'), 'monthly' => __('decisions.granularity_monthly')] as $value => $label)
                                        <option value="{{ $value }}" @selected(old('granularity') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </x-field>
                        </div>
                        <div class="col-md-6">
                            <x-field :label="__('decisions.field_horizon')" for="subject_horizon">
                                <input id="subject_horizon" name="horizon" type="number" min="1" max="365" value="{{ old('horizon') }}" class="form-control">
                            </x-field>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">{{ __('decisions.recommend_submit') }}</button>
                        </div>
                    </form>
                @else
                    <p class="text-secondary">{{ __('decisions.read_only') }}</p>
                @endif
            </x-card>
        </div>

        <div class="col-md-6">
            <x-card :title="__('decisions.simulate_title')" :description="__('decisions.simulate_desc')">
                @if (auth()->user()->isAnalyst())
                    <form method="POST" action="{{ route('decisions.scenarios.run') }}" class="row row-cards">
                        @csrf
                        <div class="col-md-6">
                            <x-field :label="__('decisions.field_type')" for="scenario_type" required>
                                <select id="scenario_type" name="type" required class="form-select">
                                    <option value="">{{ __('decisions.type_placeholder') }}</option>
                                    <option value="price_change_pct" @selected(old('type') === 'price_change_pct')>{{ __('decisions.type_price') }}</option>
                                    <option value="inventory_change_pct" @selected(old('type') === 'inventory_change_pct')>{{ __('decisions.type_inventory') }}</option>
                                    <option value="churn_rise_pp" @selected(old('type') === 'churn_rise_pp')>{{ __('decisions.type_churn') }}</option>
                                </select>
                            </x-field>
                        </div>
                        <div class="col-md-6">
                            <x-field :label="__('decisions.field_value')" for="scenario_value" required>
                                <input id="scenario_value" name="value" type="number" step="any" required value="{{ old('value') }}" placeholder="{{ __('decisions.field_value_placeholder') }}" class="form-control">
                            </x-field>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">{{ __('decisions.simulate_submit') }}</button>
                        </div>
                    </form>
                @else
                    <p class="text-secondary">{{ __('decisions.read_only') }}</p>
                @endif
            </x-card>
        </div>
    </div>

    <x-card :title="__('decisions.history_title')" :description="__('decisions.history_desc')">
        @if ($cases === [])
            <x-empty-state
                :title="__('decisions.history_empty_title')"
                :description="__('decisions.history_empty_desc')"
            />
        @else
            <x-table-wrapper :label="__('decisions.history_table_label')">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col" class="text-end">{{ __('decisions.col_id') }}</th>
                            <th scope="col">{{ __('decisions.col_subject') }}</th>
                            <th scope="col">{{ __('decisions.col_status') }}</th>
                            <th scope="col" class="text-end">{{ __('decisions.col_created') }}</th>
                            <th scope="col"><span class="visually-hidden">{{ __('decisions.col_actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cases as $case)
                            <tr>
                                <td class="text-end">{{ $case['id'] ?? '?' }}</td>
                                <td>
                                    <span class="fw-bold text-secondary">{{ $case['subject']['branch'] ?? __('decisions.subject_all_branches') }}</span>
                                    <span class="d-block small text-secondary">{{ $case['subject']['period'] ?? '' }}</span>
                                </td>
                                <td><x-badge variant="info">{{ $case['status'] ?? '?' }}</x-badge></td>
                                <td class="text-end"><span class="small text-secondary">{{ $case['created_at'] ?? '?' }}</span></td>
                                <td class="text-end">
                                    <a href="{{ route('decisions.show', ['id' => (int) ($case['id'] ?? 0)]) }}" class="btn btn-sm">{{ __('decisions.open') }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>
        @endif
    </x-card>
@endsection
