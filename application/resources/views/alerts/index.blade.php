@extends('layouts.app')

@section('title', 'Pusat peringatan')

@section('content')
    @php
        $severityVariants = ['critical' => 'danger', 'high' => 'warning', 'medium' => 'info', 'low' => 'neutral'];
        $severityLabels = ['critical' => __('alerts.severity_critical'), 'high' => __('alerts.severity_high'), 'medium' => __('alerts.severity_medium'), 'low' => __('alerts.severity_low')];
        $statusVariants = ['open' => 'info', 'acknowledged' => 'warning', 'resolved' => 'success'];
        $statusLabels = ['open' => __('alerts.status_open'), 'acknowledged' => __('alerts.status_acknowledged'), 'resolved' => __('alerts.status_resolved')];
        $canWrite = auth()->user()->isAnalyst();
        $metricNames = collect($metrics)->map(fn ($metric): string => is_array($metric) ? (string) ($metric['name'] ?? '') : (string) $metric)->filter()->unique()->sort()->values();
    @endphp

    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">{{ __('alerts.header_title') }}</h1>
                <p class="page-subtitle">
                    {{ __('alerts.header_subtitle') }}
                </p>
            </div>
        </div>
    </div>

    @unless ($engineAvailable)
        <x-card class="mb-3">
            <div role="status" class="alert alert-warning">
                <x-badge variant="warning">{{ __('alerts.engine_unavailable') }}</x-badge>
                <p class="text-secondary">
                    {{ __('alerts.engine_unavailable_lead') }} <code>fastapi</code>
                    {{ __('alerts.engine_unavailable_trail') }}
                </p>
            </div>
        </x-card>
    @endunless

    <x-card :title="__('alerts.filter_title')" :description="__('alerts.filter_desc')">
        <form method="GET" action="{{ route('alerts.index') }}" class="row row-cards">
            <div class="col-md-6">
                <x-field :label="__('alerts.field_status')" for="status">
                    <select id="status" name="status" class="form-select">
                        <option value="">{{ __('alerts.status_all') }}</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>

            <div class="col-md-6">
                <x-field :label="__('alerts.field_rule_id')" for="rule_id" :hint="__('alerts.field_rule_id_hint')">
                    <input
                        id="rule_id"
                        name="rule_id"
                        type="number"
                        min="1"
                        value="{{ $filters['rule_id'] ?? '' }}"
                        placeholder="{{ __('alerts.field_rule_id_placeholder') }}"
                        @error('rule_id') aria-invalid="true" @enderror
                        class="form-control"
                    >
                </x-field>
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary">{{ __('alerts.apply') }}</button>
                <a href="{{ route('alerts.index') }}" class="btn">{{ __('alerts.reset') }}</a>
            </div>
        </form>
    </x-card>

    <x-card :title="__('alerts.list_title')" :description="__('alerts.list_desc')">
        @if ($alerts === [])
            <x-empty-state
                :title="__('alerts.list_empty_title')"
                :description="__('alerts.list_empty_desc')"
            />
        @else
            <x-table-wrapper :label="__('alerts.list_table_label')">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('alerts.col_alert') }}</th>
                            <th scope="col">{{ __('alerts.col_severity') }}</th>
                            <th scope="col">{{ __('alerts.col_status') }}</th>
                            <th scope="col" class="text-end">{{ __('alerts.col_triggered') }}</th>
                            <th scope="col"><span class="visually-hidden">{{ __('alerts.col_actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($alerts as $alert)
                            @php
                                $severity = strtolower((string) ($alert['severity'] ?? 'low'));
                                $status = strtolower((string) ($alert['status'] ?? 'open'));
                            @endphp
                            <tr>
                                <td>
                                    <div class="fw-bold text-secondary">{{ $alert['message'] ?? __('alerts.alert_fallback', ['id' => $alert['id'] ?? '?']) }}</div>
                                    <div class="small text-secondary">{{ __('alerts.rule_ref', ['id' => $alert['rule_id'] ?? '?']) }}</div>
                                </td>
                                <td><x-badge variant="{{ $severityVariants[$severity] ?? 'neutral' }}">{{ $severityLabels[$severity] ?? $severity }}</x-badge></td>
                                <td><x-badge variant="{{ $statusVariants[$status] ?? 'neutral' }}">{{ $statusLabels[$status] ?? $status }}</x-badge></td>
                                <td class="text-end">
                                    <span class="small text-secondary">{{ $alert['triggered_at'] ?? __('alerts.triggered_unknown') }}</span>
                                </td>
                                <td class="text-end">
                                    @if ($canWrite && $status !== 'resolved' && isset($alert['id']))
                                        <form method="POST" action="{{ route('alerts.ack', ['id' => (int) $alert['id']]) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm">{{ __('alerts.ack') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>
        @endif
    </x-card>

    <x-card :title="__('alerts.rules_title')" :description="__('alerts.rules_desc')">
        @if ($rules === [])
            <x-empty-state
                :title="__('alerts.rules_empty_title')"
                :description="__('alerts.rules_empty_desc')"
            />
        @else
            <x-table-wrapper :label="__('alerts.rules_table_label')">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('alerts.col_rule') }}</th>
                            <th scope="col">{{ __('alerts.col_metric') }}</th>
                            <th scope="col" class="text-end">{{ __('alerts.col_threshold') }}</th>
                            <th scope="col">{{ __('alerts.col_active') }}</th>
                            @if ($canWrite)
                                <th scope="col"><span class="visually-hidden">{{ __('alerts.col_actions') }}</span></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rules as $rule)
                            <tr>
                                <td>
                                    <div class="fw-bold text-secondary">{{ $rule['name'] ?? __('alerts.rule_ref', ['id' => $rule['id'] ?? '?']) }}</div>
                                    <div class="small text-secondary">{{ __('alerts.rule_id_ref', ['id' => $rule['id'] ?? '?']) }}</div>
                                </td>
                                <td><code>{{ $rule['metric'] ?? '?' }} {{ $rule['operator'] ?? '' }}</code></td>
                                <td class="text-end">{{ $rule['threshold'] ?? '?' }}</td>
                                <td>
                                    <x-badge variant="{{ ! empty($rule['is_active']) ? 'success' : 'neutral' }}">
                                        {{ ! empty($rule['is_active']) ? __('alerts.active') : __('alerts.inactive') }}
                                    </x-badge>
                                </td>
                                @if ($canWrite && isset($rule['id']))
                                    <td class="text-end">
                                        <form method="POST" action="{{ route('alerts.rules.toggle', ['id' => (int) $rule['id']]) }}">
                                            @csrf
                                            <input type="hidden" name="is_active" value="{{ empty($rule['is_active']) ? '1' : '0' }}">
                                            <button type="submit" class="btn btn-sm">{{ empty($rule['is_active']) ? __('alerts.activate') : __('alerts.deactivate') }}</button>
                                        </form>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>
        @endif
    </x-card>

    @if ($canWrite)
        <x-card :title="__('alerts.create_title')" :description="__('alerts.create_desc')">
            <form method="POST" action="{{ route('alerts.rules.store') }}" class="row row-cards">
                @csrf

                <div class="col-md-6">
                    <x-field :label="__('alerts.field_name')" for="name" name="name" required>
                        <input
                            id="name"
                            name="name"
                            type="text"
                            value="{{ old('name') }}"
                            required
                            maxlength="128"
                            placeholder="{{ __('alerts.field_name_placeholder') }}"
                            @error('name') aria-invalid="true" @enderror
                            class="form-control"
                        >
                    </x-field>
                </div>

                <div class="col-md-6">
                    <x-field :label="__('alerts.field_metric')" for="metric" name="metric" required>
                        <select id="metric" name="metric" required @error('metric') aria-invalid="true" @enderror class="form-select">
                            <option value="">{{ __('alerts.metric_placeholder') }}</option>
                            @foreach ($metricNames as $metric)
                                <option value="{{ $metric }}" @selected(old('metric') === $metric)>{{ $metric }}</option>
                            @endforeach
                        </select>
                    </x-field>
                </div>

                <div class="col-md-6">
                    <x-field :label="__('alerts.field_operator')" for="operator" name="operator" required :hint="__('alerts.field_operator_hint')">
                        <input
                            id="operator"
                            name="operator"
                            type="text"
                            value="{{ old('operator') }}"
                            required
                            maxlength="16"
                            placeholder="{{ __('alerts.field_operator_placeholder') }}"
                            @error('operator') aria-invalid="true" @enderror
                            class="form-control"
                        >
                    </x-field>
                </div>

                <div class="col-md-6">
                    <x-field :label="__('alerts.field_threshold')" for="threshold" name="threshold" required>
                        <input
                            id="threshold"
                            name="threshold"
                            type="number"
                            step="any"
                            value="{{ old('threshold') }}"
                            required
                            @error('threshold') aria-invalid="true" @enderror
                            class="form-control"
                        >
                    </x-field>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary">{{ __('alerts.create_submit') }}</button>
                </div>
            </form>
        </x-card>
    @endif
@endsection
