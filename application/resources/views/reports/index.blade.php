@extends('layouts.app')

@section('title', 'Laporan')

@section('content')
    @php
        $periodLabels = config('ai_engine.period_labels', []);
        $financeLabels = config('ai_engine.finance_labels', []);

        $kpi = (array) ($report['kpi'] ?? []);
        $finance = (array) ($report['finance'] ?? []);
        $narrative = (string) ($report['narrative'] ?? '');
        $sections = (array) ($report['sections'] ?? []);
        $hasContent = $report !== [];
    @endphp

    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">{{ __('reports.header_title') }}</h1>
                <div class="page-subtitle">
                    {{ __('reports.header_subtitle') }}
                </div>
            </div>
        </div>
    </div>

    <nav aria-label="{{ __('reports.period_nav_aria') }}" class="mb-3">
        <ul class="list-unstyled d-flex flex-wrap gap-2 mb-0">
            @foreach ($periods as $value)
                @php $isActive = $period === $value; @endphp
                <li>
                    <a
                        href="{{ route('reports.index', ['period' => $value]) }}"
                        @if ($isActive) aria-current="page" @endif
                        class="{{ $isActive ? 'btn btn-primary' : 'btn' }}"
                    >{{ $periodLabels[$value] ?? \Illuminate\Support\Str::headline($value) }}</a>
                </li>
            @endforeach
        </ul>
    </nav>

    @unless ($engineAvailable)
        <x-card class="mb-3">
            <div role="status" class="alert alert-warning mb-0">
                <x-badge variant="warning">{{ __('reports.engine_unavailable') }}</x-badge>
                <p class="mb-0 text-secondary">
                    {{ __('reports.engine_unavailable_lead') }} <code>fastapi</code>
                    {{ __('reports.engine_unavailable_trail') }}
                </p>
            </div>
        </x-card>
    @endunless

    <x-card class="mb-3" :title="__('reports.history_title')" description="{{ __('reports.history_desc') }}">
        @if (($history ?? collect())->isEmpty())
            <x-empty-state
                :title="__('reports.history_empty_title')"
                description="{{ __('reports.history_empty_desc') }}"
            />
        @else
            <x-table-wrapper :label="__('reports.history_table_label')">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col" class="text-end">{{ __('reports.col_id') }}</th>
                            <th scope="col">{{ __('reports.col_period') }}</th>
                            <th scope="col">{{ __('reports.col_summary') }}</th>
                            <th scope="col">{{ __('reports.col_by') }}</th>
                            <th scope="col" class="text-end">{{ __('reports.col_created') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $item)
                            <tr>
                                <td class="text-end">{{ $item->getKey() }}</td>
                                <td><x-badge variant="info">{{ $periodLabels[$item->period] ?? $item->period }}</x-badge></td>
                                <td class="small text-secondary">{{ \Illuminate\Support\Str::limit($item->narrative(), 120) }}</td>
                                <td class="small text-secondary">{{ $item->creator?->name ?? __('reports.scheduled') }}</td>
                                <td class="text-end"><span class="small text-secondary">{{ $item->created_at?->locale('id')->translatedFormat('d M Y H:i') ?? '?' }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>
        @endif

        @if (auth()->user()->isAnalyst())
            <form method="POST" action="{{ route('reports.store') }}" class="row row-cards mt-3">
                @csrf
                <div class="col-md-6">
                    <x-field :label="__('reports.field_period')" for="history_period">
                        <select id="history_period" name="period" class="form-select">
                            @foreach ($periods as $value)
                                <option value="{{ $value }}" @selected($period === $value)>{{ $periodLabels[$value] ?? $value }}</option>
                            @endforeach
                        </select>
                    </x-field>
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary">{{ __('reports.create_now') }}</button>
                </div>
            </form>
        @endif
    </x-card>

    @unless ($hasContent)
        <x-card :title="__('reports.report_title', ['period' => $periodLabels[$period] ?? $period])">
            <x-empty-state
                :title="__('reports.report_empty_title')"
                description="{{ __('reports.report_empty_desc') }}"
            />
        </x-card>
    @else
        <x-card
            class="mb-3"
            :title="__('reports.summary_title', ['period' => $periodLabels[$period] ?? $period])"
            description="{{ __('reports.summary_desc') }}"
        >
            @if ($narrative === '')
                <x-empty-state
                    :title="__('reports.narrative_empty_title')"
                    description="{{ __('reports.narrative_empty_desc') }}"
                />
            @else
                <p class="text-secondary mb-0" style="white-space: pre-line;">{{ $narrative }}</p>
            @endif
        </x-card>

        @if ($sections !== [])
            <x-card class="mb-3" :title="__('reports.sections_title')" description="{{ __('reports.sections_desc') }}">
                <div class="d-grid gap-3">
                    @foreach ($sections as $sectionKey => $section)
                        <div>
                            <h3 class="fw-bold mb-2">{{ \Illuminate\Support\Str::headline((string) $sectionKey) }}</h3>
                            <ul class="mb-0 text-secondary">
                                @if (is_array($section))
                                    @foreach ($section as $item)
                                        <li>
                                            @if (is_array($item))
                                                {{ is_scalar($item['text'] ?? null) ? $item['text'] : json_encode($item, JSON_UNESCAPED_UNICODE) }}
                                            @else
                                                {{ is_scalar($item) ? $item : json_encode($item, JSON_UNESCAPED_UNICODE) }}
                                            @endif
                                        </li>
                                    @endforeach
                                @else
                                    <li>{{ is_scalar($section) ? $section : json_encode($section, JSON_UNESCAPED_UNICODE) }}</li>
                                @endif
                            </ul>
                        </div>
                    @endforeach
                </div>
            </x-card>
        @endif

        <div class="row row-cards">
            <div class="col-md-6">
                <x-card :title="__('reports.kpi_title')" description="{{ __('reports.kpi_desc') }}">
                    <div class="row row-cards">
                        <div class="col-sm-6"><x-stat :label="__('reports.stat_revenue')" :value="\Illuminate\Support\Number::currency((float) ($kpi['revenue'] ?? 0), in: 'idr', locale: 'id', precision: 0)" /></div>
                        <div class="col-sm-6"><x-stat :label="__('reports.stat_orders')" :value="number_format((int) ($kpi['orders'] ?? 0), 0, ',', '.')" /></div>
                        <div class="col-sm-6"><x-stat :label="__('reports.stat_units')" :value="number_format((float) ($kpi['units'] ?? 0), 0, ',', '.')" /></div>
                        <div class="col-sm-6"><x-stat :label="__('reports.stat_aov')" :value="\Illuminate\Support\Number::currency((float) ($kpi['aov'] ?? 0), in: 'idr', locale: 'id', precision: 0)" /></div>
                        <div class="col-sm-6"><x-stat :label="__('reports.stat_growth')" :value="\Illuminate\Support\Number::percentage((float) ($kpi['growth_pct'] ?? 0), precision: 1, locale: 'id')" /></div>
                        <div class="col-sm-6"><x-stat :label="__('reports.stat_margin')" :value="\Illuminate\Support\Number::percentage((float) ($kpi['margin_pct'] ?? 0), precision: 1, locale: 'id')" /></div>
                    </div>
                </x-card>
            </div>

            <div class="col-md-6">
                <x-card :title="__('reports.finance_title')" description="{{ __('reports.finance_desc') }}">
                    @if ($finance === [])
                        <x-empty-state :title="__('reports.finance_empty_title')" description="{{ __('reports.finance_empty_desc') }}" />
                    @else
                        <dl class="datagrid">
                            @foreach ($finance as $key => $value)
                                <div class="datagrid-item">
                                    <dt class="datagrid-title">{{ $financeLabels[$key] ?? \Illuminate\Support\Str::headline((string) $key) }}</dt>
                                    <dd class="datagrid-content">
                                        @if ($key === 'margin_pct')
                                            {{ \Illuminate\Support\Number::percentage((float) $value, precision: 1, locale: 'id') }}
                                        @elseif (is_bool($value))
                                            {{ $value ? __('reports.yes') : __('reports.no') }}
                                        @else
                                            {{ \Illuminate\Support\Number::currency((float) $value, in: 'idr', locale: 'id', precision: 0) }}
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                </x-card>
            </div>
        </div>

        <x-card class="mt-3" :title="__('reports.auto_title')" description="{{ __('reports.auto_desc') }}">
            @php $autoSnapshots = $snapshots ?? []; @endphp
            @if (($snapshotsAvailable ?? false) === false)
                <x-empty-state
                    :title="__('reports.snapshot_unavailable_title')"
                    description="{{ __('reports.snapshot_unavailable_desc') }}"
                />
            @elseif ($autoSnapshots === [])
                <x-empty-state
                    :title="__('reports.snapshot_empty_title')"
                    description="{{ __('reports.snapshot_empty_desc') }}"
                />
            @else
                <x-table-wrapper :label="__('reports.snapshot_table_label')">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('reports.snap_kpi') }}</th>
                                <th scope="col" class="text-end">{{ __('reports.snap_value') }}</th>
                                <th scope="col" class="text-end">{{ __('reports.snap_target') }}</th>
                                <th scope="col">{{ __('reports.snap_status') }}</th>
                                <th scope="col">{{ __('reports.snap_period') }}</th>
                                <th scope="col">{{ __('reports.snap_computed') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($autoSnapshots as $snap)
                                @php
                                    $sstatus = (string) ($snap['status'] ?? 'ok');
                                    $svariant = $sstatus === 'crit' ? 'danger' : ($sstatus === 'warn' ? 'warning' : 'success');
                                @endphp
                                <tr>
                                    <td class="fw-medium">{{ $snap['kpi_name'] ?? __('reports.unknown') }}</td>
                                    <td class="text-end">{{ number_format((float) ($snap['value'] ?? 0), 2, ',', '.') }}</td>
                                    <td class="text-end">
                                        @if (($snap['target'] ?? null) === null)
                                            <span class="text-secondary">—</span>
                                        @else
                                            {{ number_format((float) $snap['target'], 2, ',', '.') }}
                                        @endif
                                    </td>
                                    <td><x-badge :variant="$svariant">{{ strtoupper($sstatus) }}</x-badge></td>
                                    <td>{{ $snap['period'] ?? '—' }}</td>
                                    <td class="text-secondary">{{ $snap['computed_at'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-table-wrapper>
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <form method="POST" action="{{ url('/api/analytics/export') }}">
                        @csrf
                        <input type="hidden" name="format" value="csv">
                        <input type="hidden" name="dataset" value="kpi">
                        <button
                            type="submit"
                            class="btn"
                        >{{ __('reports.download_csv') }}</button>
                    </form>
                    <form method="POST" action="{{ url('/api/analytics/export') }}">
                        @csrf
                        <input type="hidden" name="format" value="xlsx">
                        <input type="hidden" name="dataset" value="kpi">
                        <button
                            type="submit"
                            class="btn"
                        >{{ __('reports.download_xlsx') }}</button>
                    </form>
                </div>
            @endif
        </x-card>
    @endunless
@endsection
