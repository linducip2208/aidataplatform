@extends('layouts.app')

@section('title', $provider->exists ? 'Ubah provider' : 'Tambah provider')

@section('content')
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <nav aria-label="{{ __('admin.providers_form_breadcrumb_aria') }}" class="mb-2">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('admin.providers.index') }}">{{ __('admin.providers_form_breadcrumb') }}</a></li>
                        <li class="breadcrumb-item active" aria-current="page">{{ $provider->exists ? __('admin.providers_form_edit_crumb') : __('admin.providers_form_add_crumb') }}</li>
                    </ol>
                </nav>
                <h1 class="page-title">{{ $provider->exists ? __('admin.providers_form_edit_title') : __('admin.providers_form_add_title') }}</h1>
                <p class="page-subtitle">{{ __('admin.providers_form_sub') }}</p>
            </div>
        </div>
    </div>

    <x-card :title="__('admin.providers_form_card_title')">
        <form method="POST" action="{{ $action }}" class="row row-cards">
            @csrf
            @if ($method !== 'POST')
                @method($method)
            @endif

            <div class="col-md-6">
                <x-field :label="__('admin.providers_form_name_label')" for="provider_name" name="name" required>
                    <input id="provider_name" name="name" type="text" value="{{ old('name', $provider->name) }}" required maxlength="128" placeholder="{{ __('admin.providers_form_name_placeholder') }}" @error('name') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>

            <div class="col-md-6">
                <x-field :label="__('admin.providers_form_type_label')" for="provider_type" name="provider_type" required>
                    <select id="provider_type" name="provider_type" required @error('provider_type') aria-invalid="true" @enderror class="form-select">
                        @foreach (\App\Models\AiProvider::TYPES as $value => $label)
                            <option value="{{ $value }}" @selected(old('provider_type', $provider->provider_type) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>

            <div class="col-12">
                <x-field :label="__('admin.providers_form_base_url_label')" for="provider_base_url" name="base_url" required :hint="__('admin.providers_form_base_url_hint')">
                    <input id="provider_base_url" name="base_url" type="url" value="{{ old('base_url', $provider->base_url) }}" required maxlength="512" placeholder="{{ __('admin.providers_form_base_url_placeholder') }}" @error('base_url') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>

            <div class="col-md-6">
                <x-field :label="__('admin.providers_form_model_label')" for="provider_model" name="model" required>
                    <input id="provider_model" name="model" type="text" list="discovered-models" value="{{ old('model', $provider->model) }}" required maxlength="256" placeholder="{{ __('admin.providers_form_model_placeholder') }}" @error('model') aria-invalid="true" @enderror class="form-control">
                    <datalist id="discovered-models">
                        @if ($provider->exists)
                            @foreach ($provider->models()->where('is_active', true)->orderBy('external_id')->get() as $discovered)
                                <option value="{{ $discovered->external_id }}">{{ $discovered->name ?? $discovered->external_id }}</option>
                            @endforeach
                        @endif
                    </datalist>
                </x-field>
            </div>

            <div class="col-md-6">
                <x-field :label="__('admin.providers_form_embedding_label')" for="provider_embedding" name="embedding_model" :hint="__('admin.providers_form_embedding_hint')">
                    <input id="provider_embedding" name="embedding_model" type="text" value="{{ old('embedding_model', $provider->embedding_model) }}" maxlength="256" @error('embedding_model') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>

            <div class="col-12">
                <x-field :label="__('admin.providers_form_capabilities_label')" for="provider_capabilities" name="capabilities" :hint="__('admin.providers_form_capabilities_hint')">
                    <input id="provider_capabilities" name="capabilities" type="text" value="{{ old('capabilities', is_array($provider->capabilities) ? implode(', ', $provider->capabilities) : '') }}" maxlength="2000" @error('capabilities') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>

            <div class="col-md-4">
                <x-field :label="__('admin.providers_form_in_price_label')" for="provider_in_price" name="input_price_per_million">
                    <input id="provider_in_price" name="input_price_per_million" type="number" step="any" min="0" value="{{ old('input_price_per_million', $provider->input_price_per_million) }}" @error('input_price_per_million') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>

            <div class="col-md-4">
                <x-field :label="__('admin.providers_form_out_price_label')" for="provider_out_price" name="output_price_per_million">
                    <input id="provider_out_price" name="output_price_per_million" type="number" step="any" min="0" value="{{ old('output_price_per_million', $provider->output_price_per_million) }}" @error('output_price_per_million') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>

            <div class="col-md-6">
                <x-field :label="__('admin.providers_form_api_key_label')" for="provider_api_key" name="api_key" :hint="__('admin.providers_form_api_key_hint')">
                    <input id="provider_api_key" name="api_key" type="password" autocomplete="off" value="" maxlength="512" placeholder="{{ __('admin.providers_form_api_key_placeholder') }}" class="form-control">
                </x-field>
            </div>

            <div class="col-md-6">
                <x-field :label="__('admin.providers_form_protocol_label')" for="provider_protocol" name="protocol" :hint="__('admin.providers_form_protocol_hint')">
                    <select id="provider_protocol" name="protocol" @error('protocol') aria-invalid="true" @enderror class="form-select">
                        <option value="">{{ __('admin.providers_form_protocol_auto') }}</option>
                        <option value="responses" @selected(old('protocol', $provider->protocol) === 'responses')>Responses API (POST /responses)</option>
                        <option value="chat-completions" @selected(old('protocol', $provider->protocol) === 'chat-completions')>Chat Completions (POST /chat/completions)</option>
                    </select>
                </x-field>
            </div>

            <div class="col-md-4">
                <x-field :label="__('admin.providers_form_timeout_label')" for="provider_timeout" name="timeout_seconds" :hint="__('admin.providers_form_timeout_hint')">
                    <input id="provider_timeout" name="timeout_seconds" type="number" min="1" max="600" value="{{ old('timeout_seconds', $provider->timeout_seconds) }}" @error('timeout_seconds') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>

            <div class="col-md-4">
                <x-field :label="__('admin.providers_form_retries_label')" for="provider_retries" name="max_retries" :hint="__('admin.providers_form_retries_hint')">
                    <input id="provider_retries" name="max_retries" type="number" min="0" max="10" value="{{ old('max_retries', $provider->max_retries) }}" @error('max_retries') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>

            <div class="col-md-4">
                <x-field :label="__('admin.providers_form_priority_label')" for="provider_priority" name="priority" :hint="__('admin.providers_form_priority_hint')">
                    <input id="provider_priority" name="priority" type="number" min="0" max="100000" value="{{ old('priority', $provider->priority ?? 100) }}" @error('priority') aria-invalid="true" @enderror class="form-control">
                </x-field>
            </div>

            <div class="col-12">
                <label class="form-check">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $provider->is_active ?? true)) class="form-check-input">
                    <span class="form-check-label">{{ __('admin.providers_form_active_label') }}</span>
                </label>
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary"><x-icon name="check" />{{ $provider->exists ? __('admin.providers_form_save') : __('admin.providers_form_create') }}</button>
                <a href="{{ route('admin.providers.index') }}" class="btn">{{ __('admin.providers_form_cancel') }}</a>
            </div>
        </form>
    </x-card>

    <x-card class="mt-3" :title="__('admin.providers_probe_title')" description="{{ __('admin.providers_probe_desc') }}">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <button type="button" id="btn-test-connection" class="btn">
                <span class="spinner-border spinner-border-sm me-1 d-none" role="status" aria-hidden="true"></span>{{ __('admin.providers_btn_test_connection') }}
            </button>
            <button type="button" id="btn-discover-models" class="btn">
                <span class="spinner-border spinner-border-sm me-1 d-none" role="status" aria-hidden="true"></span>{{ __('admin.providers_btn_discover') }}
            </button>
            <button type="button" id="btn-test-model" class="btn btn-primary" @if ($provider->exists) data-test-model-url="{{ route('admin.providers.test-model', $provider) }}" @endif>
                <span class="spinner-border spinner-border-sm me-1 d-none" role="status" aria-hidden="true"></span>{{ __('admin.providers_btn_test_model') }}
            </button>
        </div>
        <div id="probe-result" role="status" aria-live="polite"></div>
        <div id="discovered-list" class="mt-3"></div>
    </x-card>
