@extends('layouts.app')

@section('title', $dataset->name)

@section('content')
    @php
        $canWrite = auth()->user()->isAnalyst();
        $status = $dataset->status();

        $hasJob = (bool) $dataset->import_job_id;
        $columnProfiles = (array) $dataset->columns;
        $columns = $dataset->columnNames();
        $hasProfile = $columns !== [];
        $mappings = (array) $dataset->mappings;
        $hasMappings = $mappings !== [];

        // Session old input is stored flat as `mappings[kolom]`, so dot notation
        // never resolves it. Index the array once instead.
        $oldMappings = (array) old('mappings', []);

        $score = isset($quality['score']) ? (float) $quality['score'] : null;
        $breakdown = (array) ($quality['breakdown'] ?? []);
        $issues = (array) ($quality['issues'] ?? []);
        $passed = (bool) ($quality['passed'] ?? false);
        $isCommitted = $status->value === 'committed' || $dataset->committed_at !== null;

        // The union of every sample row's keys, so a ragged payload cannot shift
        // the value of a column under its own header.
        $sampleColumns = [];
        foreach ($sampleRows as $row) {
            foreach (array_keys((array) $row) as $key) {
                $sampleColumns[$key] = true;
            }
        }
        $sampleColumns = array_keys($sampleColumns);

        $breakdownLabels = [
            'completeness' => __('datasets.show_breakdown_completeness'),
            'uniqueness' => __('datasets.show_breakdown_uniqueness'),
            'validity' => __('datasets.show_breakdown_validity'),
            'consistency' => __('datasets.show_breakdown_consistency'),
        ];

        // Mirrors CANONICAL_FIELDS in ai-engine/app/ingestion/mapper.py. A new
        // canonical field must be added on both sides or the grid will offer a
        // target the ETL never produces.
        $canonicalFields = [
            'sales' => ['transaction_date', 'customer_code', 'customer_name', 'product_code', 'product_name', 'branch_name', 'quantity', 'selling_price', 'discount', 'revenue'],
            'inventory' => ['snapshot_date', 'product_code', 'product_name', 'warehouse_name', 'stock_qty'],
            'purchases' => ['purchase_date', 'supplier_code', 'supplier_name', 'product_code', 'quantity', 'cost'],
            'expenses' => ['expense_date', 'department_name', 'category', 'amount'],
            'customers' => ['customer_code', 'customer_name', 'segment', 'city'],
            'products' => ['product_code', 'product_name', 'category', 'unit', 'cost_price', 'selling_price'],
        ];
        $targets = $canonicalFields[$dataset->dataset_type] ?? $canonicalFields['sales'];

        $steps = [
            ['label' => __('datasets.show_step_preview'), 'done' => $hasProfile, 'ready' => $hasJob],
            ['label' => __('datasets.show_step_mapping'), 'done' => $hasMappings, 'ready' => $hasProfile],
            ['label' => __('datasets.show_step_quality'), 'done' => $score !== null, 'ready' => $hasJob && $hasMappings],
            ['label' => __('datasets.show_step_commit'), 'done' => $isCommitted, 'ready' => $hasJob && $passed && ! $isCommitted],
        ];

        $typeLabels = config('ai_engine.dataset_type_labels', []);
    @endphp

    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <nav aria-label="{{ __('datasets.show_breadcrumb_aria') }}">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item">
                            <a
                                href="{{ route('datasets.index') }}"
                            >{{ __('datasets.show_breadcrumb') }}</a>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ $dataset->name }}</li>
                    </ol>
                </nav>

                <h1 class="page-title">{{ $dataset->name }}</h1>
                <div class="page-subtitle d-flex flex-wrap align-items-center gap-2">
                    <x-badge :class="$status->badgeClass()">{{ $status->localizedLabel() }}</x-badge>
                    <span>{{ $dataset->source_filename ?: __('datasets.show_no_file') }}</span>
                    <span aria-hidden="true">&middot;</span>
                    <span>{{ $dataset->sizeForHumans() }}</span>
                </div>
            </div>

            <div class="col-auto ms-auto">
                <div class="d-flex flex-wrap gap-2">
                    <a
                        href="{{ route('datasets.index') }}"
                        class="btn"
                    >{{ __('datasets.show_back') }}</a>

                    @if ($hasJob)
                        <a
                            href="{{ route('imports.show', $dataset) }}"
                            class="btn"
                        >{{ __('datasets.show_view_job') }}</a>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <ol class="row row-cards" aria-label="{{ __('datasets.show_steps_aria') }}">
        @foreach ($steps as $index => $step)
            <li class="col-sm-6 col-lg-3">
                <div class="card card-body">
                    <p class="text-secondary small fw-bold text-uppercase">{{ __('datasets.show_step_n', ['n' => $index + 1]) }}</p>
                    <p class="fw-bold">{{ $step['label'] }}</p>
                    <p>
                        @if ($step['done'])
                            <x-badge variant="success">{{ __('datasets.show_step_done') }}</x-badge>
                        @elseif ($step['ready'])
                            <x-badge variant="info">{{ __('datasets.show_step_ready') }}</x-badge>
                        @else
                            <x-badge variant="neutral">{{ __('datasets.show_step_waiting') }}</x-badge>
                        @endif
                    </p>
                </div>
            </li>
        @endforeach
    </ol>

    @unless ($canWrite)
        <x-card class="mb-3" :title="__('datasets.show_readonly_title')">
            <p class="text-secondary">
                {{ __('datasets.show_readonly_prefix') }} <strong>{{ auth()->user()->role()->localizedLabel() }}</strong> {{ __('datasets.show_readonly_suffix', ['admin' => \App\Enums\UserRole::Admin->localizedLabel(), 'analyst' => \App\Enums\UserRole::Analyst->localizedLabel()]) }}
            </p>
        </x-card>
    @endunless

    <div class="row row-cards">
        <div class="col-lg-8">
            <x-card
                :title="__('datasets.show_stage1_title')"
                description="{{ __('datasets.show_stage1_desc') }}"
            >
                @if (! $hasJob)
                    <p class="text-secondary">
                        {{ __('datasets.show_stage1_nojob') }}
                    </p>
                @endif

                <dl class="datagrid mb-3">
                    <div class="datagrid-item">
                        <dt class="datagrid-title">{{ __('datasets.show_row_count') }}</dt>
                        <dd class="datagrid-content tabular-nums">{{ number_format((int) $dataset->row_count, 0, ',', '.') }}</dd>
                    </div>
                    <div class="datagrid-item">
                        <dt class="datagrid-title">{{ __('datasets.show_col_count') }}</dt>
                        <dd class="datagrid-content tabular-nums">{{ number_format((int) $dataset->column_count, 0, ',', '.') }}</dd>
                    </div>
                    <div class="datagrid-item">
                        <dt class="datagrid-title">{{ __('datasets.show_import_job') }}</dt>
                        <dd class="datagrid-content tabular-nums">{{ $dataset->import_job_id ? number_format((int) $dataset->import_job_id, 0, ',', '.') : __('datasets.show_job_none') }}</dd>
                    </div>
                    <div class="datagrid-item">
                        <dt class="datagrid-title">{{ __('datasets.show_uploaded') }}</dt>
                        <dd class="datagrid-content">
                            {{ $dataset->created_at ? $dataset->created_at->locale('id')->translatedFormat('d M Y H:i') : __('datasets.show_uploaded_unknown') }}
                        </dd>
                    </div>
                </dl>

                @if ($canWrite)
                    <form method="POST" action="{{ route('datasets.preview', $dataset) }}">
                        @csrf
                        <button
                            type="submit"
                            @disabled(! $hasJob)
                            class="btn btn-primary"
                        >{{ $hasProfile ? __('datasets.show_preview_rerun') : __('datasets.show_preview_run') }}</button>

                        @unless ($hasJob)
                            <p class="text-secondary small">{{ __('datasets.show_preview_disabled') }}</p>
                        @endunless
                    </form>
                @endif
            </x-card>

            <x-card
                :title="__('datasets.show_stage2_title')"
                description="{{ __('datasets.show_stage2_desc') }}"
            >
                @if (! $hasProfile)
                    <x-empty-state
                        :title="__('datasets.show_stage2_empty_title')"
                        description="{{ __('datasets.show_stage2_empty_desc') }}"
                    />
                @else
                    @if ($canWrite)
                        <form method="POST" action="{{ route('datasets.mapping', $dataset) }}">
                            @csrf

                            <x-table-wrapper :label="__('datasets.show_mapping_label')" class="mb-3">
                                <table class="table table-vcenter card-table">
                                    <thead>
                                        <tr>
                                            <th scope="col">{{ __('datasets.show_th_source') }}</th>
                                            <th scope="col">{{ __('datasets.show_th_canonical') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($columns as $column)
                                            @php
                                                $inputName = 'mappings['.$column.']';
                                                $selected = $mappings[$column] ?? $oldMappings[$column] ?? null;

                                                // A mapping saved before the canonical list changed
                                                // would be wiped by a plain re-submit, so keep
                                                // the stored target selectable.
                                                $options = in_array((string) $selected, $targets, true) || $selected === null
                                                    ? $targets
                                                    : array_merge([(string) $selected], $targets);
                                            @endphp
                                            <tr>
                                                <td>
                                                    <span>{{ $column }}</span>
                                                    @php $profile = $columnProfiles[$loop->index] ?? null; @endphp
                                                    @if (is_array($profile))
                                                        <span class="d-block text-secondary small">
                                                            @if (! empty($profile['dtype']))
                                                                <span class="text-uppercase">{{ $profile['dtype'] }}</span>
                                                                &middot;
                                                            @endif
                                            {{ __('datasets.show_missing', ['count' => number_format((int) ($profile['missing'] ?? 0), 0, ',', '.')]) }}
                                            &middot;
                                            {{ __('datasets.show_unique', ['count' => number_format((int) ($profile['unique'] ?? 0), 0, ',', '.')]) }}
                                                        </span>
                                                    @endif
                                                </td>
                                                <td>
                                                    <label for="mapping-{{ $loop->index }}" class="visually-hidden">{{ __('datasets.show_mapping_target', ['column' => $column]) }}</label>
                                                    <select
                                                        id="mapping-{{ $loop->index }}"
                                                        name="{{ $inputName }}"
                                                        class="form-select"
                                                    >
                                                        <option value="">{{ __('datasets.show_unmapped') }}</option>
                                                        @foreach ($options as $target)
                                                            <option value="{{ $target }}" @selected($selected === $target)>{{ $target }}</option>
                                                        @endforeach
                                                    </select>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </x-table-wrapper>

                            @error('mappings')
                                <p class="text-danger small fw-bold">{{ $message }}</p>
                            @enderror

                            <x-field
                                :label="__('datasets.show_template_label')"
                                for="save_as_template"
                                name="save_as_template"
                                hint="{{ __('datasets.show_template_hint') }}"
                                class="mb-3"
                            >
                                <input
                                    id="save_as_template"
                                    name="save_as_template"
                                    type="text"
                                    value="{{ old('save_as_template') }}"
                                    maxlength="120"
                                    placeholder="{{ __('datasets.show_template_placeholder') }}"
                                    @error('save_as_template') aria-invalid="true" @enderror
                                    class="form-control"
                                >
                            </x-field>

                            <button
                                type="submit"
                                class="btn btn-primary"
                            >{{ __('datasets.show_save_mapping') }}</button>
                        </form>
                    @else
                        <dl class="datagrid">
                            @foreach ($mappings as $source => $target)
                                <div class="datagrid-item">
                                    <dt class="datagrid-title">{{ $source }}</dt>
                                    <dd class="datagrid-content">{{ $target }}</dd>
                                </div>
                            @endforeach

                            @if ($mappings === [])
                                <x-empty-state :title="__('datasets.show_no_mapping_title')" description="{{ __('datasets.show_no_mapping_desc') }}" />
                            @endif
                        </dl>
                    @endif
                @endif
            </x-card>

            <x-card
                :title="__('datasets.show_stage3_title')"
                description="{{ __('datasets.show_stage3_desc') }}"
            >
                @if ($score === null)
                    <p class="text-secondary">
                        {{ __('datasets.show_stage3_empty') }}
                    </p>
                @else
                    <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
                        <p class="tabular-nums">
                            {{ \Illuminate\Support\Number::percentage($score * 100, precision: 1, locale: 'id') }}
                        </p>
                        <x-badge variant="{{ $passed ? 'success' : 'danger' }}">{{ $passed ? \App\Enums\QualityVerdict::Pass->localizedLabel() : __('datasets.show_not_passed') }}</x-badge>
                        <span class="text-secondary">{{ __('datasets.show_threshold', ['value' => \Illuminate\Support\Number::percentage($threshold * 100, precision: 1, locale: 'id')]) }}</span>
                    </div>

                    <dl class="mb-3">
                        @foreach ($breakdownLabels as $key => $label)
                            @php
                                $value = (float) ($breakdown[$key] ?? 0);
                                $percent = max(0.0, min(1.0, $value)) * 100;
                                $barClass = $value >= $threshold ? 'bg-success' : 'bg-warning';
                            @endphp
                            <div class="datagrid-item">
                                <div class="d-flex align-items-center justify-content-between gap-2">
                                    <dt class="datagrid-title">{{ $label }}</dt>
                                    <dd class="datagrid-content tabular-nums">{{ \Illuminate\Support\Number::percentage($value * 100, precision: 1, locale: 'id') }}</dd>
                                </div>
                                <div class="progress">
                                    <div class="progress-bar {{ $barClass }}" role="progressbar" style="width: {{ number_format($percent, 1, '.', '') }}%" aria-valuenow="{{ number_format($percent, 1, '.', '') }}" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                            </div>
                        @endforeach
                    </dl>
                @endif

                @if ($issues !== [])
                    <x-table-wrapper :label="__('datasets.show_issues_label')" class="mb-3">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('datasets.show_th_rule') }}</th>
                                    <th scope="col">{{ __('datasets.show_th_column') }}</th>
                                    <th scope="col" class="text-end">{{ __('datasets.show_th_count') }}</th>
                                    <th scope="col">{{ __('datasets.show_th_note') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($issues as $issue)
                                    <tr>
                                        <td><code>{{ $issue['rule'] ?? __('datasets.show_issue_rule_unknown') }}</code></td>
                                        <td>{{ $issue['column'] ?? __('datasets.show_issue_all_table') }}</td>
                                        <td class="text-end tabular-nums">{{ number_format((int) ($issue['count'] ?? 0), 0, ',', '.') }}</td>
                                        <td class="text-secondary">{{ $issue['message'] ?? __('datasets.show_issue_no_desc') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-table-wrapper>
                @endif

                @if ($canWrite)
                    <form method="POST" action="{{ route('datasets.quality', $dataset) }}">
                        @csrf
                        <button
                            type="submit"
                            @disabled(! $hasJob || ! $hasMappings)
                            class="btn btn-primary"
                        >{{ $score === null ? __('datasets.show_run_quality') : __('datasets.show_rerun_quality') }}</button>

                        @unless ($hasJob && $hasMappings)
                            <p class="text-secondary small">
                                {{ __('datasets.show_quality_disabled_lead') }}
                                @if (! $hasJob)
                                    {{ __('datasets.show_quality_no_job') }}
                                @else
                                    {{ __('datasets.show_quality_no_mapping') }}
                                @endif
                            </p>
                        @endunless
                    </form>
                @endif
            </x-card>

            <x-card
                :title="__('datasets.show_stage4_title')"
                description="{{ __('datasets.show_stage4_desc') }}"
            >
                @if ($isCommitted)
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                        <x-badge :class="$status->badgeClass()">{{ $status->localizedLabel() }}</x-badge>
                        <p class="text-secondary">
                            {{ __('datasets.show_commit_last') }}
                            @if ($dataset->committed_at)
                                {{ __('datasets.show_commit_on', ['date' => $dataset->committed_at->locale('id')->translatedFormat('d M Y H:i')]) }}
                            @else
                                .
                            @endif
                        </p>
                    </div>
                @elseif (! $passed)
                    <p class="text-secondary">
                        {{ __('datasets.show_commit_blocked', ['threshold' => \Illuminate\Support\Number::percentage($threshold * 100, precision: 1, locale: 'id')]) }}
                    </p>
                @endif

                @if ($canWrite)
                    <form method="POST" action="{{ route('datasets.commit', $dataset) }}">
                        @csrf

                        <div class="d-flex align-items-start gap-2 mb-3">
                            <input
                                id="run_async"
                                name="run_async"
                                type="checkbox"
                                value="1"
                                @checked(old('run_async', '1'))
                                @disabled($isCommitted)
                                class="form-check-input"
                            >
                            <label for="run_async" class="text-secondary">
                                {{ __('datasets.show_async_label') }}
                                <span class="d-block text-secondary small">
                                    {{ __('datasets.show_async_hint') }}
                                </span>
                            </label>
                        </div>

                        <button
                            type="submit"
                            @disabled($isCommitted || ! $hasJob || ! $passed)
                            class="btn btn-primary"
                        >{{ __('datasets.show_commit_button') }}</button>

                        @if ($isCommitted || ! $hasJob || ! $passed)
                            <p class="text-secondary small">
                                {{ __('datasets.show_commit_disabled_lead') }}
                                @if ($isCommitted)
                                    {{ __('datasets.show_commit_done') }}
                                @elseif (! $hasJob)
                                    {{ __('datasets.show_commit_no_job') }}
                                @else
                                    {{ __('datasets.show_commit_quality') }}
                                @endif
                            </p>
                        @endif
                    </form>
                @endif
            </x-card>

            <x-card :title="__('datasets.show_sample_title')" description="{{ __('datasets.show_sample_desc') }}">
                @if ($sampleRows === [] || $sampleColumns === [])
                    <x-empty-state
                        :title="__('datasets.show_sample_empty_title')"
                        description="{{ __('datasets.show_sample_empty_desc') }}"
                    />
                @else
                    <x-table-wrapper :label="__('datasets.show_sample_title')">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    @foreach ($sampleColumns as $column)
                                        <th scope="col">{{ $column }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($sampleRows as $row)
                                    @php $row = (array) $row; @endphp
                                    <tr>
                                        @foreach ($sampleColumns as $column)
                                            @php $value = $row[$column] ?? null; @endphp
                                            <td class="text-secondary">
                                                @php
                                                    $text = is_scalar($value) || $value === null
                                                        ? (string) ($value ?? '')
                                                        : json_encode($value, JSON_UNESCAPED_UNICODE);
                                                @endphp
                                                {{ $text === '' ? '—' : \Illuminate\Support\Str::limit($text, 60) }}
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-table-wrapper>
                @endif
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card :title="__('datasets.show_info_title')" description="{{ __('datasets.show_info_desc') }}">
                <dl class="datagrid">
                    <div class="datagrid-item">
                        <dt class="datagrid-title">{{ __('datasets.show_info_type') }}</dt>
                        <dd class="datagrid-content">{{ $typeLabels[$dataset->dataset_type] ?? $dataset->dataset_type }}</dd>
                    </div>
                    <div class="datagrid-item">
                        <dt class="datagrid-title">{{ __('datasets.show_info_file') }}</dt>
                        <dd class="datagrid-content">{{ $dataset->source_filename ?: __('datasets.show_info_file_unknown') }}</dd>
                    </div>
                    <div class="datagrid-item">
                        <dt class="datagrid-title">{{ __('datasets.show_info_size') }}</dt>
                        <dd class="datagrid-content">{{ $dataset->sizeForHumans() }}</dd>
                    </div>
                    <div class="datagrid-item">
                        <dt class="datagrid-title">{{ __('datasets.show_info_uuid') }}</dt>
                        <dd class="datagrid-content font-monospace text-secondary small">{{ $dataset->uuid }}</dd>
                    </div>
                    @if ($dataset->checksum_sha256)
                        <div class="datagrid-item">
                            <dt class="datagrid-title">{{ __('datasets.show_info_checksum') }}</dt>
                            <dd class="datagrid-content font-monospace text-secondary small">{{ $dataset->checksum_sha256 }}</dd>
                        </div>
                    @endif
                    <div class="datagrid-item">
                        <dt class="datagrid-title">{{ __('datasets.show_info_score') }}</dt>
                        <dd class="datagrid-content">
                            @if ($score !== null)
                                {{ \Illuminate\Support\Number::percentage($score * 100, precision: 1, locale: 'id') }}
                            @else
                                {{ __('datasets.show_info_score_none') }}
                            @endif
                        </dd>
                    </div>
                    <div class="datagrid-item">
                        <dt class="datagrid-title">{{ __('datasets.show_info_updated') }}</dt>
                        <dd class="datagrid-content">
                            {{ $dataset->updated_at ? $dataset->updated_at->locale('id')->translatedFormat('d M Y H:i') : __('datasets.show_info_updated_unknown') }}
                        </dd>
                    </div>
                </dl>
            </x-card>

            @if ($canWrite)
                <x-card :title="__('datasets.show_delete_title')" description="{{ __('datasets.show_delete_desc') }}">
                    <p class="text-secondary">
                        {{ __('datasets.show_delete_hint') }}
                    </p>

                    <form
                        method="POST"
                        action="{{ route('datasets.destroy', $dataset) }}"
                        x-on:submit.confirm="{{ __('datasets.show_delete_confirm') }}"
                    >
                        @csrf
                        @method('DELETE')
                        <button
                            type="submit"
                            class="btn btn-outline-danger"
                        >{{ __('datasets.show_delete_button') }}</button>
                    </form>
                </x-card>
            @endif
        </div>
    </div>
@endsection
