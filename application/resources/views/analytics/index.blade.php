@extends('layouts.app')

@section('title', 'Analitik')

@section('content')
    @php
        $branchOptions = collect($branches)->pluck('branch')->filter()->map(fn ($value): string => (string) $value);

        // Keep the active filter selectable even when the engine is down and
        // returns no branch list, otherwise a reload silently drops it.
        if (filled($filters['branch'] ?? null)) {
            $branchOptions = $branchOptions->push((string) $filters['branch']);
        }

        $branchOptions = $branchOptions->unique()->sort()->values();

        $periodLabels = config('ai_engine.period_labels', []);
        $financeLabels = config('ai_engine.finance_labels', []);

        $rfmColumns = [
            'customer' => 'Pelanggan',
            'recency_days' => 'Recency (hari)',
            'frequency' => 'Frequency',
            'monetary' => 'Monetary',
            'r_score' => 'Skor R',
            'f_score' => 'Skor F',
            'm_score' => 'Skor M',
            'segment' => 'Segmen',
        ];
    @endphp

    <div class="page-header">
        <h1 class="page-title">{{ __('analytics.header_title') }}</h1>
        <p class="page-subtitle">
            {{ __('analytics.header_subtitle') }}
        </p>
    </div>

    @unless ($engineAvailable)
        <x-card>
            <div role="status" class="alert alert-warning">
                <x-badge variant="warning">{{ __('analytics.engine_unavailable_badge') }}</x-badge>
                <p class="text-secondary">
                    {{ __('analytics.engine_unavailable_lead') }} <code>fastapi</code>
                    {{ __('analytics.engine_unavailable_trail') }}
                </p>
            </div>
        </x-card>
    @endunless

    <x-card :title="__('analytics.filter_title')" description="{{ __('analytics.filter_desc') }}">
        <form method="GET" action="{{ route('analytics.index') }}" class="row row-cards">
            <div class="col-md-6">
                <x-field :label="__('analytics.field_start')" for="date_from">
                    <input
                        id="date_from"
                        name="date_from"
                        type="date"
                        value="{{ $filters['date_from'] ?? '' }}"
                        @error('date_from') aria-invalid="true" @enderror
                        class="form-control"
                    >
                </x-field>
            </div>

            <div class="col-md-6">
                <x-field :label="__('analytics.field_end')" for="date_to">
                    <input
                        id="date_to"
                        name="date_to"
                        type="date"
                        value="{{ $filters['date_to'] ?? '' }}"
                        @error('date_to') aria-invalid="true" @enderror
                        class="form-control"
                    >
                </x-field>
            </div>

            <div class="col-md-6">
                <x-field :label="__('analytics.field_branch')" for="branch" hint="{{ __('analytics.field_branch_hint') }}">
                    <select
                        id="branch"
                        name="branch"
                        class="form-select"
                    >
                        <option value="">{{ __('analytics.branch_all') }}</option>
                        @foreach ($branchOptions as $branch)
                            <option value="{{ $branch }}" @selected(($filters['branch'] ?? '') === $branch)>{{ $branch }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>

            <div class="col-md-6">
                <x-field :label="__('analytics.field_category')" for="category" hint="{{ __('analytics.field_category_hint') }}">
                    <input
                        id="category"
                        name="category"
                        type="text"
                        value="{{ $filters['category'] ?? '' }}"
                        placeholder="{{ __('analytics.field_category_placeholder') }}"
                        @error('category') aria-invalid="true" @enderror
                        class="form-control"
                    >
                </x-field>
            </div>

            <div class="col-md-6">
                <x-field :label="__('analytics.field_granularity')" for="granularity">
                    <select
                        id="granularity"
                        name="granularity"
                        class="form-select"
                    >
                        @foreach ($periodLabels as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['granularity'] ?? 'daily') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>

            <div class="col-md-6">
                <button
                    type="submit"
                    class="btn btn-primary"
                >{{ __('analytics.apply') }}</button>
                <a
                    href="{{ route('analytics.index') }}"
                    class="btn"
                >{{ __('analytics.reset') }}</a>
            </div>
        </form>
    </x-card>

    <x-card :title="__('analytics.kpi_title')" description="{{ __('analytics.kpi_desc') }}">
        <div class="row row-cards">
            <div class="col-sm-6 col-lg-4">
                <x-stat :label="__('analytics.kpi_revenue')" :value="\Illuminate\Support\Number::currency((float) ($kpi['revenue'] ?? 0), in: 'idr', locale: 'id', precision: 0)" />
            </div>
            <div class="col-sm-6 col-lg-4">
                <x-stat :label="__('analytics.kpi_orders')" :value="number_format((int) ($kpi['orders'] ?? 0), 0, ',', '.')" />
            </div>
            <div class="col-sm-6 col-lg-4">
                <x-stat :label="__('analytics.kpi_units')" :value="number_format((float) ($kpi['units'] ?? 0), 0, ',', '.')" />
            </div>
            <div class="col-sm-6 col-lg-4">
                <x-stat :label="__('analytics.kpi_aov')" :value="\Illuminate\Support\Number::currency((float) ($kpi['aov'] ?? 0), in: 'idr', locale: 'id', precision: 0)" />
            </div>
            <div class="col-sm-6 col-lg-4">
                <x-stat
                    :label="__('analytics.kpi_growth')"
                    :value="\Illuminate\Support\Number::percentage((float) ($kpi['growth_pct'] ?? 0), precision: 1, locale: 'id')"
                    :hint="((float) ($kpi['growth_pct'] ?? 0)) >= 0 ? __('analytics.kpi_growth_up') : __('analytics.kpi_growth_down')"
                />
            </div>
            <div class="col-sm-6 col-lg-4">
                <x-stat
                    :label="__('analytics.kpi_margin')"
                    :value="\Illuminate\Support\Number::percentage((float) ($kpi['margin_pct'] ?? 0), precision: 1, locale: 'id')"
                    hint="{{ __('analytics.kpi_margin_hint') }}"
                />
            </div>
        </div>
    </x-card>

    <x-card :title="__('analytics.targets_title')" description="{{ __('analytics.targets_desc') }}">
        @php $evaluated = $kpiEvaluated ?? []; @endphp
        @if ($evaluated === [])
            <x-empty-state
                :title="__('analytics.targets_empty_title')"
                description="{{ __('analytics.targets_empty_desc') }}"
            />
        @else
            <x-table-wrapper :label="__('analytics.targets_table')">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('analytics.th_kpi') }}</th>
                            <th scope="col" class="text-end">{{ __('analytics.th_value') }}</th>
                            <th scope="col" class="text-end">{{ __('analytics.th_target') }}</th>
                            <th scope="col">{{ __('analytics.th_status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($evaluated as $row)
                            @php
                                $status = (string) ($row['status'] ?? 'ok');
                                $variant = $status === 'crit' ? 'danger' : ($status === 'warn' ? 'warning' : 'success');
                                $label = $status === 'crit' ? 'Kritis' : ($status === 'warn' ? 'Waspada' : 'OK');
                            @endphp
                            <tr>
                                <td class="fw-bold text-secondary">{{ $row['name'] ?? __('analytics.unknown') }}</td>
                                <td class="text-end">{{ number_format((float) ($row['value'] ?? 0), 2, ',', '.') }}</td>
                                <td class="text-end">
                                    @if (($row['target'] ?? null) === null)
                                        <span class="text-secondary">—</span>
                                    @else
                                        {{ number_format((float) $row['target'], 2, ',', '.') }}
                                    @endif
                                </td>
                                <td><x-badge :variant="$variant">{{ $label }}</x-badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>
        @endif
    </x-card>

    <x-card :title="__('analytics.compare_title')" description="{{ __('analytics.compare_desc') }}">
        @php
            $compareRows = [];
            $comparisonData = $comparison ?? [];
            if (isset($comparisonData['kpis']) && is_array($comparisonData['kpis'])) {
                $compareRows = $comparisonData['kpis'];
            }
        @endphp
        @if ($compareRows === [])
            <x-empty-state
                :title="__('analytics.compare_empty_title')"
                description="{{ __('analytics.compare_empty_desc') }}"
            />
        @else
            <x-table-wrapper :label="__('analytics.compare_table')">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('analytics.th_kpi') }}</th>
                            <th scope="col" class="text-end">{{ __('analytics.th_current') }}</th>
                            <th scope="col" class="text-end">{{ __('analytics.th_previous') }}</th>
                            <th scope="col" class="text-end">{{ __('analytics.th_delta') }}</th>
                            <th scope="col" class="text-end">{{ __('analytics.th_delta_pct') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($compareRows as $name => $delta)
                            <tr>
                                <td class="fw-bold text-secondary">{{ $name }}</td>
                                <td class="text-end">{{ number_format((float) ($delta['current'] ?? 0), 2, ',', '.') }}</td>
                                <td class="text-end">{{ number_format((float) ($delta['previous'] ?? 0), 2, ',', '.') }}</td>
                                <td class="text-end">{{ number_format((float) ($delta['delta'] ?? 0), 2, ',', '.') }}</td>
                                <td class="text-end">{{ number_format((float) ($delta['delta_pct'] ?? 0), 1, ',', '.') }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>
        @endif
    </x-card>

    <x-card :title="__('analytics.trend_title')" description="{{ __('analytics.trend_desc') }}">
        @if ($trend === [])
            <x-empty-state
                :title="__('analytics.trend_empty_title')"
                description="{{ __('analytics.trend_empty_desc') }}"
            />
        @else
            <x-trend-chart
                :label="__('analytics.trend_chart_label')"
                :points="collect($trend)->map(fn ($point): array => ['label' => (string) ($point['period'] ?? ''), 'value' => (float) ($point['revenue'] ?? 0)])->values()->all()"
            />
            <x-table-wrapper :label="__('analytics.trend_table')">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('analytics.th_period') }}</th>
                            <th scope="col" class="text-end">{{ __('analytics.th_revenue') }}</th>
                            <th scope="col" class="text-end">{{ __('analytics.th_orders') }}</th>
                            <th scope="col" class="text-end">{{ __('analytics.th_units') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($trend as $point)
                            <tr>
                                <td class="fw-bold text-secondary">{{ $point['period'] ?? __('analytics.unknown') }}</td>
                                <td class="text-end">{{ \Illuminate\Support\Number::currency((float) ($point['revenue'] ?? 0), in: 'idr', locale: 'id', precision: 0) }}</td>
                                <td class="text-end">{{ number_format((int) ($point['orders'] ?? 0), 0, ',', '.') }}</td>
                                <td class="text-end">{{ number_format((float) ($point['units'] ?? 0), 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>
        @endif
    </x-card>

    <div class="row row-cards">
        <div class="col-md-6">
            <x-card :title="__('analytics.branches_title')" description="{{ __('analytics.branches_desc') }}">
                @if ($branches === [])
                    <x-empty-state :title="__('analytics.branches_empty_title')" description="{{ __('analytics.branches_empty_desc') }}" />
                @else
                    @php $maxBranchRevenue = max(1.0, (float) collect($branches)->max('revenue')); @endphp
                    <div class="mb-3" role="img" aria-label="{{ __('analytics.branches_chart_aria') }}">
                        @foreach ($branches as $row)
                            @php $barPct = max(0.0, min(100.0, (float) ($row['revenue'] ?? 0) / $maxBranchRevenue * 100)); @endphp
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="small text-secondary text-truncate" style="width: 6rem;">{{ $row['branch'] ?? '?' }}</span>
                                <div class="progress flex-fill" style="height: 0.625rem;">
                                    <div class="progress-bar bg-primary" style="width: {{ number_format($barPct, 1, '.', '') }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <x-table-wrapper :label="__('analytics.branches_table')">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('analytics.th_branch') }}</th>
                                    <th scope="col" class="text-end">{{ __('analytics.th_revenue') }}</th>
                                    <th scope="col" class="text-end">{{ __('analytics.th_orders') }}</th>
                                    <th scope="col" class="text-end">{{ __('analytics.th_share') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($branches as $row)
                                    <tr>
                                        <td class="fw-bold text-secondary">{{ $row['branch'] ?? __('analytics.unknown') }}</td>
                                        <td class="text-end">{{ \Illuminate\Support\Number::currency((float) ($row['revenue'] ?? 0), in: 'idr', locale: 'id', precision: 0) }}</td>
                                        <td class="text-end">{{ number_format((int) ($row['orders'] ?? 0), 0, ',', '.') }}</td>
                                        <td class="text-end">{{ \Illuminate\Support\Number::percentage((float) ($row['share_pct'] ?? 0), precision: 1, locale: 'id') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-table-wrapper>
                @endif
            </x-card>
        </div>

        <div class="col-md-6">
            <x-card :title="__('analytics.finance_title')" description="{{ __('analytics.finance_desc') }}">
                @if ($finance === [])
                    <x-empty-state :title="__('analytics.finance_empty_title')" description="{{ __('analytics.finance_empty_desc') }}" />
                @else
                    <dl class="datagrid">
                        @foreach ($finance as $key => $value)
                            @php $isMoney = ! in_array($key, ['margin_pct'], true); @endphp
                            <div class="datagrid-item">
                                <dt class="datagrid-title">{{ $financeLabels[$key] ?? \Illuminate\Support\Str::headline((string) $key) }}</dt>
                                <dd class="datagrid-content">
                                    @if (is_bool($value))
                                        {{ $value ? __('analytics.yes') : __('analytics.no') }}
                                    @elseif ($isMoney)
                                        {{ \Illuminate\Support\Number::currency((float) $value, in: 'idr', locale: 'id', precision: 0) }}
                                    @else
                                        {{ \Illuminate\Support\Number::percentage((float) $value, precision: 1, locale: 'id') }}
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </x-card>
        </div>
    </div>

    <x-card :title="__('analytics.rfm_title')" description="{{ __('analytics.rfm_desc') }}">
        @if ($rfm === [])
            <x-empty-state :title="__('analytics.rfm_empty_title')" description="{{ __('analytics.rfm_empty_desc') }}" />
        @else
            <x-table-wrapper :label="__('analytics.rfm_table')">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            @foreach ($rfmColumns as $label)
                                <th scope="col" @if (! in_array($label, ['Pelanggan', 'Segmen'], true)) class="text-end" @endif>{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rfm as $row)
                            <tr>
                                @foreach ($rfmColumns as $key => $label)
                                    @php $value = $row[$key] ?? null; @endphp
                                    <td @if (! in_array($label, ['Pelanggan', 'Segmen'], true)) class="text-end" @endif>
                                        @if ($value === null || $value === '')
                                            <span class="text-secondary">—</span>
                                        @elseif ($key === 'monetary')
                                            {{ \Illuminate\Support\Number::currency((float) $value, in: 'idr', locale: 'id', precision: 0) }}
                                        @else
                                            {{ \Illuminate\Support\Str::limit((string) $value, 40) }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>
        @endif
    </x-card>

    <x-card :title="__('analytics.abc_title')" description="{{ __('analytics.abc_desc') }}">
        @if ($abc === [])
            <x-empty-state :title="__('analytics.abc_empty_title')" description="{{ __('analytics.abc_empty_desc') }}" />
        @else
            <x-table-wrapper :label="__('analytics.abc_table')">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('analytics.th_product') }}</th>
                            <th scope="col" class="text-end">{{ __('analytics.th_revenue') }}</th>
                            <th scope="col" class="text-end">{{ __('analytics.th_share') }}</th>
                            <th scope="col" class="text-end">{{ __('analytics.th_cumulative') }}</th>
                            <th scope="col">{{ __('analytics.th_class') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($abc as $row)
                            @php $grade = strtoupper((string) ($row['grade'] ?? 'C')); @endphp
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        @php
                                            $productName = (string) ($row['product'] ?? 'Tidak diketahui');
                                            $productImage = $row['image_url'] ?? null;
                                            $productDescription = $row['description'] ?? null;
                                        @endphp
                                        @if (is_string($productImage) && $productImage !== '')
                                            <span class="avatar avatar-sm bg-white border" aria-hidden="true">
                                                <img src="{{ $productImage }}" alt="" loading="lazy" width="32" height="32">
                                            </span>
                                        @else
                                            <span class="avatar avatar-sm bg-azure-lt" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($productName, 0, 1)) }}</span>
                                        @endif
                                        <div class="min-w-0">
                                            <div class="fw-bold text-secondary">{{ $productName }}</div>
                                            @if (is_string($productDescription) && $productDescription !== '')
                                                <div class="small text-secondary text-truncate" style="max-width: 22rem;">{{ $productDescription }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="text-end">{{ \Illuminate\Support\Number::currency((float) ($row['revenue'] ?? 0), in: 'idr', locale: 'id', precision: 0) }}</td>
                                <td class="text-end">{{ \Illuminate\Support\Number::percentage((float) ($row['share_pct'] ?? 0), precision: 1, locale: 'id') }}</td>
                                <td class="text-end">{{ \Illuminate\Support\Number::percentage((float) ($row['cumulative_pct'] ?? 0), precision: 1, locale: 'id') }}</td>
                                <td>
                                    <x-badge variant="{{ $grade === 'A' ? 'success' : ($grade === 'B' ? 'info' : 'neutral') }}">
                                        {{ __('analytics.grade_label', ['grade' => $grade]) }}
                                    </x-badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>
        @endif
    </x-card>

    <x-card :title="__('analytics.cohort_title')" description="{{ __('analytics.cohort_desc') }}">
        @php
            $cohortPeriods = collect($cohort)->pluck('period_offset')->filter(fn ($value): bool => is_numeric($value))->map(fn ($value): int => (int) $value)->unique()->sort()->values();
            $cohortNames = collect($cohort)->pluck('cohort')->filter()->unique()->values();

            // Index the cohort rows once, keyed exactly as the grid looks them
            // up below. Scanning the whole collection again for every
            // cohort x period cell made this table quadratic in the number of
            // rows the engine returns.
            //
            // The key carries the value's type because the lookup is a strict
            // `===`: `1` and `'1'` are distinct cohort names here, and
            // collapsing them onto one key would merge two grid cells. `??=`
            // keeps the first row for a duplicate key, which is what
            // Collection::first() returned before.
            $cohortCells = [];

            foreach ($cohort as $row) {
                $cohortValue = $row['cohort'] ?? null;
                $cohortCells[gettype($cohortValue).':'.$cohortValue.'|'.((int) ($row['period_offset'] ?? -1))] ??= $row;
            }
        @endphp

        @if ($cohort === [])
            <x-empty-state :title="__('analytics.cohort_empty_title')" description="{{ __('analytics.cohort_empty_desc') }}" />
        @else
            <x-table-wrapper :label="__('analytics.cohort_table')">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('analytics.th_cohort') }}</th>
                            @foreach ($cohortPeriods as $offset)
                                <th scope="col" class="text-end">
                                    {{ $offset === 0 ? __('analytics.cohort_initial') : '+'.$offset }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cohortNames as $name)
                            <tr>
                                <th scope="row">
                                    {{ $name }}
                                </th>
                                @foreach ($cohortPeriods as $offset)
                                    @php
                                        $cell = $cohortCells[gettype($name).':'.$name.'|'.((int) $offset)] ?? null;
                                    @endphp
                                    <td class="text-end">
                                        @if ($cell === null)
                                            <span class="text-secondary">—</span>
                                        @else
                                            <span class="fw-bold text-secondary">
                                                {{ \Illuminate\Support\Number::percentage((float) ($cell['retention_pct'] ?? 0), precision: 1, locale: 'id') }}
                                            </span>
                                            <span class="text-secondary">
                                                {{ __('analytics.customers_count', ['count' => number_format((int) ($cell['active_customers'] ?? 0), 0, ',', '.')]) }}
                                            </span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>
        @endif
    </x-card>

    <x-card :title="__('analytics.dashboard_title')" description="{{ __('analytics.dashboard_desc') }}">
        @php
            $dashboardData = $dashboard ?? [];
            $widgets = is_array($dashboardData) && isset($dashboardData['widgets']) && is_array($dashboardData['widgets'])
                ? $dashboardData['widgets']
                : [];
        @endphp
        @if ($widgets === [])
            <x-empty-state
                :title="__('analytics.dashboard_empty_title')"
                description="{{ __('analytics.dashboard_empty_desc') }}"
            />
        @else
            <div class="row row-cards">
                @foreach ($widgets as $widget)
                    @php
                        $wdata = $widget['data'] ?? [];
                        $count = is_array($wdata) ? (array_is_list($wdata) ? count($wdata) : count($wdata)) : 0;
                    @endphp
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h3 class="fw-bold">{{ $widget['title'] ?? $widget['id'] ?? 'Widget' }}</h3>
                                <x-badge variant="info">{{ $widget['chart_type'] ?? 'table' }}</x-badge>
                                <p class="text-secondary">
                                    {{ __('analytics.widget_rows', ['count' => $count]) }} <code>{{ $widget['endpoint'] ?? '—' }}</code>
                                </p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-card>

    <x-card :title="__('analytics.export_title')" description="{{ __('analytics.export_desc') }}">
        <div>
            <form method="POST" action="{{ url('/api/analytics/export') }}">
                @csrf
                <input type="hidden" name="format" value="csv">
                <input type="hidden" name="dataset" value="trend">
                <button
                    type="submit"
                    class="btn"
                >{{ __('analytics.export_csv') }}</button>
            </form>
            <form method="POST" action="{{ url('/api/analytics/export') }}">
                @csrf
                <input type="hidden" name="format" value="xlsx">
                <input type="hidden" name="dataset" value="trend">
                <button
                    type="submit"
                    class="btn"
                >{{ __('analytics.export_xlsx') }}</button>
            </form>
        </div>
        <p class="text-secondary">{{ __('analytics.export_pdf_note') }}</p>
    </x-card>

    @php
        // Built in PHP, not inline in the directive: a multiline array literal
        // inside @json() breaks Blade's parenthesis matching and compiles to
        // invalid PHP. @json escapes <, >, & for safe embedding in <script>.
        $analyticsData = [
            'kpi' => $kpi ?? [],
            'trend' => $trend ?? [],
            'branches' => $branches ?? [],
            'finance' => $finance ?? [],
            'comparison' => $comparison ?? [],
            'dashboard' => $dashboard ?? [],
        ];
    @endphp
    <script type="application/json" id="analytics-data">
        @json($analyticsData)
    </script>
@endsection
