@extends('layouts.app')

@section('title', __('resources.title').' · '.__('ce.app_title'))

@section('content')
@php
    $notAvailable = __('ce.common.not_available');

    $engineLabels = [
        'mysql' => __('resources.engine.mysql'),
        'postgresql' => __('resources.engine.postgresql'),
        'mssql' => __('resources.engine.mssql'),
    ];

    $tlsLabels = [
        'prefer' => __('resources.tls.prefer'),
        'require' => __('resources.tls.require'),
        'disable' => __('resources.tls.disable'),
        'verify_ca' => __('resources.tls.verify_ca'),
        'verify_full' => __('resources.tls.verify_full'),
    ];

    $authLabels = [
        'client_passthrough' => __('resources.auth.client_passthrough'),
        'managed' => __('resources.auth.managed'),
    ];
@endphp

<div class="page-head">
    <div>
        <h1 class="page-title">{{ __('resources.heading') }}</h1>

        <p class="subtitle">{{ __('resources.subtitle') }}</p>
    </div>

    <a class="button primary" href="{{ route('admin.resources.create') }}">
        + {{ __('resources.create') }}
    </a>
</div>

<div class="card table-wrap">
    <table>
        <thead>
            <tr>
                <th>{{ __('resources.columns.resource') }}</th>
                <th>{{ __('resources.columns.engine') }}</th>
                <th>{{ __('resources.columns.target') }}</th>
                <th>{{ __('resources.columns.tls_auth') }}</th>
                <th>{{ __('resources.columns.sql_audit') }}</th>
                <th>{{ __('resources.columns.status') }}</th>
                <th>{{ __('resources.columns.sessions') }}</th>
                <th>{{ __('resources.columns.active_gateways') }}</th>
                <th>{{ __('resources.columns.open_connections') }}</th>
                <th>{{ __('resources.columns.created_by') }}</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($resources as $resource)
                <tr>
                    <td>
                        <a
                            class="resource-name"
                            href="{{ route('admin.resources.show', $resource) }}"
                        >
                            {{ $resource->name }}
                        </a>

                        <div class="muted">
                            {{ $resource->description ?: $notAvailable }}
                        </div>
                    </td>

                    <td>
                        <span class="badge">
                            {{ $engineLabels[$resource->engine] ?? $resource->engine }}
                        </span>
                    </td>

                    <td class="endpoint">
                        {{ $resource->target_host }}:{{ $resource->target_port }}

                        @if ($resource->target_database)
                            <br>
                            <span class="muted">
                                {{ $resource->target_database }}
                            </span>
                        @endif
                    </td>

                    <td>
                        {{ $tlsLabels[$resource->target_tls_mode]
                            ?? $resource->target_tls_mode }}<br>

                        <span class="muted">
                            {{ $authLabels[$resource->auth_mode]
                                ?? $resource->auth_mode }}
                        </span>
                    </td>

                    <td>
                        <span class="badge {{ $resource->query_audit_enabled ? 'green' : 'red' }}">
                            {{ $resource->query_audit_enabled
                                ? __('resources.audit.enabled')
                                : __('resources.audit.disabled') }}
                        </span>
                    </td>

                    <td>
                        <span class="badge {{ $resource->is_active ? 'green' : 'red' }}">
                            {{ $resource->is_active
                                ? __('resources.status.active')
                                : __('resources.status.inactive') }}
                        </span>
                    </td>

                    <td>{{ $resource->sessions_count }}</td>
                    <td>{{ $resource->active_sessions_count }}</td>
                    <td>{{ $resource->open_connections_count }}</td>

                    <td>
                        {{ $resource->createdBy?->name ?? $notAvailable }}<br>

                        <span class="muted">
                            {{ $resource->createdBy?->email ?? '' }}
                        </span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="muted">
                        {{ __('resources.empty') }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
