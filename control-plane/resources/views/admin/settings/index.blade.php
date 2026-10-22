@extends('layouts.app')

@section('title', __('ce.settings.title').' · '.__('ce.app_title'))

@section('content')
<div class="ce-settings-page">
    <header class="ce-settings-header">
        <h1 class="page-title">{{ __('ce.settings.title') }}</h1>
        <p class="subtitle">{{ __('ce.settings.description') }}</p>
    </header>

    <section class="ce-settings-section">
        <h2>{{ __('ce.settings.access') }}</h2>
        <div class="ce-settings-cards">
            <a class="ce-settings-card" href="{{ route('admin.administrators.index') }}">
                <div class="ce-settings-card-title">{{ __('ce.nav.administrators') }} <span aria-hidden="true">→</span></div>
                <p>{{ __('ce.settings.admins_desc') }}</p>
            </a>
            <a class="ce-settings-card" href="{{ route('admin.ad-settings.index') }}">
                <div class="ce-settings-card-title">Active Directory <span aria-hidden="true">→</span></div>
                <p>{{ __('ce.settings.ad_desc') }}</p>
            </a>
        </div>
    </section>

    <section class="ce-settings-section">
        <h2>{{ __('ce.settings.alerts') }}</h2>
        <div class="ce-settings-cards">
            <a class="ce-settings-card" href="{{ route('admin.alert-channels.index') }}">
                <div class="ce-settings-card-title">{{ __('ce.nav.alert_channels') }} <span aria-hidden="true">→</span></div>
                <p>{{ __('ce.settings.channels_desc') }}</p>
            </a>
            <a class="ce-settings-card" href="{{ route('admin.alert-rules.index') }}">
                <div class="ce-settings-card-title">{{ __('alerts.rules_nav') }} <span aria-hidden="true">→</span></div>
                <p>{{ __('ce.settings.rules_desc') }}</p>
            </a>
        </div>
    </section>

    <section class="ce-settings-section">
        <h2>{{ __('ce.settings.future_title') }}</h2>
        <div class="ce-settings-future">
            <p>{{ __('ce.settings.future_desc') }}</p>
        </div>
    </section>
</div>
@endsection
