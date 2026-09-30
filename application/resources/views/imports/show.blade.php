@extends('layouts.app')

@section('title', 'Impor '.$dataset->name)

@section('content')
    @php
        $typeLabels = config('ai_engine.dataset_type_labels', []);
        $reportLabels = config('ai_engine.import_job_report_labels', []);

        $status = $dataset->status();
        $jobStatus = strtolower((string) ($job['status'] ?? ''));
        $progress = isset($job['progress']) ? (float) $job['progress'] : null;
        $progress = $progress === null ? null : max(0.0, min(100.0, $progress));

        // The engine reports progress in percentage points already, so it is
        // passed to Number::percentage() unscaled. An unlisted status gets a
        // neutral badge rather than the raw token.
        $jobBadge = $jobStatus === ''
            ? ['label' => 'Belum ada laporan job', 'badge' => 'badge-neutral']
            : (config('ai_engine.import_job_statuses')[$jobStatus]
                ?? ['label' => 'Status tidak dikenal', 'badge' => 'badge-neutral']);
    @endphp

    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <nav aria-label="{{ __('imports.breadcrumb') }}">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item">
                            <a
                                href="{{ route('imports.index') }}"
                            >{{ __('imports.title') }}</a>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ $dataset->name }}</li>
                    </ol>
                </nav>

                <h1 class="page-title">{{ __('imports.show_title') }}</h1>
                <p class="page-subtitle">
                    <x-badge :class="$status->badgeClass()">{{ $status->localizedLabel() }}</x-badge>
                    <span>{{ __('imports.job_id_label', ['id' => number_format((int) $dataset->import_job_id, 0, ',', '.')]) }}</span>
                </p>
            </div>
            <div class="col-auto">
                <a
                    href="{{ route('imports.show', ['dataset' => $dataset, 'refresh' => 1]) }}"
                    class="btn btn-primary"
                >{{ __('imports.refresh') }}</a>
                <a
                    href="{{ route('datasets.show', $dataset) }}"
                    class="btn"
                >{{ __('imports.back_to_dataset') }}</a>
            </div>
        </div>
    </div>

    <div class="row row-cards">
        <div class="col-lg-8">
            <x-card :title="__('imports.progress_title')" description="{{ __('imports.progress_description') }}">
                @if ($progress === null)
                    <x-empty-state
                        :title="__('imports.progress_empty_title')"
                        description="{{ __('imports.progress_empty_description') }}"
                    />
                @else
                    <div>
                        <x-badge :class="$jobBadge['badge']">{{ $jobBadge['label'] }}</x-badge>
                        <span class="fw-bold text-secondary">{{ \Illuminate\Support\Number::percentage($progress, precision: 1, locale: 'id') }}</span>                    </div>

                    <div
                        class="progress"
                        role="progressbar"
                        aria-valuenow="{{ number_format($progress, 1, '.', '') }}"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-label="{{ __('imports.progress_label', ['name' => $dataset->name]) }}"
                    >
                        <div
                            class="progress-bar {{ $progress >= 100 ? 'bg-success' : '' }}"
                            style="width: {{ number_format($progress, 1, '.', '') }}%"
                        ></div>
                    </div>
                @endif
            </x-card>

            <x-card :title="__('imports.rows_title')" description="{{ __('imports.rows_description') }}">
                @if ($job === [])
                    <x-empty-state
                        :title="__('imports.report_unavailable_title')"
                        description="{{ __('imports.report_unavailable_description') }}"
                    />
                @else
                    <dl class="datagrid">
                        <div class="datagrid-item">
                            <dt class="datagrid-title">{{ __('imports.total_rows') }}</dt>
                            <dd class="datagrid-content">{{ number_format((int) ($job['total_rows'] ?? 0), 0, ',', '.') }}</dd>
                        </div>
                        <div class="datagrid-item">
                            <dt class="datagrid-title">{{ __('imports.processed_rows') }}</dt>
                            <dd class="datagrid-content">{{ number_format((int) ($job['processed_rows'] ?? 0), 0, ',', '.') }}</dd>
                        </div>
                        <div class="datagrid-item">
                            <dt class="datagrid-title">{{ __('imports.success_rows') }}</dt>
                            <dd class="datagrid-content">
                                {{ number_format(max(0, (int) ($job['processed_rows'] ?? 0) - (int) ($job['error_rows'] ?? 0)), 0, ',', '.') }}
                            </dd>
                        </div>
                        <div class="datagrid-item">
                            <dt class="datagrid-title">{{ __('imports.error_rows') }}</dt>
                            <dd class="datagrid-content">{{ number_format((int) ($job['error_rows'] ?? 0), 0, ',', '.') }}</dd>
                        </div>
                    </dl>
                @endif
            </x-card>

            <x-card :title="__('imports.engine_report_title')" description="{{ __('imports.engine_report_description') }}">
                @if (! is_array($job['report'] ?? null) || ($job['report'] ?? []) === [])
                    <x-empty-state
                        :title="__('imports.report_empty_title')"
                        description="{{ __('imports.report_empty_description') }}"
                    />
                @else
                    <dl class="datagrid">
                        @foreach ((array) $job['report'] as $key => $value)
                            <div class="datagrid-item">
                                <dt class="datagrid-title">{{ $reportLabels[$key] ?? \Illuminate\Support\Str::headline((string) $key) }}</dt>
                                <dd class="datagrid-content text-end">
                                    @php
                                        $reportValue = is_scalar($value) || $value === null
                                            ? (string) ($value ?? '—')
                                            : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                                    @endphp
                                    {{ $reportValue === '' ? '—' : $reportValue }}
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card :title="__('imports.dataset_title')" description="{{ __('imports.dataset_description') }}">
                <dl class="datagrid">
                    <div class="datagrid-item">
                        <dt class="datagrid-title">{{ __('imports.field_name') }}</dt>
                        <dd class="datagrid-content">{{ $dataset->name }}</dd>
                    </div>
                    <div class="datagrid-item">
                        <dt class="datagrid-title">{{ __('imports.field_type') }}</dt>
                        <dd class="datagrid-content">{{ $typeLabels[$dataset->dataset_type] ?? $dataset->dataset_type }}</dd>
                    </div>
                    <div class="datagrid-item">
                        <dt class="datagrid-title">{{ __('imports.field_file') }}</dt>
                        <dd class="datagrid-content">{{ $dataset->source_filename ?: __('imports.unknown') }}</dd>
                    </div>
                    <div class="datagrid-item">
                        <dt class="datagrid-title">{{ __('imports.field_row_count') }}</dt>
                        <dd class="datagrid-content">{{ number_format((int) $dataset->row_count, 0, ',', '.') }}</dd>
                    </div>
                    <div class="datagrid-item">
                        <dt class="datagrid-title">{{ __('imports.field_committed_at') }}</dt>
                        <dd class="datagrid-content">
                            {{ $dataset->committed_at ? $dataset->committed_at->locale('id')->translatedFormat('d M Y H:i') : __('imports.not_committed') }}
                        </dd>
                    </div>
                </dl>
            </x-card>
        </div>
    </div>
@endsection
