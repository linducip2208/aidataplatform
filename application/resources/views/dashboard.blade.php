@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @php
        $typeLabels = config('ai_engine.dataset_type_labels', []);

        // GET /api/v1/health answers a bare HealthResponse: {status, app, env, version}.
        // The db/redis probes live on /readiness, which this page never calls, so
        // they must not be rendered here as if they had been measured.
        $engineIsHealthy = ($engineHealth['status'] ?? null) === 'ok';
    @endphp

    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">Dashboard</h1>
                <div class="page-subtitle">
                    {{ __('dashboard.subtitle') }}
                </div>
            </div>
        </div>
    </div>

    <div class="row row-cards mb-3">
        <div class="col-sm-6 col-lg-3">
            <x-stat :label="__('dashboard.total_label')" :value="number_format((int) $stats['datasets'], 0, ',', '.')" hint="{{ __('dashboard.total_hint') }}" />
        </div>
        <div class="col-sm-6 col-lg-3">
            <x-stat
                :label="\App\Enums\DatasetStatus::Committed->localizedLabel()"
                :value="number_format((int) $stats['committed'], 0, ',', '.')"
                :hint="__('dashboard.committed_hint')"
            />
        </div>
        <div class="col-sm-6 col-lg-3">
            <x-stat
                :label="\App\Enums\DatasetStatus::Quarantined->localizedLabel()"
                :value="number_format((int) $stats['quarantined'], 0, ',', '.')"
                :hint="__('dashboard.quarantined_hint')"
            />
        </div>
        {{-- Spans uploaded, previewing and importing, so it names the group
             rather than borrowing the label of any one status. --}}
        <div class="col-sm-6 col-lg-3">
            <x-stat :label="__('dashboard.processing_label')" :value="number_format((int) $stats['importing'], 0, ',', '.')" hint="{{ __('dashboard.processing_hint') }}" />
        </div>
    </div>

    <x-card
        class="mb-3"
        :title="__('dashboard.engine_title')"
        description="{{ __('dashboard.engine_desc') }}"
    >
        @if (is_null($engineHealth))
            <div role="status" class="d-flex flex-wrap align-items-center gap-2">
                <x-badge variant="warning">{{ __('dashboard.engine_unreachable') }}</x-badge>
                <p class="text-secondary">
                    {{ __('dashboard.engine_unreachable_lead') }} <code>fastapi</code>
                    {{ __('dashboard.engine_unreachable_middle') }} <code>AI_ENGINE_URL</code> {{ __('dashboard.engine_unreachable_trail') }}
                </p>
            </div>
        @else
            <dl class="datagrid">
                <div class="datagrid-item">
                    <dt class="datagrid-title">{{ __('dashboard.status_label') }}</dt>
                    <dd class="datagrid-content">
                        @if ($engineIsHealthy)
                            <x-badge variant="success">{{ __('dashboard.healthy') }}</x-badge>
                        @else
                            <x-badge variant="danger">{{ __('dashboard.degraded') }}</x-badge>
                            <span class="text-secondary">{{ $engineHealth['status'] ?? __('dashboard.unknown_status') }}</span>
                        @endif
                    </dd>
                </div>
                <div class="datagrid-item">
                    <dt class="datagrid-title">{{ __('dashboard.app_label') }}</dt>
                    <dd class="datagrid-content">
                        {{ $engineHealth['app'] ?? __('dashboard.unreported') }}
                    </dd>
                </div>
                <div class="datagrid-item">
                    <dt class="datagrid-title">{{ __('dashboard.env_label') }}</dt>
                    <dd class="datagrid-content">
                        <span>{{ $engineHealth['env'] ?? __('dashboard.unreported') }}</span>
                        @if (! empty($engineHealth['version']))
                            <x-badge variant="neutral">{{ __('dashboard.version_badge', ['version' => $engineHealth['version']]) }}</x-badge>
                        @endif
                    </dd>
                </div>
            </dl>
        @endif
    </x-card>

    <x-card
        class="mb-3"
        :title="__('dashboard.kpi_title')"
        description="{{ __('dashboard.kpi_desc') }}"
    >
        @if (is_null($kpi))
            <x-empty-state
                :title="__('dashboard.kpi_empty_title')"
                description="{{ __('dashboard.kpi_empty_desc') }}"
            />
        @else
            <div class="row row-cards">
                {{-- Rupiah carries no decimals and the engine already sends the
                     percentage fields in 0-100, so they are passed through
                     un-scaled. --}}
                <div class="col-sm-6 col-lg-4">
                    <x-stat :label="__('dashboard.revenue')" :value="\Illuminate\Support\Number::currency((float) ($kpi['revenue'] ?? 0), in: 'idr', locale: 'id', precision: 0)" />
                </div>
                <div class="col-sm-6 col-lg-4">
                    <x-stat :label="__('dashboard.orders')" :value="number_format((int) ($kpi['orders'] ?? 0), 0, ',', '.')" />
                </div>
                <div class="col-sm-6 col-lg-4">
                    <x-stat :label="__('dashboard.units')" :value="number_format((float) ($kpi['units'] ?? 0), 0, ',', '.')" />
                </div>
                <div class="col-sm-6 col-lg-4">
                    <x-stat :label="__('dashboard.aov')" :value="\Illuminate\Support\Number::currency((float) ($kpi['aov'] ?? 0), in: 'idr', locale: 'id', precision: 0)" />
                </div>
                <div class="col-sm-6 col-lg-4">
                    <x-stat
                        :label="__('dashboard.growth')"
                        :value="\Illuminate\Support\Number::percentage((float) ($kpi['growth_pct'] ?? 0), precision: 1, locale: 'id')"
                        :hint="((float) ($kpi['growth_pct'] ?? 0)) >= 0 ? __('dashboard.growth_up') : __('dashboard.growth_down')"
                    />
                </div>
                <div class="col-sm-6 col-lg-4">
                    <x-stat
                        :label="__('dashboard.margin')"
                        :value="\Illuminate\Support\Number::percentage((float) ($kpi['margin_pct'] ?? 0), precision: 1, locale: 'id')"
                        hint="{{ __('dashboard.margin_hint') }}"
                    />
                </div>
            </div>
        @endif
    </x-card>

    <div class="row row-cards">
        <div class="col-md-6">
            <x-card :title="__('dashboard.recent_title')" description="{{ __('dashboard.recent_desc') }}">
                <x-slot:actions>
                    <a
                        href="{{ route('datasets.index') }}"
                        class="btn"
                    >{{ __('dashboard.view_all') }}</a>
                </x-slot:actions>

                @if ($recentDatasets->isEmpty())
                    <x-empty-state
                        :title="__('dashboard.empty_datasets_title')"
                        description="{{ __('dashboard.empty_datasets_desc') }}"
                    >
                        <x-slot:action>
                            <a
                                href="{{ route('datasets.create') }}"
                                class="btn btn-primary"
                            >{{ __('dashboard.upload_dataset') }}</a>
                        </x-slot:action>
                    </x-empty-state>
                @else
                    <x-table-wrapper :label="__('dashboard.recent_table_label')">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('dashboard.table_dataset') }}</th>
                                    <th scope="col">{{ __('dashboard.table_type') }}</th>
                                    <th scope="col">{{ __('dashboard.table_status') }}</th>
                                    <th scope="col" class="text-end">{{ __('dashboard.table_rows') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recentDatasets as $dataset)
                                    @php $status = $dataset->status(); @endphp
                                    <tr>
                                        <td>
                                            <a
                                                href="{{ route('datasets.show', $dataset) }}"
                                            >{{ $dataset->name }}</a>
                                            <span class="d-block text-secondary small">{{ $dataset->source_filename ?: __('dashboard.no_file') }}</span>
                                        </td>
                                        <td>{{ $typeLabels[$dataset->dataset_type] ?? $dataset->dataset_type }}</td>
                                        <td><x-badge :class="$status->badgeClass()">{{ $status->localizedLabel() }}</x-badge></td>
                                        <td class="text-end tabular-nums">{{ number_format((int) $dataset->row_count, 0, ',', '.') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-table-wrapper>
                @endif
            </x-card>
        </div>

        <div class="col-md-6">
            <x-card :title="__('dashboard.threads_title')" description="{{ __('dashboard.threads_desc') }}">
                <x-slot:actions>
                    <a
                        href="{{ route('assistant.index') }}"
                        class="btn"
                    >{{ __('dashboard.open_assistant') }}</a>
                </x-slot:actions>

                @if ($recentThreads->isEmpty())
                    <x-empty-state
                        :title="__('dashboard.empty_threads_title')"
                        description="{{ __('dashboard.empty_threads_desc') }}"
                    />
                @else
                    <ul class="list-group list-group-flush">
                        @foreach ($recentThreads as $thread)
                            <li class="list-group-item">
                                <div>
                                    <a
                                        href="{{ route('assistant.threads.show', $thread) }}"
                                    >{{ $thread->title ?: __('dashboard.no_thread_title') }}</a>
                                    <p class="text-secondary small">
                                        {{ __('dashboard.messages_count', ['count' => number_format((int) $thread->message_count, 0, ',', '.')]) }}
                                        @if ($thread->last_message_at)
                                            &middot; {{ $thread->last_message_at->locale('id')->translatedFormat('d M Y H:i') }}
                                        @endif
                                    </p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>
    </div>
@endsection
