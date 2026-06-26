@extends('layouts.app')

@section('title', __('sessions.create_page.title').' · '.__('ce.app_title'))

@section('content')
@php
    $modeNotes = [
        'temporary' => __('sessions.create_page.mode_notes.temporary'),
        'persistent' => __('sessions.create_page.mode_notes.persistent'),
    ];
@endphp

<a class="back-link" href="{{ route('admin.sessions.index') }}">
    {{ __('sessions.create_page.back') }}
</a>

<h1 class="page-title">{{ __('sessions.create_page.heading') }}</h1>

<p class="subtitle">{{ __('sessions.create_page.subtitle') }}</p>

@if ($resources->isEmpty())
    <div class="form-error">
        {{ __('sessions.create_page.no_resources') }}
    </div>
@else
    <form class="card form-card" method="POST" action="{{ route('admin.sessions.store') }}">
        @csrf

        <div class="form-grid">
            <div class="full">
                <label for="resource_id">{{ __('sessions.create_page.resource') }}</label>

                <select id="resource_id" name="resource_id" required autofocus>
                    <option value="">
                        {{ __('sessions.create_page.resource_placeholder') }}
                    </option>

                    @foreach ($resources as $resource)
                        <option
                            value="{{ $resource->id }}"
                            @selected((string) old('resource_id') === (string) $resource->id)
                        >
                            {{ $resource->name }}
                            — {{ $resource->engine }}
                            — {{ $resource->target_host }}:{{ $resource->target_port }}
                            @if ($resource->target_database)
                                / {{ $resource->target_database }}
                            @endif
                        </option>
                    @endforeach
                </select>

                @error('resource_id')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label for="mode">{{ __('sessions.create_page.connection_mode') }}</label>

                <select id="mode" name="mode" required>
                    <option
                        value="temporary"
                        @selected(old('mode', 'temporary') === 'temporary')
                    >
                        {{ __('sessions.create_page.temporary_option') }}
                    </option>

                    <option
                        value="persistent"
                        @selected(old('mode') === 'persistent')
                    >
                        {{ __('sessions.create_page.persistent_option') }}
                    </option>
                </select>

                <div id="mode-note" class="mode-note"></div>

                @error('mode')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div id="ttl-wrap">
                <label for="ttl_seconds">{{ __('sessions.create_page.ttl') }}</label>

                <input
                    id="ttl_seconds"
                    name="ttl_seconds"
                    type="number"
                    min="60"
                    max="604800"
                    value="{{ old('ttl_seconds', 3600) }}"
                >

                <div class="hint">{{ __('sessions.create_page.ttl_hint') }}</div>

                @error('ttl_seconds')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label for="source_cidr">{{ __('sessions.create_page.source_cidr') }}</label>

                <input
                    id="source_cidr"
                    name="source_cidr"
                    type="text"
                    maxlength="128"
                    value="{{ old('source_cidr') }}"
                    placeholder="10.10.10.1/32"
                    required
                >

                <div class="hint">
                    {{ __('sessions.create_page.source_cidr_hint') }}
                </div>

                @error('source_cidr')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label for="db_username">{{ __('sessions.create_page.db_username') }}</label>

                <input
                    id="db_username"
                    name="db_username"
                    type="text"
                    maxlength="128"
                    value="{{ old('db_username') }}"
                    placeholder="db_readonly_user"
                    required
                >

                <div class="hint">
                    {{ __('sessions.create_page.db_username_hint') }}
                </div>

                @error('db_username')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <div class="hint">
            {{ __('sessions.create_page.created_note') }}
            <code>{{ __('sessions.status.created') }}</code>.
            {{ __('sessions.create_page.created_action') }}
            <code>{{ __('sessions.create_page.start_session') }}</code>.
        </div>

        <div class="submit-row">
            <button class="button primary" type="submit">
                {{ __('sessions.create_page.submit') }}
            </button>

            <a class="button" href="{{ route('admin.sessions.index') }}">
                {{ __('sessions.create_page.cancel') }}
            </a>
        </div>
    </form>
@endif

<script>
(() => {
    const mode = document.getElementById('mode');
    const ttlWrap = document.getElementById('ttl-wrap');
    const ttl = document.getElementById('ttl_seconds');
    const note = document.getElementById('mode-note');
    const modeNotes = @json($modeNotes);

    if (!mode || !ttlWrap || !ttl || !note) {
        return;
    }

    const syncMode = () => {
        const temporary = mode.value === 'temporary';

        ttlWrap.hidden = !temporary;
        ttl.required = temporary;
        note.textContent = temporary
            ? modeNotes.temporary
            : modeNotes.persistent;
    };

    mode.addEventListener('change', syncMode);
    syncMode();
})();
</script>
@endsection
