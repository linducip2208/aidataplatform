@extends('layouts.app')

@section('title', 'Pengguna')

@section('content')
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">{{ __('admin.users_header_title') }}</h1>
                <div class="page-subtitle">
                    {{ __('admin.users_header_sub') }}
                </div>
            </div>
        </div>
    </div>

    <x-card class="mb-3" :title="__('admin.users_filter_title')" :description="__('admin.users_filter_desc')">
        <form method="GET" action="{{ route('admin.users.index') }}">
            <div class="row row-cards">
                <div class="col-md-4">
                    <x-field :label="__('admin.users_search_label')" for="q" :hint="__('admin.users_search_hint')">
                        <input
                            id="q"
                            name="q"
                            type="search"
                            value="{{ $filters['q'] ?? '' }}"
                            placeholder="{{ __('admin.users_search_placeholder') }}"
                            @error('q') aria-invalid="true" @enderror
                            class="form-control"
                        >
                    </x-field>
                </div>

                <div class="col-md-4">
                    <x-field :label="__('admin.users_role_label')" for="role">
                        <select
                            id="role"
                            name="role"
                            class="form-select"
                        >
                                <option value="">{{ __('admin.users_role_all') }}</option>
                                @foreach ($roles as $role)
                                    <option value="{{ $role->value }}" @selected(($filters['role'] ?? '') === $role->value)>{{ $role->localizedLabel() }}</option>
                                @endforeach
                        </select>
                    </x-field>
                </div>

                <div class="col-md-4">
                    <div class="d-flex align-items-end gap-2 h-100">
                        <button
                            type="submit"
                            class="btn btn-primary flex-fill"
                        >{{ __('admin.users_apply') }}</button>
                        <a
                            href="{{ route('admin.users.index') }}"
                            class="btn"
                        >{{ __('admin.users_reset') }}</a>
                    </div>
                </div>
            </div>
        </form>
    </x-card>

    <div class="row row-cards">
        <div class="col-lg-8">
            <x-card :title="__('admin.users_list_title')" :description="__('admin.users_list_desc', ['count' => number_format($users->total(), 0, ',', '.')])">
                @if ($users->isEmpty())
                <x-empty-state
                    :title="__('admin.users_empty_title')"
                    :description="__('admin.users_empty_desc')"
                />
            @else
                <ul class="list-unstyled mb-0 d-grid gap-3">
                    @foreach ($users as $user)
                        @php $isSelf = $user->getKey() === auth()->id(); @endphp
                        <li class="card">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-lg-6">
                                        <p class="fw-bold mb-1">
                                            {{ $user->name }}
                                            @if ($isSelf)
                                                <span class="small fw-normal text-secondary">{{ __('admin.users_you') }}</span>
                                            @endif
                                        </p>
                                        <p class="small text-secondary text-break">{{ $user->email }}</p>

                                        <dl class="datagrid mt-3">
                                            <div class="datagrid-item">
                                                <dt class="datagrid-title">{{ __('admin.users_th_role') }}</dt>
                                                <dd class="datagrid-content"><x-badge variant="info">{{ $user->role()->localizedLabel() }}</x-badge></dd>
                                            </div>
                                            <div class="datagrid-item">
                                                <dt class="datagrid-title">{{ __('admin.users_th_status') }}</dt>
                                                <dd class="datagrid-content">
                                                    @if ($user->is_active)
                                                        <x-badge variant="success">{{ __('admin.users_active') }}</x-badge>
                                                    @else
                                                        <x-badge variant="danger">{{ __('admin.users_inactive') }}</x-badge>
                                                    @endif
                                                </dd>
                                            </div>
                                            <div class="datagrid-item">
                                                <dt class="datagrid-title">{{ __('admin.users_th_last_login') }}</dt>
                                                <dd class="datagrid-content">
                                                    {{ $user->last_login_at ? $user->last_login_at->locale('id')->translatedFormat('d M Y H:i') : __('admin.users_never') }}
                                                </dd>
                                            </div>
                                        </dl>
                                    </div>

                                    <div class="col-lg-6">
                                        <form method="POST" action="{{ route('admin.users.update', $user) }}" class="pt-3 mt-3 border-top">
                                            @csrf
                                            @method('PATCH')

                                            <p class="fw-bold">{{ __('admin.users_edit_title') }}</p>

                                            <x-field :label="__('admin.users_name_label')" for="name-{{ $user->getKey() }}" name="name" required>
                                                <input
                                                    id="name-{{ $user->getKey() }}"
                                                    name="name"
                                                    type="text"
                                                    value="{{ old('name', $user->name) }}"
                                                    required
                                                    maxlength="100"
                                                    @error('name') aria-invalid="true" @enderror
                                                    class="form-control"
                                                >
                                            </x-field>

                                            <x-field :label="__('admin.users_email_label')" for="email-{{ $user->getKey() }}" name="email" required>
                                                <input
                                                    id="email-{{ $user->getKey() }}"
                                                    name="email"
                                                    type="email"
                                                    value="{{ old('email', $user->email) }}"
                                                    required
                                                    @error('email') aria-invalid="true" @enderror
                                                    class="form-control"
                                                >
                                            </x-field>

                                            <x-field :label="__('admin.users_role_label')" for="role-{{ $user->getKey() }}" name="role" required>
                                                <select
                                                    id="role-{{ $user->getKey() }}"
                                                    name="role"
                                                    required
                                                    @error('role') aria-invalid="true" @enderror
                                                    class="form-select"
                                                >
                                                    @foreach ($roles as $role)
                                                        <option value="{{ $role->value }}" @selected(old('role', $user->role->value) === $role->value)>
                                                            {{ $role->localizedLabel() }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </x-field>

                                            <div class="form-check mb-3">
                                                {{-- The controller validates is_active as `sometimes`,
                                                     so an unchecked box that sends nothing would leave the
                                                     account active. The hidden field is what carries the
                                                     "0" when the box is cleared. --}}
                                                <input type="hidden" name="is_active" value="0">
                                                <input
                                                    id="is_active-{{ $user->getKey() }}"
                                                    name="is_active"
                                                    type="checkbox"
                                                    value="1"
                                                    @checked(old('is_active', $user->is_active ? '1' : '0') === '1')
                                                    class="form-check-input"
                                                >
                                                <label for="is_active-{{ $user->getKey() }}" class="form-check-label">{{ __('admin.users_active_label') }}</label>
                                            </div>

                                            <button
                                                type="submit"
                                                class="btn btn-primary w-100"
                                            >{{ __('admin.users_save') }}</button>

                                            @if ($isSelf)
                                                <p class="small text-secondary mt-2 mb-0">
                                                    {{ __('admin.users_self_note') }}
                                                </p>
                                            @else
                                                <button
                                                    type="submit"
                                                    form="delete-user-{{ $user->getKey() }}"
                                                    class="btn btn-outline-danger w-100 mt-2"
                                                >{{ __('admin.users_delete') }}</button>
                                            @endif
                                        </form>
                                    </div>
                                </div>
                            </div>

                            @unless ($isSelf)
                                <form
                                    id="delete-user-{{ $user->getKey() }}"
                                    method="POST"
                                    action="{{ route('admin.users.destroy', $user) }}"
                                    x-on:submit.confirm="{{ __('admin.users_delete_confirm') }}"
                                >
                                    @csrf
                                    @method('DELETE')
                                </form>
                            @endunless
                        </li>
                    @endforeach
                </ul>


                <div class="mt-3">
                    {{ $users->links() }}
                </div>
            @endif
        </x-card>
        </div>

        <div class="col-lg-4">
            <x-card :title="__('admin.users_create_title')" :description="__('admin.users_create_desc')">
                <form method="POST" action="{{ route('admin.users.store') }}">
                    @csrf

                    <x-field :label="__('admin.users_name_label')" for="new-name" name="name" required>
                        <input
                            id="new-name"
                            name="name"
                            type="text"
                            value="{{ old('name') }}"
                            required
                            maxlength="100"
                            @error('name') aria-invalid="true" @enderror
                            class="form-control"
                        >
                    </x-field>

                    <x-field :label="__('admin.users_email_label')" for="new-email" name="email" required>
                        <input
                            id="new-email"
                            name="email"
                            type="email"
                            value="{{ old('email') }}"
                            required
                            autocomplete="off"
                            @error('email') aria-invalid="true" @enderror
                            class="form-control"
                        >
                    </x-field>

                    <x-field :label="__('admin.users_password_label')" for="new-password" name="password" required>
                        <input
                            id="new-password"
                            name="password"
                            type="password"
                            required
                            autocomplete="new-password"
                            @error('password') aria-invalid="true" @enderror
                            class="form-control"
                        >
                    </x-field>

                    <x-field :label="__('admin.users_role_label')" for="new-role" name="role" required :hint="__('admin.users_role_hint')">
                        <select
                            id="new-role"
                            name="role"
                            required
                            @error('role') aria-invalid="true" @enderror
                            class="form-select"
                        >
                            @foreach ($roles as $role)
                                <option value="{{ $role->value }}" @selected(old('role') === $role->value)>
                                    {{ $role->localizedLabel() }} - {{ $role->description() }}
                                </option>
                            @endforeach
                        </select>
                    </x-field>

                    <button
                        type="submit"
                        class="btn btn-primary w-100"
                    >{{ __('admin.users_create_title') }}</button>
                </form>
            </x-card>

            <x-card :title="__('admin.users_roles_title')" :description="__('admin.users_roles_desc')">
                <ul class="list-unstyled mb-0 d-grid gap-3">
                    @foreach ($roles as $role)
                        <li>
                            <p class="fw-bold mb-1">{{ $role->localizedLabel() }}</p>
                            <p class="text-secondary mb-0">{{ $role->description() }}</p>
                        </li>
                    @endforeach
                </ul>
            </x-card>
        </div>
    </div>
@endsection