@endsection

@push('scripts')
    <script>
        (function () {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
            const resultBox = document.getElementById('probe-result');
            const listBox = document.getElementById('discovered-list');
            const providerId = {{ $provider->exists ? $provider->getKey() : 'null' }};
            const opencodeGoBase = @json($opencodeGoBaseUrl);

            function setLoading(button, loading) {
                button.disabled = loading;
                button.querySelector('.spinner-border')?.classList.toggle('d-none', !loading);
            }

            function formValues() {
                return {
                    provider_type: document.getElementById('provider_type').value,
                    base_url: document.getElementById('provider_base_url').value,
                    model: document.getElementById('provider_model').value,
                    protocol: document.getElementById('provider_protocol').value || null,
                    timeout_seconds: document.getElementById('provider_timeout').value || null,
                    max_retries: document.getElementById('provider_retries').value || null,
                    api_key: document.getElementById('provider_api_key').value,
                };
            }

            function showResult(ok, title, body) {
                resultBox.innerHTML = '';
                const alert = document.createElement('div');
                alert.className = 'alert alert-' + (ok ? 'success' : 'danger');
                alert.setAttribute('role', 'alert');
                const strong = document.createElement('strong');
                strong.textContent = title;
                alert.appendChild(strong);
                if (body) {
                    const divider = document.createElement('div');
                    divider.className = 'small mt-1';
                    divider.textContent = body;
                    alert.appendChild(divider);
                }
                resultBox.appendChild(alert);
            }

            async function postJson(url, payload) {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
                    body: JSON.stringify(payload),
                });
                return response.json();
            }

            async function run(button, fn) {
                setLoading(button, true);
                try {
                    await fn();
                } catch (error) {
                    showResult(false, 'Network error', String(error?.message ?? error));
                } finally {
                    setLoading(button, false);
                }
            }

            document.getElementById('btn-test-connection').addEventListener('click', function () {
                run(this, async () => {
                    const data = await postJson(@json(route('admin.providers.probe')), {...formValues(), action: 'connection'});
                    showResult(data.ok === true, data.ok === true ? 'OK' : 'Failed', data.note ?? '');
                });
            });

            document.getElementById('btn-test-model').addEventListener('click', function () {
                run(this, async () => {
                    const values = formValues();
                    let data;
                    const savedUrl = this.dataset.testModelUrl || null;
                    if (savedUrl) {
                        data = await postJson(savedUrl, {api_key: values.api_key, model: values.model || null});
                    } else {
                        data = await postJson(@json(route('admin.providers.probe')), {...values, action: 'model'});
                    }
                    const detail = (data.note ?? '') + (data.text ? ' — ' + data.text : '');
                    showResult(data.ok === true, data.ok === true ? 'OK' : 'Failed', detail);
                });
            });

            document.getElementById('btn-discover-models').addEventListener('click', function () {
                run(this, async () => {
                    const values = formValues();
                    const payload = providerId ? {provider_id: providerId, api_key: values.api_key} : {...values, api_key: values.api_key};
                    const data = await postJson(@json(route('admin.providers.discover')), payload);
                    listBox.innerHTML = '';
                    if (data.ok !== true) {
                        showResult(false, 'Failed', data.note ?? '');
                        return;
                    }
                    showResult(true, 'OK', data.note ?? '');
                    const table = document.createElement('table');
                    table.className = 'table table-vcenter card-table mt-2';
                    const thead = document.createElement('thead');
                    thead.innerHTML = '<tr><th scope="col">Model</th><th scope="col">Capabilities</th><th scope="col"></th></tr>';
                    table.appendChild(thead);
                    const tbody = document.createElement('tbody');
                    const datalist = document.getElementById('discovered-models');
                    (data.models ?? []).forEach((model) => {
                        const option = document.createElement('option');
                        option.value = model.external_id;
                        option.textContent = model.name ?? model.external_id;
                        datalist.appendChild(option);
                        const row = document.createElement('tr');
                        const idCell = document.createElement('td');
                        const code = document.createElement('code');
                        code.textContent = model.external_id;
                        idCell.appendChild(code);
                        if (model.name) {
                            const small = document.createElement('div');
                            small.className = 'small text-secondary';
                            small.textContent = model.name;
                            idCell.appendChild(small);
                        }
                        const capCell = document.createElement('td');
                        (model.capabilities ?? []).forEach((capability) => {
                            const badge = document.createElement('span');
                            badge.className = 'badge badge-neutral me-1';
                            badge.textContent = capability;
                            capCell.appendChild(badge);
                        });
                        if ((model.capabilities ?? []).length === 0) {
                            capCell.textContent = '—';
                        }
                        const pickCell = document.createElement('td');
                        pickCell.className = 'text-end';
                        const pick = document.createElement('button');
                        pick.type = 'button';
                        pick.className = 'btn btn-sm';
                        pick.textContent = 'Use';
                        pick.addEventListener('click', () => {
                            document.getElementById('provider_model').value = model.external_id;
                        });
                        pickCell.appendChild(pick);
                        row.append(idCell, capCell, pickCell);
                        tbody.appendChild(row);
                    });
                    table.appendChild(tbody);
                    listBox.appendChild(table);
                });
            });

            document.getElementById('provider_type').addEventListener('change', function () {
                if (this.value === 'opencode-go') {
                    const base = document.getElementById('provider_base_url');
                    if (!base.value) {
                        base.value = opencodeGoBase;
                    }
                }
            });
        })();
    </script>
@endpush
