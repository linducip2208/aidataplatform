@extends('layouts.app')

@section('title', 'Asisten AI')

@section('content')
    @php
        $roleLabels = [
            'user' => __('assistant.role_user'),
            'assistant' => __('assistant.role_assistant'),
            'system' => __('assistant.role_system'),
        ];
        $canWrite = auth()->user()->isAnalyst();
        $threadTitle = $thread ? ($thread->title ?: __('assistant.new_conversation')) : __('assistant.new_conversation');
    @endphp

    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="page-title">{{ __('assistant.title') }}</h1>
                <div class="page-subtitle">
                    {{ __('assistant.subtitle') }}
                </div>
            </div>
        </div>
    </div>

    <div class="row row-cards">
        <div class="col-lg-8">
            <x-card :title="$threadTitle" :description="__('assistant.thread_desc')">
                @if ($messages->isEmpty())
                    <x-empty-state
                        :title="__('assistant.empty_messages_title')"
                        description="{{ __('assistant.empty_messages_desc') }}"
                    />
                @else
                    <ul class="list-unstyled mb-0 d-grid gap-3">
                        @if (! empty($messagesTruncated))
                            <li class="alert alert-warning mb-0">
                                {{ __('assistant.truncated', ['count' => number_format($messages->count(), 0, ',', '.')]) }}
                            </li>
                        @endif
                        @foreach ($messages as $message)
                            @php
                                $role = strtolower((string) ($message->role ?? 'user'));
                                $isUser = $role === 'user';
                                $evidence = (array) ($message->evidence ?? []);
                            @endphp
                            <li class="d-flex {{ $isUser ? 'justify-content-end' : 'justify-content-start' }}">
                                <div class="card w-100 {{ $isUser ? 'bg-primary-lt' : '' }}">
                                    <div class="card-body">
                                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                            <span class="text-secondary small text-uppercase fw-bold">
                                                {{ $roleLabels[$role] ?? \Illuminate\Support\Str::headline($role) }}
                                            </span>
                                            @if ($message->steps)
                                                <x-badge variant="neutral">{{ __('assistant.steps_count', ['count' => number_format((int) $message->steps, 0, ',', '.')]) }}</x-badge>
                                            @endif
                                        </div>

                                        @if (filled($message->content))
                                            <p class="text-secondary mb-0" style="white-space: pre-line;">{{ $message->content }}</p>
                                        @else
                                            <p class="text-secondary mb-0">
                                                {{ __('assistant.empty_reply') }}
                                            </p>
                                        @endif

                                        @if ($evidence !== [])
                                            <div class="mt-3 pt-3 border-top">
                                                <p class="text-secondary small text-uppercase fw-bold mb-2">{{ __('assistant.evidence') }}</p>
                                                <ul class="list-unstyled mb-0 d-grid gap-2">
                                                    {{-- Evidence comes from the agent, one {source, data} row per tool
                                                         that ran. A tool that raised still produces a row whose data is
                                                         {"error": ...}: that is a degraded result to display, not a page
                                                         error. The cast keeps a non-array row from being indexed. --}}
                                                    @foreach ($evidence as $row)
                                                        @php
                                                            $item = (array) $row;
                                                            $data = (array) ($item['data'] ?? []);
                                                        @endphp
                                                        <li class="card card-body bg-light-lt py-2 px-3">
                                                            <p class="small fw-bold mb-1">{{ $item['source'] ?? __('assistant.unknown_source') }}</p>
                                                            <p class="small text-secondary text-break mb-0">
                                                                @if ($data === [])
                                                                    <span class="text-secondary">{{ __('assistant.no_data') }}</span>
                                                                @elseif (isset($data['error']))
                                                                    <span class="text-warning">{{ __('assistant.source_failed', ['error' => $data['error']]) }}</span>
                                                                @else
                                                                    {{ json_encode($data, JSON_UNESCAPED_UNICODE) }}
                                                                @endif
                                                            </p>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($canWrite)
                    <form method="POST" action="{{ route('assistant.store') }}" class="mt-3 pt-3 border-top">
                        @csrf

                        @if ($thread)
                            <input type="hidden" name="thread_id" value="{{ $thread->getKey() }}">
                        @endif

                        <x-field :label="__('assistant.question_label')" for="message" name="message" required>
                            <textarea
                                id="message"
                                name="message"
                                rows="3"
                                required
                                placeholder="{{ __('assistant.question_placeholder') }}"
                                @error('message') aria-invalid="true" @enderror
                                class="form-control"
                            >{{ old('message') }}</textarea>
                        </x-field>

                        <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                            <button
                                type="submit"
                                class="btn btn-primary"
                            ><x-icon name="send" />{{ __('assistant.submit') }}</button>
                            <a
                                href="{{ route('assistant.index') }}"
                                @if (! $thread || $thread->getKey() === $threads->first()?->getKey()) aria-current="page" @endif
                                class="btn"
                            >{{ __('assistant.back_to_latest') }}</a>
                        </div>
                    </form>
                @else
                    <div class="mt-3 pt-3 border-top">
                        <p class="text-secondary mb-0">
                            {{ __('assistant.readonly_prefix') }} <strong>{{ auth()->user()->role()->localizedLabel() }}</strong> {{ __('assistant.readonly_notice', ['admin' => \App\Enums\UserRole::Admin->localizedLabel(), 'analyst' => \App\Enums\UserRole::Analyst->localizedLabel()]) }}
                        </p>
                    </div>
                @endif
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card :title="__('assistant.threads_title')" description="{{ __('assistant.threads_desc', ['count' => number_format($threads->count(), 0, ',', '.')]) }}">
                @if ($threads->isEmpty())
                    <x-empty-state
                        :title="__('assistant.empty_threads_title')"
                        description="{{ __('assistant.empty_threads_desc') }}"
                    />
                @else
                    <ul class="list-group list-group-flush">
                        @foreach ($threads as $item)
                            @php $isCurrent = $thread && $item->getKey() === $thread->getKey(); @endphp
                            <li class="list-group-item px-0">
                                <div class="d-flex align-items-start justify-content-between gap-2">
                                    <div class="text-truncate">
                                        <a
                                            href="{{ route('assistant.threads.show', $item) }}"
                                            @if ($isCurrent) aria-current="page" @endif
                                            class="d-block text-truncate fw-medium {{ $isCurrent ? 'text-primary' : '' }}"
                                        >{{ $item->title ?: __('assistant.untitled') }}</a>
                                        <p class="small text-secondary mt-1 mb-0">
                                            {{ __('assistant.messages_count', ['count' => number_format((int) $item->message_count, 0, ',', '.')]) }}
                                            @if ($item->last_message_at)
                                                &middot; {{ $item->last_message_at->locale('id')->translatedFormat('d M Y H:i') }}
                                            @endif
                                        </p>
                                    </div>

                                    @if ($canWrite)
                                        <form
                                            method="POST"
                                            action="{{ route('assistant.threads.destroy', $item) }}"
                                            x-on:submit.confirm="{{ __('assistant.delete_confirm') }}"
                                        >
                                            @csrf
                                            @method('DELETE')
                                            <button
                                                type="submit"
                                                aria-label="{{ __('assistant.delete_aria', ['title' => $item->title ?: __('assistant.untitled_short')]) }}"
                                                class="btn btn-outline-danger btn-sm"
                                            >{{ __('assistant.delete') }}</button>
                                        </form>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>
    </div>
@endsection
