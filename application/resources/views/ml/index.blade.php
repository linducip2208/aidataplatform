@extends('layouts.app')

@section('title', 'Pembelajaran mesin')

@section('content')
    @php
        $typeLabels = config('ai_engine.model_type_labels', []);

        // One entry per status the registry can report, carrying both the word
        // a user reads and the badge it paints, so the two cannot drift apart.
        $statuses = config('ai_engine.model_version_statuses', []);

        $promoteTargets = array_intersect_key($statuses, array_flip(['PRODUCTION', 'STAGED', 'ARCHIVED']));

        $selectedId = isset($selected['id']) ? (int) $selected['id'] : null;
        $versions = (array) ($selected['versions'] ?? []);
    @endphp

    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">{{ __('ml.header_title') }}</h1>
                <div class="page-subtitle">
                    {{ __('ml.header_subtitle') }}
                </div>
            </div>
        </div>
    </div>

    @if (filled($error))
        <x-card class="mb-3">
            <div role="alert" class="alert alert-danger mb-0">
                <x-badge variant="danger">{{ __('ml.failed') }}</x-badge>
                <span>{{ $error }}</span>
            </div>
        </x-card>
    @endif

    <div class="row row-cards">
        <div class="col-lg-8">
            <x-card :title="__('ml.models_title')" description="{{ __('ml.models_desc') }}">
                @if ($models === [])
                    <x-empty-state
                        :title="__('ml.models_empty_title')"
                        description="{{ __('ml.models_empty_desc') }}"
                    />
                @else
                    <x-table-wrapper :label="__('ml.models_table')">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('ml.th_model') }}</th>
                                    <th scope="col">{{ __('ml.th_type') }}</th>
                                    <th scope="col">{{ __('ml.th_status') }}</th>
                                    <th scope="col" class="text-end">{{ __('ml.th_prod_version') }}</th>
                                    <th scope="col"><span class="visually-hidden">{{ __('ml.th_actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($models as $model)
                                    @php
                                        $statusKey = strtoupper((string) ($model['status'] ?? 'DRAFT'));
                                        $modelId = (int) ($model['id'] ?? 0);
                                    @endphp
                                    <tr @if ($selectedId === $modelId) class="table-active" @endif>
                                        <td>
                                            <span class="fw-medium">{{ $model['name'] ?? __('ml.unnamed') }}</span>
                                            <span class="d-block small text-secondary">ID {{ $modelId }}</span>
                                        </td>
                                        <td>{{ $typeLabels[$model['model_type'] ?? ''] ?? ($model['model_type'] ?? __('ml.unknown')) }}</td>
                                        <td>
                                            <x-badge :class="$statuses[$statusKey]['badge'] ?? 'badge-neutral'">
                                                {{ $statuses[$statusKey]['label'] ?? __('ml.status_unknown') }}
                                            </x-badge>
                                        </td>
                                        <td class="text-end">
                                            {{ ! empty($model['production_version_id']) ? number_format((int) $model['production_version_id'], 0, ',', '.') : __('ml.no_production') }}
                                        </td>
                                        <td class="text-end">
                                            <form method="GET" action="{{ route('ml.index') }}">
                                                <input type="hidden" name="model" value="{{ $modelId }}">
                                                <button
                                                    type="submit"
                                                    class="btn"
                                                >{{ $selectedId === $modelId ? __('ml.opening') : __('ml.view_versions') }}</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-table-wrapper>
                @endif
            </x-card>

            <x-card
                :title="__('ml.versions_title')"
                :description="$selectedId ? __('ml.versions_desc_selected') : __('ml.versions_desc_empty')"
            >
                @if ($versions === [])
                    <x-empty-state
                        :title="__('ml.versions_empty_title')"
                        description="{{ __('ml.versions_empty_desc') }}"
                    />
                @else
                    <x-table-wrapper :label="__('ml.versions_table')">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('ml.th_version') }}</th>
                                    <th scope="col">{{ __('ml.th_status') }}</th>
                                    <th scope="col">{{ __('ml.th_metrics') }}</th>
                                    @if ($canApprove)
                                        <th scope="col"><span class="visually-hidden">{{ __('ml.th_promote') }}</span></th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($versions as $version)
                                    @php
                                        $versionStatus = strtoupper((string) ($version['status'] ?? 'DRAFT'));
                                        $versionId = (int) ($version['id'] ?? 0);
                                        $metrics = (array) ($version['metrics'] ?? []);
                                    @endphp
                                    <tr>
                                        <td>
                                            <span class="fw-medium">{{ $version['version'] ?? 'v'.$versionId }}</span>
                                            <span class="d-block small text-secondary">ID {{ $versionId }}</span>
                                        </td>
                                        <td>
                                            <x-badge :class="$statuses[$versionStatus]['badge'] ?? 'badge-neutral'">
                                                {{ $statuses[$versionStatus]['label'] ?? __('ml.status_unknown') }}
                                            </x-badge>
                                        </td>
                                        <td>
                                            @if ($metrics === [])
                                                <span class="small text-secondary">{{ __('ml.no_metrics') }}</span>
                                            @else
                                                <dl class="datagrid">
                                                    @foreach ($metrics as $metricKey => $metricValue)
                                                        <div class="datagrid-item">
                                                            <dt class="datagrid-title">{{ \Illuminate\Support\Str::headline((string) $metricKey) }}</dt>
                                                            <dd class="datagrid-content">
                                                                @if (is_bool($metricValue))
                                                                    {{ $metricValue ? __('ml.yes') : __('ml.no') }}
                                                                @elseif (is_scalar($metricValue) || $metricValue === null)
                                                                    {{ \Illuminate\Support\Str::limit((string) $metricValue, 40) }}
                                                                @else
                                                                    {{ \Illuminate\Support\Str::limit((string) json_encode($metricValue, JSON_UNESCAPED_UNICODE), 60) }}
                                                                @endif
                                                            </dd>
                                                        </div>
                                                    @endforeach
                                                </dl>
                                            @endif
                                        </td>
                                        @if ($canApprove)
                                            <td>
                                                <form method="POST" action="{{ route('ml.promote', ['modelId' => $selectedId]) }}">
                                                    @csrf
                                                    <input type="hidden" name="version_id" value="{{ $versionId }}">

                                                    <label for="to_status-{{ $versionId }}" class="visually-hidden">{{ __('ml.promote_target_label', ['version' => $version['version'] ?? $versionId]) }}</label>
                                                    <select
                                                        id="to_status-{{ $versionId }}"
                                                        name="to_status"
                                                        class="form-select"
                                                    >
                                                        @foreach ($promoteTargets as $value => $target)
                                                            <option value="{{ $value }}" @selected(old('to_status', 'PRODUCTION') === $value)>{{ $target['label'] }}</option>
                                                        @endforeach
                                                    </select>

                                                    <button
                                                        type="submit"
                                                        class="btn btn-primary w-100 mt-2"
                                                    >{{ __('ml.promote') }}</button>
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
        </div>

        <div class="col-lg-4">
            @if (auth()->user()->isAnalyst())
                <x-card :title="__('ml.train_title')" description="{{ __('ml.train_desc') }}">
                    <form method="POST" action="{{ route('ml.train') }}">
                        @csrf

                        <x-field :label="__('ml.field_model_type')" for="model_type" name="model_type" required>
                            <select
                                id="model_type"
                                name="model_type"
                                required
                                @error('model_type') aria-invalid="true" @enderror
                                class="form-select"
                            >
                                <option value="">{{ __('ml.type_placeholder') }}</option>
                                @foreach ($modelTypes as $type)
                                    <option value="{{ $type }}" @selected(old('model_type') === $type)>{{ $typeLabels[$type] ?? $type }}</option>
                                @endforeach
                            </select>
                        </x-field>

                        <x-field :label="__('ml.field_model_name')" for="name" name="name" required>
                            <input
                                id="name"
                                name="name"
                                type="text"
                                value="{{ old('name') }}"
                                required
                                maxlength="100"
                                placeholder="{{ __('ml.name_placeholder') }}"
                                @error('name') aria-invalid="true" @enderror
                                class="form-control"
                            >
                        </x-field>

                        <x-field
                            :label="__('ml.field_params')"
                            for="params"
                            name="params"
                            hint="{{ __('ml.field_params_hint') }}"
                        >
                            <textarea
                                id="params"
                                name="params"
                                rows="5"
                                spellcheck="false"
                                @error('params') aria-invalid="true" @enderror
                                class="form-control"
                            >{{ old('params', '{}') }}</textarea>
                        </x-field>

                        <button
                            type="submit"
                            class="btn btn-primary w-100"
                        >{{ __('ml.start_training') }}</button>
                    </form>
                </x-card>
            @else
                <x-card :title="__('ml.readonly_title')">
                    <p class="text-secondary mb-0">
                        {{ __('ml.readonly_lead') }} <strong>{{ auth()->user()->role()->localizedLabel() }}</strong> {{ __('ml.readonly_trail', ['admin' => \App\Enums\UserRole::Admin->localizedLabel(), 'analyst' => \App\Enums\UserRole::Analyst->localizedLabel()]) }}
                    </p>
                </x-card>
            @endif

            <x-card :title="__('ml.governance_title')" description="{{ __('ml.governance_desc') }}">
                <ul class="list-unstyled mb-0 text-secondary">
                    <li>{{ __('ml.governance_admin', ['admin' => \App\Enums\UserRole::Admin->localizedLabel()]) }}</li>
                    <li>{{ __('ml.governance_metrics') }}</li>
                    <li>{{ __('ml.governance_production') }}</li>
                </ul>
            </x-card>
        </div>
    </div>

    @php
        $experiments = $experiments ?? [];
        $experimentsError = $experimentsError ?? null;
        $events = $events ?? [];
        $eventsError = $eventsError ?? null;
    @endphp

    <div class="d-grid gap-3 mt-3">
        <x-card
            :title="__('ml.experiments_title')"
            description="{{ __('ml.experiments_desc') }}"
        >
            @if (filled($experimentsError))
                <div role="alert" class="alert alert-danger mb-0">
                    <x-badge variant="danger">{{ __('ml.failed') }}</x-badge>
                    <span>{{ $experimentsError }}</span>
                </div>
            @elseif ($experiments === [])
                <x-empty-state
                    :title="__('ml.experiments_empty_title')"
                    description="{{ __('ml.experiments_empty_desc') }}"
                />
            @else
                <x-table-wrapper :label="__('ml.experiments_table')">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('ml.th_experiment') }}</th>
                                <th scope="col">{{ __('ml.th_type') }}</th>
                                <th scope="col">{{ __('ml.th_status') }}</th>
                                <th scope="col">{{ __('ml.th_split') }}</th>
                                <th scope="col">{{ __('ml.th_validate_metrics') }}</th>
                                @if ($canApprove)
                                    <th scope="col"><span class="visually-hidden">{{ __('ml.th_promote_experiment') }}</span></th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($experiments as $experiment)
                                @php
                                    $experimentId = (int) ($experiment['id'] ?? 0);
                                    $experimentStatus = strtoupper((string) ($experiment['status'] ?? 'PLANNED'));
                                    $splitConfig = (array) ($experiment['split_config'] ?? []);
                                    $splitSizes = (array) ($splitConfig['sizes'] ?? []);
                                    $validateMetrics = (array) (($experiment['metrics']['validate'] ?? []) ?? []);
                                @endphp
                                <tr>
                                    <td>
                                        <span class="fw-medium">{{ $experiment['name'] ?? __('ml.unnamed') }}</span>
                                        <span class="d-block small text-secondary">ID {{ $experimentId }}</span>
                                    </td>
                                    <td>{{ $typeLabels[$experiment['model_type'] ?? ''] ?? ($experiment['model_type'] ?? __('ml.unknown')) }}</td>
                                    <td>
                                        <x-badge :class="$statuses[$experimentStatus]['badge'] ?? 'badge-neutral'">
                                            {{ $statuses[$experimentStatus]['label'] ?? $experimentStatus }}
                                        </x-badge>
                                    </td>
                                    <td class="text-secondary">
                                        {{ $splitConfig['strategy'] ?? __('ml.not_split') }}
                                        @if ($splitSizes !== [])
                                            <span class="d-block small">
                                                {{ __('ml.split_train') }} {{ $splitSizes['train'] ?? 0 }} /
                                                {{ __('ml.split_validate') }} {{ $splitSizes['validate'] ?? 0 }} /
                                                {{ __('ml.split_test') }} {{ $splitSizes['test'] ?? 0 }}
                                            </span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($validateMetrics === [])
                                            <span class="small text-secondary">{{ __('ml.no_metrics') }}</span>
                                        @else
                                            <dl class="datagrid">
                                                @foreach ($validateMetrics as $metricKey => $metricValue)
                                                    <div class="datagrid-item">
                                                        <dt class="datagrid-title">{{ \Illuminate\Support\Str::headline((string) $metricKey) }}</dt>
                                                        <dd class="datagrid-content">
                                                            @if (is_scalar($metricValue) || $metricValue === null)
                                                                {{ \Illuminate\Support\Str::limit((string) $metricValue, 40) }}
                                                            @else
                                                                {{ \Illuminate\Support\Str::limit((string) json_encode($metricValue, JSON_UNESCAPED_UNICODE), 60) }}
                                                            @endif
                                                        </dd>
                                                    </div>
                                                @endforeach
                                            </dl>
                                        @endif
                                    </td>
                                    @if ($canApprove)
                                        <td>
                                            <form method="POST" action="{{ url('/ml/experiments/'.$experimentId.'/promote') }}">
                                                @csrf
                                                <button
                                                    type="submit"
                                                    class="btn btn-primary w-100"
                                                >{{ __('ml.promote_version') }}</button>
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

        @if (isset($selected['id']))
            <x-card
                :title="__('ml.audit_title')"
                description="{{ __('ml.audit_desc') }}"
            >
                @if (filled($eventsError))
                    <div role="alert" class="alert alert-danger mb-0">
                        <x-badge variant="danger">{{ __('ml.failed') }}</x-badge>
                        <span>{{ $eventsError }}</span>
                    </div>
                @elseif ($events === [])
                    <x-empty-state
                        :title="__('ml.audit_empty_title')"
                        description="{{ __('ml.audit_empty_desc') }}"
                    />
                @else
                    <x-table-wrapper :label="__('ml.audit_table')">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('ml.th_time') }}</th>
                                    <th scope="col">{{ __('ml.th_event') }}</th>
                                    <th scope="col">{{ __('ml.th_version') }}</th>
                                    <th scope="col">{{ __('ml.th_change') }}</th>
                                    <th scope="col">{{ __('ml.th_note') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($events as $event)
                                    <tr>
                                        <td class="text-secondary">{{ $event['created_at'] ?? '-' }}</td>
                                        <td>{{ $event['event_type'] ?? '-' }}</td>
                                        <td>{{ $event['version_id'] ?? '-' }}</td>
                                        <td class="text-secondary">{{ $event['from_status'] ?? '' }} &rarr; {{ $event['to_status'] ?? '' }}</td>
                                        <td class="text-secondary">{{ \Illuminate\Support\Str::limit((string) ($event['note'] ?? ''), 80) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-table-wrapper>
                @endif

                @if ($canApprove)
                    <form method="POST" action="{{ url('/ml/'.((int) $selected['id']).'/rollback') }}" class="mt-3">
                        @csrf
                        <div class="row g-2 align-items-end">
                            <div class="col-md-8">
                                <label for="rollback-note" class="form-label">{{ __('ml.rollback_label') }}</label>
                                <input
                                    id="rollback-note"
                                    name="note"
                                    type="text"
                                    maxlength="1024"
                                    placeholder="{{ __('ml.rollback_placeholder') }}"
                                    class="form-control"
                                >
                            </div>
                            <div class="col-md-4">
                                <button
                                    type="submit"
                                    class="btn btn-outline-danger w-100"
                                >{{ __('ml.rollback_submit') }}</button>
                            </div>
                        </div>
                    </form>
                @endif
            </x-card>
        @endif

        @if (auth()->user()->isAnalyst())
            <div class="row row-cards">
                <div class="col-md-6">
                    <x-card :title="__('ml.create_experiment_title')" description="{{ __('ml.create_experiment_desc') }}">
                        <form method="POST" action="{{ url('/ml/experiments') }}">
                            @csrf

                            <x-field :label="__('ml.field_model_type')" for="experiment_model_type" name="model_type" required>
                                <select
                                    id="experiment_model_type"
                                    name="model_type"
                                    required
                                    class="form-select"
                                >
                                    <option value="">{{ __('ml.type_placeholder') }}</option>
                                    @foreach ($modelTypes as $type)
                                        <option value="{{ $type }}">{{ $typeLabels[$type] ?? $type }}</option>
                                    @endforeach
                                </select>
                            </x-field>

                            <x-field :label="__('ml.field_experiment_name')" for="experiment_name" name="name">
                                <input
                                    id="experiment_name"
                                    name="name"
                                    type="text"
                                    maxlength="128"
                                    placeholder="{{ __('ml.experiment_name_placeholder') }}"
                                    class="form-control"
                                >
                            </x-field>

                            <button
                                type="submit"
                                class="btn btn-primary w-100"
                            >{{ __('ml.run_experiment') }}</button>
                        </form>
                    </x-card>
                </div>

                <div class="col-md-6">
                    <x-card :title="__('ml.batch_title')" description="{{ __('ml.batch_desc') }}">
                        <form method="POST" action="{{ url('/ml/batch-predict') }}">
                            @csrf

                            <x-field :label="__('ml.field_model_type')" for="batch_model_type" name="model_type" required>
                                <select
                                    id="batch_model_type"
                                    name="model_type"
                                    required
                                    class="form-select"
                                >
                                    <option value="">{{ __('ml.type_placeholder') }}</option>
                                    @foreach ($modelTypes as $type)
                                        <option value="{{ $type }}">{{ $typeLabels[$type] ?? $type }}</option>
                                    @endforeach
                                </select>
                            </x-field>

                            <x-field :label="__('ml.field_model_name')" for="batch_model_name" name="model_name">
                                <input
                                    id="batch_model_name"
                                    name="model_name"
                                    type="text"
                                    maxlength="128"
                                    placeholder="{{ __('ml.batch_name_placeholder') }}"
                                    class="form-control"
                                >
                            </x-field>

                            <x-field
                                :label="__('ml.field_dataset')"
                                for="batch_dataset"
                                name="dataset"
                                hint="{{ __('ml.field_dataset_hint') }}"
                            >
                                <textarea
                                    id="batch_dataset"
                                    name="dataset"
                                    rows="4"
                                    spellcheck="false"
                                    class="form-control"
                                >[]</textarea>
                            </x-field>

                            <button
                                type="submit"
                                class="btn btn-primary w-100"
                            >{{ __('ml.run_batch') }}</button>
                        </form>
                    </x-card>
                </div>
            </div>
        @endif
    </div>
@endsection
