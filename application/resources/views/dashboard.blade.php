@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @php
        $user = auth()->user();
        $firstName = strtok((string) $user->name, ' ') ?: $user->name;
        $hour = (int) now()->format('G');
        $greetingKey = $hour < 11 ? 'morning' : ($hour < 15 ? 'afternoon' : ($hour < 19 ? 'evening' : 'night'));

        // GET /api/v1/health answers a bare HealthResponse: {status, app, env, version}.
        // The db/redis probes live on /readiness, which this page never calls, so
        // they must not be rendered here as if they had been measured.
        $engineIsHealthy = ($engineHealth['status'] ?? null) === 'ok';

        $total = (int) $stats['datasets'];
        $committed = (int) $stats['committed'];
        $quarantined = (int) $stats['quarantined'];
        $importing = (int) $stats['importing'];
        $passRate = $total > 0 ? round(($total - $quarantined) / $total * 100, 1) : null;
    @endphp

    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <div class="page-pretitle">{{ now()->locale(app()->getLocale())->translatedFormat('l, d F Y') }}</div>
                <h1 class="page-title">{{ __('dashboard.greeting_'.$greetingKey, ['name' => $firstName]) }}</h1>
                <div class="page-subtitle">{{ __('dashboard.subtitle') }}</div>
            </div>
            @if ($user->isAnalyst())
                <div class="col-auto ms-auto d-print-none">
                    <a
                        href="{{ route('datasets.create') }}"
                        class="btn btn-primary"
                    ><x-icon name="plus" />{{ __('dashboard.upload_dataset') }}</a>
                </div>
            @endif
        </div>
    </div>

    <div class="row row-cards mb-3">
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3">
                        <span class="avatar avatar-md bg-primary-lt text-primary" aria-hidden="true"><x-icon name="database" /></span>
                        <div>
                            <div class="text-secondary">{{ __('dashboard.metric_datasets') }}</div>
                            <div class="h1 mb-0">{{ number_format($total, 0, ',', '.') }}</div>
                        </div>
                    </div>
                    <div class="text-secondary small mt-2">{{ __('dashboard.metric_datasets_sub', ['count' => number_format($committed, 0, ',', '.')]) }}</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3">
                        <span class="avatar avatar-md bg-azure-lt text-azure" aria-hidden="true"><x-icon name="activity" /></span>
                        <div>
                            <div class="text-secondary">{{ __('dashboard.metric_processing') }}</div>
                            <div class="h1 mb-0">{{ number_format($importing, 0, ',', '.') }}</div>
                        </div>
                    </div>
                    <div class="text-secondary small mt-2">{{ __('dashboard.processing_hint') }}</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3">
                        <span class="avatar avatar-md bg-orange-lt text-orange" aria-hidden="true"><x-icon name="shield-check" /></span>
                        <div>
                            <div class="text-secondary">{{ __('dashboard.metric_quality') }}</div>
                            <div class="h1 mb-0">{{ $passRate === null ? '—' : number_format($passRate, 1, ',', '.').'%' }}</div>
                        </div>
                    </div>
                    <div class="text-secondary small mt-2">{{ __('dashboard.metric_quality_sub', ['count' => number_format($quarantined, 0, ',', '.')]) }}</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3">
                        <span class="avatar avatar-md bg-green-lt text-green" aria-hidden="true"><x-icon name="cpu" /></span>
                        <div>
                            <div class="text-secondary">{{ __('dashboard.metric_engine') }}</div>
                            <div class="mt-1">
                                @if (is_null($engineHealth))
                                    <x-badge variant="warning">{{ __('dashboard.engine_unreachable') }}</x-badge>
                                @elseif ($engineIsHealthy)
                                    <x-badge variant="success">{{ __('dashboard.healthy') }}</x-badge>
                                @else
                                    <x-badge variant="danger">{{ __('dashboard.degraded') }}</x-badge>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="text-secondary small mt-2">
                        @if (is_null($engineHealth))
                            {{ __('dashboard.engine_unreachable_lead') }} <code>fastapi</code>
                            {{ __('dashboard.engine_unreachable_middle') }} <code>AI_ENGINE_URL</code> {{ __('dashboard.engine_unreachable_trail') }}
                        @else
                            {{ $engineHealth['app'] ?? __('dashboard.unreported') }}
                            @if (! empty($engineHealth['version']))
                                &middot; {{ __('dashboard.version_badge', ['version' => $engineHealth['version']]) }}
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row row-cards">
        <div class="col-lg-8">
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
                        icon="database"
                    >
                        @if (auth()->user()->isAnalyst())
                            <x-slot:action>
                                <a
                                    href="{{ route('datasets.create') }}"
                                    class="btn btn-primary"
                                >{{ __('dashboard.upload_dataset') }}</a>
                            </x-slot:action>
                        @endif
                    </x-empty-state>
                @else
                    <x-table-wrapper :label="__('dashboard.recent_table_label')">
                        <table class="table table-vcenter table-hover card-table">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('dashboard.table_dataset') }}</th>
                                    <th scope="col">{{ __('dashboard.table_status') }}</th>
                                    <th scope="col" class="text-end">{{ __('dashboard.table_rows') }}</th>
                                    <th scope="col" class="text-end">{{ __('dashboard.table_updated') }}</th>
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
                                        <td><x-badge :class="$status->badgeClass()">{{ $status->localizedLabel() }}</x-badge></td>
                                        <td class="text-end tabular-nums">{{ number_format((int) $dataset->row_count, 0, ',', '.') }}</td>
                                        <td class="text-end text-secondary text-nowrap">{{ $dataset->updated_at?->locale(app()->getLocale())->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-table-wrapper>
                @endif
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card
                class="mb-3"
                :title="__('dashboard.threads_title')"
                description="{{ __('dashboard.threads_desc') }}"
            >
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
                        icon="message-chatbot"
                    >
                        <x-slot:action>
                            <a
                                href="{{ route('assistant.index') }}"
                                class="btn btn-primary"
                            >{{ __('dashboard.start_thread') }}</a>
                        </x-slot:action>
                    </x-empty-state>
                @else
                    <ul class="list-group list-group-flush">
                        @foreach ($recentThreads as $thread)
                            <li class="list-group-item px-0">
                                <div>
                                    <a
                                        href="{{ route('assistant.threads.show', $thread) }}"
                                    >{{ $thread->title ?: __('dashboard.no_thread_title') }}</a>
                                    <p class="text-secondary small mb-0">
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

            <x-card :title="__('dashboard.quick_title')">
                <div class="d-grid gap-2">
                    @if (auth()->user()->isAnalyst())
                        <a
                            href="{{ route('datasets.create') }}"
                            class="btn btn-outline-primary justify-content-start"
                        ><x-icon name="plus" />{{ __('dashboard.upload_dataset') }}</a>
                    @endif
                    <a
                        href="{{ route('assistant.index') }}"
                        class="btn btn-outline-primary justify-content-start"
                    ><x-icon name="message-chatbot" />{{ __('dashboard.quick_assistant') }}</a>
                    <a
                        href="{{ route('reports.index') }}"
                        class="btn btn-outline-primary justify-content-start"
                    ><x-icon name="report" />{{ __('dashboard.quick_reports') }}</a>
                </div>
            </x-card>
        </div>
    </div>
@endsection
