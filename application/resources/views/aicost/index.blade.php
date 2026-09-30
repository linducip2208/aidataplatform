@extends('layouts.app')

@section('title', 'Biaya AI')

@section('content')
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">{{ __('aicost.header_title') }}</h1>
                <p class="page-subtitle">
                    {{ __('aicost.header_subtitle') }}
                </p>
            </div>
        </div>
    </div>

    @unless ($engineAvailable)
        <x-card class="mb-3">
            <div role="status" class="alert alert-warning">
                <x-badge variant="warning">{{ __('aicost.engine_unavailable') }}</x-badge>
                <p class="text-secondary">
                    {{ __('aicost.engine_unavailable_lead') }} <code>fastapi</code>
                    {{ __('aicost.engine_unavailable_trail') }}
                </p>
            </div>
        </x-card>
    @endunless

    <x-card class="mb-3" :title="__('aicost.range_title')" :description="__('aicost.range_desc')">
        <form method="GET" action="{{ route('ai.usage') }}" class="row row-cards">
            <div class="col-md-6">
                <x-field :label="__('aicost.field_days')" for="days">
                    <select id="days" name="days" class="form-select">
                        @foreach ([7, 30, 90, 365] as $option)
                            <option value="{{ $option }}" @selected($days === $option)>{{ __('aicost.days_option', ['count' => $option]) }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>
            <div class="col-md-6 d-flex align-items-end">
                <button type="submit" class="btn btn-primary">{{ __('aicost.apply') }}</button>
            </div>
        </form>
    </x-card>

    <div class="row row-cards mb-3">
        <div class="col-sm-6 col-lg-3">
            <x-stat :label="__('aicost.stat_turns')" :value="number_format((int) ($totals['turns'] ?? 0), 0, ',', '.')" :hint="__('aicost.stat_turns_hint', ['days' => $days])" />
        </div>
        <div class="col-sm-6 col-lg-3">
            <x-stat :label="__('aicost.stat_tokens')" :value="number_format((int) ($totals['total_tokens'] ?? 0), 0, ',', '.')" :hint="__('aicost.stat_tokens_hint')" />
        </div>
        <div class="col-sm-6 col-lg-3">
            <x-stat
                :label="__('aicost.stat_cost')"
                :value="number_format((float) ($totals['estimated_cost_total'] ?? 0), 4, ',', '.')"
                :hint="__('aicost.stat_cost_hint', ['count' => (int) ($totals['unpriced_rows'] ?? 0)])"
            />
        </div>
        <div class="col-sm-6 col-lg-3">
            <x-stat :label="__('aicost.stat_unpriced')" :value="number_format((int) ($totals['unpriced_rows'] ?? 0), 0, ',', '.')" :hint="__('aicost.stat_unpriced_hint')" />
        </div>
    </div>

    <x-card :title="__('aicost.by_model_title')" :description="__('aicost.by_model_desc')">
        @if ($byModel === [])
            <x-empty-state
                :title="__('aicost.by_model_empty_title')"
                :description="__('aicost.by_model_empty_desc')"
            />
        @else
            <x-table-wrapper :label="__('aicost.by_model_table_label')">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('aicost.col_model') }}</th>
                            <th scope="col" class="text-end">{{ __('aicost.col_turns') }}</th>
                            <th scope="col" class="text-end">{{ __('aicost.col_tokens') }}</th>
                            <th scope="col" class="text-end">{{ __('aicost.col_cost') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($byModel as $row)
                            <tr>
                                <td>
                                    <div class="fw-bold text-secondary">{{ $row['model'] !== '' ? $row['model'] : __('aicost.model_unknown') }}</div>
                                    <div class="small text-secondary">{{ $row['provider'] !== '' ? $row['provider'] : __('aicost.provider_none') }}</div>
                                </td>
                                <td class="text-end">{{ number_format((int) ($row['turns'] ?? 0), 0, ',', '.') }}</td>
                                <td class="text-end">{{ number_format((int) ($row['total_tokens'] ?? 0), 0, ',', '.') }}</td>
                                <td class="text-end">{{ number_format((float) ($row['estimated_cost_total'] ?? 0), 4, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>
        @endif
    </x-card>

    <x-card :title="__('aicost.by_day_title')" :description="__('aicost.by_day_desc')">
        @if ($byDay === [])
            <x-empty-state
                :title="__('aicost.by_day_empty_title')"
                :description="__('aicost.by_day_empty_desc')"
            />
        @else
            <x-table-wrapper :label="__('aicost.by_day_table_label')">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('aicost.col_date') }}</th>
                            <th scope="col" class="text-end">{{ __('aicost.col_turns') }}</th>
                            <th scope="col" class="text-end">{{ __('aicost.col_tokens') }}</th>
                            <th scope="col" class="text-end">{{ __('aicost.col_cost') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($byDay as $row)
                            <tr>
                                <td class="fw-bold text-secondary">{{ $row['day'] ?? '?' }}</td>
                                <td class="text-end">{{ number_format((int) ($row['turns'] ?? 0), 0, ',', '.') }}</td>
                                <td class="text-end">{{ number_format((int) ($row['total_tokens'] ?? 0), 0, ',', '.') }}</td>
                                <td class="text-end">{{ number_format((float) ($row['estimated_cost_total'] ?? 0), 4, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>
        @endif
    </x-card>
@endsection
