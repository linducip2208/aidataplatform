@extends('layouts.app')

@section('title', 'Audit log')

@section('content')
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">{{ __('admin.audit_header_title') }}</h1>
                <div class="page-subtitle">
                    {{ __('admin.audit_header_sub') }}
                </div>
            </div>
        </div>
    </div>

    <x-card class="mb-3" :title="__('admin.audit_filter_title')" :description="__('admin.audit_filter_desc')">
        <form method="GET" action="{{ route('audit.index') }}">
            <div class="row row-cards">
                <div class="col-md-4">
                    <x-field :label="__('admin.audit_action_label')" for="action">
                        <select
                            id="action"
                            name="action"
                            class="form-select"
                        >
                            <option value="">{{ __('admin.audit_action_all') }}</option>
                            @foreach ($actions as $action)
                                <option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ $action }}</option>
                            @endforeach
                        </select>
                    </x-field>
                </div>

                <div class="col-md-4">
                    <x-field :label="__('admin.audit_actor_label')" for="actor" :hint="__('admin.audit_actor_hint')">
                        <input
                            id="actor"
                            name="actor"
                            type="search"
                            value="{{ $filters['actor'] ?? '' }}"
                            placeholder="{{ __('admin.audit_actor_placeholder') }}"
                            @error('actor') aria-invalid="true" @enderror
                            class="form-control"
                        >
                    </x-field>
                </div>

                <div class="col-md-4">
                    <div class="d-flex align-items-end gap-2 h-100">
                        <button
                            type="submit"
                            class="btn btn-primary flex-fill"
                        >{{ __('admin.audit_apply') }}</button>
                        <a
                            href="{{ route('audit.index') }}"
                            class="btn"
                        >{{ __('admin.audit_reset') }}</a>
                    </div>
                </div>
            </div>
        </form>
    </x-card>

    <x-card :title="__('admin.audit_list_title')" :description="__('admin.audit_list_desc', ['count' => number_format($logs->total(), 0, ',', '.')])">
        @if ($logs->isEmpty())
            <x-empty-state
                :title="__('admin.audit_empty_title')"
                :description="__('admin.audit_empty_desc')"
            />
        @else
            <x-table-wrapper :label="__('admin.audit_table_label')">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('admin.audit_th_time') }}</th>
                            <th scope="col">{{ __('admin.audit_th_actor') }}</th>
                            <th scope="col">{{ __('admin.audit_th_action') }}</th>
                            <th scope="col">{{ __('admin.audit_th_resource') }}</th>
                            <th scope="col">{{ __('admin.audit_th_detail') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($logs as $log)
                            <tr>
                                <td class="text-nowrap">
                                    @if ($log->created_at)
                                        <span>{{ $log->created_at->locale('id')->translatedFormat('d M Y H:i') }}</span>
                                    @else
                                        <span class="small text-secondary">{{ __('admin.audit_unknown') }}</span>
                                    @endif
                                    @if ($log->ip)
                                        <span class="d-block small text-secondary">{{ $log->ip }}</span>
                                    @endif
                                </td>
                                <td class="text-break">{{ $log->actor ?: __('admin.audit_system') }}</td>
                                <td><code>{{ $log->action }}</code></td>
                                <td>
                                    @if ($log->resource)
                                        <span class="text-secondary">{{ $log->resource }}</span>
                                        <span class="d-block small text-secondary">
                                            {{ $log->resource_id !== null ? __('admin.audit_id_prefix').' '.$log->resource_id : __('admin.audit_no_id') }}
                                        </span>
                                    @else
                                        <span class="text-secondary">{{ __('admin.audit_none') }}</span>
                                    @endif
                                </td>
                                <td>
                                    @php
                                        $detail = (array) ($log->detail ?? []);
                                        $detailJson = $detail === [] ? '' : (string) json_encode($detail, JSON_UNESCAPED_UNICODE);
                                    @endphp
                                    @if ($detailJson === '')
                                        <span class="small text-secondary">{{ __('admin.audit_none') }}</span>
                                    @else
                                        <details class="text-start">
                                            <summary>
                                                <span class="visually-hidden">{{ __('admin.audit_show_details_sr') }}</span>
                                                {{ __('admin.audit_show_details') }}
                                            </summary>
                                            <pre class="mt-2 small text-secondary text-break" style="white-space: pre-wrap;">{{ $detailJson }}</pre>
                                        </details>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>

            <div class="mt-3">
                {{ $logs->links() }}
            </div>
        @endif
    </x-card>
@endsection
