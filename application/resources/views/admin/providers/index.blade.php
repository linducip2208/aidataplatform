@extends('layouts.app')

@section('title', 'Provider AI')

@section('content')
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">{{ __('admin.providers_header_title') }}</h1>
                <div class="page-subtitle">
                    {{ __('admin.providers_header_sub') }}
                    @if ($active)
                        {{ __('admin.providers_active_label') }}: <strong>{{ $active->name }}</strong> ({{ $active->typeLabel() }}).
                    @else
                        {{ __('admin.providers_none_active') }}
                    @endif
                </div>
            </div>
            <div class="col-auto ms-auto">
                <a href="{{ route('admin.providers.create') }}" class="btn btn-primary"><x-icon name="plus" />{{ __('admin.providers_add') }}</a>
            </div>
        </div>
    </div>

    @if (session('publish_block'))
        <x-card class="mb-3" :title="__('admin.providers_env_title')" :description="__('admin.providers_env_desc')">
            <pre class="mb-2 p-3 bg-light border rounded small">{{ session('publish_block') }}</pre>
            <p class="small text-secondary"><code>{{ session('publish_restart') }}</code></p>
        </x-card>
    @endif

    <x-card :title="__('admin.providers_list_title')" :description="__('admin.providers_list_desc')">
        @if ($providers->isEmpty())
            <x-empty-state
                :title="__('admin.providers_empty_title')"
                :description="__('admin.providers_empty_desc')"
            />
        @else
            <x-table-wrapper :label="__('admin.providers_table_label')">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('admin.providers_th_name') }}</th>
                            <th scope="col">{{ __('admin.providers_th_type') }}</th>
                            <th scope="col">{{ __('admin.providers_th_model') }}</th>
                            <th scope="col" class="text-end">{{ __('admin.providers_th_priority') }}</th>
                            <th scope="col">{{ __('admin.providers_th_status') }}</th>
                            <th scope="col">{{ __('admin.providers_th_last_test') }}</th>
                            <th scope="col"><span class="visually-hidden">{{ __('admin.providers_th_actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($providers as $provider)
                            <tr>
                                <td>
                                    <div class="fw-bold text-secondary">{{ $provider->name }}</div>
                                    <div class="small text-secondary text-truncate" style="max-width: 22rem;">{{ $provider->base_url }}</div>
                                </td>
                                <td class="small text-secondary">{{ $provider->typeLabel() }}</td>
                                <td><code>{{ $provider->model }}</code>
                                    @if ($provider->models_count > 0)
                                        <span class="d-block small text-secondary mt-1">{{ __('admin.providers_models_count', ['count' => $provider->models_count]) }}</span>
                                    @endif
                                </td>
                                <td class="text-end">{{ $provider->priority }}</td>
                                <td>
                                    <x-badge variant="{{ $provider->is_active ? 'success' : 'neutral' }}">
                                        {{ $provider->is_active ? __('admin.providers_active') : __('admin.providers_inactive') }}
                                    </x-badge>
                                </td>
                                <td class="small text-secondary">
                                    @if ($provider->last_tested_at)
                                        {{ $provider->last_test_status }} &middot; {{ $provider->last_tested_at->locale('id')->translatedFormat('d M Y H:i') }}
                                    @else
                                        {{ __('admin.providers_never_tested') }}
                                    @endif
                                </td>
                                <td class="text-end">
                                    <div class="d-flex flex-wrap justify-content-end gap-1">
                                        <a href="{{ route('admin.providers.edit', $provider) }}" class="btn btn-sm">{{ __('admin.providers_edit') }}</a>
                                        <form method="POST" action="{{ route('admin.providers.toggle', $provider) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm">{{ $provider->is_active ? __('admin.providers_deactivate') : __('admin.providers_activate') }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.providers.destroy', $provider) }}" x-data x-on:submit.confirm="{{ __('admin.providers_delete_confirm') }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('admin.providers_delete') }}</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="7" class="bg-light">
                                    <div class="d-flex flex-wrap gap-2 align-items-end">
                                        <form method="POST" action="{{ route('admin.providers.test', $provider) }}" class="d-flex flex-wrap gap-2 align-items-end">
                                            @csrf
                                            <div>
                                                <label for="test-key-{{ $provider->getKey() }}" class="form-label small">{{ __('admin.providers_test_key_label') }}</label>
                                                <input id="test-key-{{ $provider->getKey() }}" name="api_key" type="password" autocomplete="off" class="form-control form-control-sm" placeholder="{{ __('admin.providers_test_key_placeholder') }}">
                                            </div>
                                            <button type="submit" class="btn btn-sm">{{ __('admin.providers_test') }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.providers.publish', $provider) }}" class="d-flex flex-wrap gap-2 align-items-end">
                                            @csrf
                                            <div>
                                                <label for="publish-key-{{ $provider->getKey() }}" class="form-label small">{{ __('admin.providers_publish_key_label') }}</label>
                                                <input id="publish-key-{{ $provider->getKey() }}" name="api_key" type="password" autocomplete="off" class="form-control form-control-sm" placeholder="{{ __('admin.providers_publish_key_placeholder') }}">
                                            </div>
                                            <button type="submit" class="btn btn-sm btn-primary">{{ __('admin.providers_publish') }}</button>
                                        </form>
                                        @if ($provider->usesResponsesApi())
                                            <form method="POST" action="{{ route('admin.providers.discover') }}" class="d-flex flex-wrap gap-2 align-items-end">
                                                @csrf
                                                <input type="hidden" name="provider_id" value="{{ $provider->getKey() }}">
                                                <div>
                                                    <label for="discover-key-{{ $provider->getKey() }}" class="form-label small">{{ __('admin.providers_discover_key_label') }}</label>
                                                    <input id="discover-key-{{ $provider->getKey() }}" name="api_key" type="password" autocomplete="off" class="form-control form-control-sm" placeholder="{{ __('admin.providers_test_key_placeholder') }}">
                                                </div>
                                                <button type="submit" class="btn btn-sm">{{ __('admin.providers_discover') }}</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>
        @endif
    </x-card>

    @if ($inContainer)
        <x-card :title="__('admin.providers_container_title')" :description="__('admin.providers_container_desc')">
            <p class="text-secondary small mb-0">
                {{ __('admin.providers_container_a') }} <code>.env</code> {{ __('admin.providers_container_b') }}
            </p>
        </x-card>
    @endif
@endsection
