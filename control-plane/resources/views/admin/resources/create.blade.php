@extends('layouts.app')

@section('title', __('resources.create_page.title').' · '.__('ce.app_title'))

@section('content')
@php
    $engineLabels = [
        'mysql' => __('resources.engine.mysql'),
        'postgresql' => __('resources.engine.postgresql'),
        'mssql' => __('resources.engine.mssql'),
    ];
@endphp

<a class="back-link" href="{{ route('admin.resources.index') }}">
    {{ __('resources.create_page.back') }}
</a>

<h1 class="page-title">{{ __('resources.create_page.heading') }}</h1>

<p class="subtitle">{{ __('resources.create_page.subtitle') }}</p>

<form
    class="card form-card"
    method="POST"
    action="{{ route('admin.resources.store') }}"
>
    @csrf

    <div class="form-grid">
        <div>
            <label for="name">{{ __('resources.create_page.name') }}</label>

            <input
                id="name"
                name="name"
                type="text"
                maxlength="160"
                value="{{ old('name') }}"
                placeholder="{{ __('resources.create_page.name_placeholder') }}"
                required
                autofocus
            >

            @error('name')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <div>
            <label for="engine">{{ __('resources.create_page.engine') }}</label>

            <select id="engine" name="engine" required>
                @foreach ($engines as $value => $label)
                    <option
                        value="{{ $value }}"
                        @selected(old('engine', 'mysql') === $value)
                    >
                        {{ $engineLabels[$value] ?? $label }}
                    </option>
                @endforeach
            </select>

            <div class="hint">
                {{ __('resources.create_page.engine_hint') }}
            </div>

            @error('engine')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <div>
            <label for="target_host">
                {{ __('resources.create_page.target_host') }}
            </label>

            <input
                id="target_host"
                name="target_host"
                type="text"
                maxlength="253"
                value="{{ old('target_host') }}"
                placeholder="{{ __('resources.create_page.target_host_placeholder') }}"
                required
            >

            @error('target_host')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <div>
            <label for="target_port">
                {{ __('resources.create_page.target_port') }}
            </label>

            <input
                id="target_port"
                name="target_port"
                type="number"
                min="1"
                max="65535"
                value="{{ old('target_port', 3306) }}"
                required
            >

            @error('target_port')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <div class="full">
            <label for="target_database">
                {{ __('resources.create_page.database') }}
            </label>

            <input
                id="target_database"
                name="target_database"
                type="text"
                maxlength="128"
                value="{{ old('target_database') }}"
                placeholder="{{ __('resources.create_page.target_database_placeholder') }}"
            >

            <div class="hint">
                {{ __('resources.create_page.database_hint') }}
            </div>

            @error('target_database')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <div class="full">
            <label for="description">
                {{ __('resources.create_page.description') }}
            </label>

            <textarea
                id="description"
                name="description"
                maxlength="5000"
                placeholder="{{ __('resources.create_page.description_placeholder') }}"
            >{{ old('description') }}</textarea>

            @error('description')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>
    </div>

    <div class="hint">
        {{ __('resources.create_page.defaults_before') }}
        <code>client_passthrough</code>.
        {{ __('resources.create_page.defaults_after') }}
    </div>

    <div class="submit-row">
        <button class="button primary" type="submit">
            {{ __('resources.create_page.submit') }}
        </button>

        <a class="button" href="{{ route('admin.resources.index') }}">
            {{ __('resources.create_page.cancel') }}
        </a>
    </div>
</form>
@endsection
