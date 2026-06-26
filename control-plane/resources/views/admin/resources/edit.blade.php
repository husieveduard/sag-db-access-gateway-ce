@extends('layouts.app')

@section('title', __('resources.edit_page.title').' · '.__('ce.app_title'))

@section('content')
@php
    $engineLabels = [
        'mysql' => __('resources.engine.mysql'),
        'postgresql' => __('resources.engine.postgresql'),
        'mssql' => __('resources.engine.mssql'),
    ];

    $authLabels = [
        'client_passthrough' => __('resources.auth.client_passthrough'),
        'managed' => __('resources.auth.managed'),
    ];
@endphp

<a class="back-link" href="{{ route('admin.resources.show', $resource) }}">
    {{ __('resources.edit_page.back') }}
</a>

<h1 class="page-title">{{ __('resources.edit_page.heading') }}</h1>
<p class="subtitle">{{ $resource->name }}</p>

@if ($errors->has('resource'))
    <div class="form-error">{{ $errors->first('resource') }}</div>
@endif

@if ($resource->active_sessions_count > 0)
    <div class="warning">
        {{ __('resources.edit_page.active_sessions_warning', [
            'count' => $resource->active_sessions_count,
        ]) }}
        {{ __('resources.edit_page.active_sessions_notice') }}
    </div>
@endif

<form
    class="card form-card"
    method="POST"
    action="{{ route('admin.resources.update', $resource) }}"
>
    @csrf
    @method('PUT')

    <div class="form-grid">
        <div>
            <label for="name">{{ __('resources.edit_page.name') }}</label>

            <input
                id="name"
                name="name"
                type="text"
                maxlength="160"
                value="{{ old('name', $resource->name) }}"
                required
                autofocus
            >

            @error('name')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <div>
            <label for="engine">{{ __('resources.edit_page.engine') }}</label>

            <select id="engine" name="engine" required>
                @foreach ($engines as $value => $label)
                    <option
                        value="{{ $value }}"
                        @selected(old('engine', $resource->engine) === $value)
                    >
                        {{ $engineLabels[$value] ?? $label }}
                    </option>
                @endforeach
            </select>

            @error('engine')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <div>
            <label for="target_host">
                {{ __('resources.edit_page.target_host') }}
            </label>

            <input
                id="target_host"
                name="target_host"
                type="text"
                maxlength="253"
                value="{{ old('target_host', $resource->target_host) }}"
                required
            >

            @error('target_host')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <div>
            <label for="target_port">
                {{ __('resources.edit_page.target_port') }}
            </label>

            <input
                id="target_port"
                name="target_port"
                type="number"
                min="1"
                max="65535"
                value="{{ old('target_port', $resource->target_port) }}"
                required
            >

            @error('target_port')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <div class="full">
            <label for="target_database">
                {{ __('resources.edit_page.database') }}
            </label>

            <input
                id="target_database"
                name="target_database"
                type="text"
                maxlength="128"
                value="{{ old('target_database', $resource->target_database) }}"
            >

            @error('target_database')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <div class="full">
            <label for="description">
                {{ __('resources.edit_page.description') }}
            </label>

            <textarea
                id="description"
                name="description"
                maxlength="5000"
            >{{ old('description', $resource->description) }}</textarea>

            @error('description')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>
    </div>

    <div class="hint">
        {{ __('resources.edit_page.security_note_before') }}
        <code>{{ $authLabels[$resource->auth_mode] ?? $resource->auth_mode }}</code>.
        {{ __('resources.edit_page.security_note_after') }}
    </div>

    <div class="submit-row">
        <button class="button primary" type="submit">
            {{ __('resources.edit_page.submit') }}
        </button>

        <a class="button" href="{{ route('admin.resources.show', $resource) }}">
            {{ __('resources.edit_page.cancel') }}
        </a>
    </div>
</form>
@endsection
