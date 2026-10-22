<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('ce.app_title'))</title>
    <link rel="stylesheet" href="{{ asset('css/sag-ce.css') }}?v=20261022-modern-nav-1">
</head>
<body>
    @php
        $currentLocale = app()->getLocale();
    @endphp

    @auth
    <div class="ce-app-shell">
        <aside class="ce-sidebar" aria-label="{{ __('ce.navigation') }}">
            <a class="ce-sidebar-brand" href="{{ route('admin.dashboard') }}">
                <span class="ce-brand-icon" aria-hidden="true">S</span>
                <span>{{ __('ce.product_name') }} <small>{{ __('ce.edition') }}</small></span>
            </a>
            <nav class="ce-side-nav" aria-label="{{ __('ce.navigation') }}">
                <div class="ce-nav-group-label">{{ __('ce.groups.workspace') }}</div>
                <a class="ce-side-link {{ request()->routeIs('admin.dashboard') ? 'is-current' : '' }}"
                    href="{{ route('admin.dashboard') }}" @if(request()->routeIs('admin.dashboard')) aria-current="page" @endif>
                    <span class="ce-nav-symbol" aria-hidden="true">◫</span>{{ __('ce.nav.dashboard') }}
                </a>
                <a class="ce-side-link {{ request()->routeIs('admin.resources.*') ? 'is-current' : '' }}"
                    href="{{ route('admin.resources.index') }}" @if(request()->routeIs('admin.resources.*')) aria-current="page" @endif>
                    <span class="ce-nav-symbol" aria-hidden="true">▤</span>{{ __('ce.nav.resources') }}
                </a>
                <a class="ce-side-link {{ request()->routeIs('admin.sessions.*') ? 'is-current' : '' }}"
                    href="{{ route('admin.sessions.index') }}" @if(request()->routeIs('admin.sessions.*')) aria-current="page" @endif>
                    <span class="ce-nav-symbol" aria-hidden="true">◷</span>{{ __('ce.nav.sessions') }}
                </a>

                <div class="ce-nav-group-label">{{ __('ce.groups.monitoring') }}</div>
                <a class="ce-side-link {{ request()->routeIs('admin.alerts.*') ? 'is-current' : '' }}"
                    href="{{ route('admin.alerts.index') }}" @if(request()->routeIs('admin.alerts.*')) aria-current="page" @endif>
                    <span class="ce-nav-symbol" aria-hidden="true">◇</span>{{ __('alerts.nav') }}
                </a>
                <a class="ce-side-link {{ request()->routeIs('admin.audit.*') ? 'is-current' : '' }}"
                    href="{{ route('admin.audit.index') }}" @if(request()->routeIs('admin.audit.*')) aria-current="page" @endif>
                    <span class="ce-nav-symbol" aria-hidden="true">≡</span>{{ __('ce.nav.audit') }}
                </a>
                @if(auth()->user()->isSuperAdmin())
                    <div class="ce-nav-group-label">{{ __('ce.groups.administration') }}</div>
                    <a class="ce-side-link {{ request()->routeIs('admin.alert-health.*') ? 'is-current' : '' }}"
                        href="{{ route('admin.alert-health.index') }}" @if(request()->routeIs('admin.alert-health.*')) aria-current="page" @endif>
                        <span class="ce-nav-symbol" aria-hidden="true">◉</span>{{ __('ce.nav.alert_health') }}
                    </a>
                    <a class="ce-side-link {{ request()->routeIs('admin.alert-channels.*') ? 'is-current' : '' }}"
                        href="{{ route('admin.alert-channels.index') }}" @if(request()->routeIs('admin.alert-channels.*')) aria-current="page" @endif>
                        <span class="ce-nav-symbol" aria-hidden="true">↗</span>{{ __('ce.nav.alert_channels') }}
                    </a>
                    <a class="ce-side-link {{ request()->routeIs('admin.alert-rules.*') ? 'is-current' : '' }}"
                        href="{{ route('admin.alert-rules.index') }}" @if(request()->routeIs('admin.alert-rules.*')) aria-current="page" @endif>
                        <span class="ce-nav-symbol" aria-hidden="true">☷</span>{{ __('alerts.rules_nav') }}
                    </a>
                    <a class="ce-side-link {{ request()->routeIs('admin.ad-settings.*') ? 'is-current' : '' }}"
                        href="{{ route('admin.ad-settings.index') }}" @if(request()->routeIs('admin.ad-settings.*')) aria-current="page" @endif>
                        <span class="ce-nav-symbol" aria-hidden="true">⌘</span>Active Directory
                    </a>
                    <a class="ce-side-link {{ request()->routeIs('admin.administrators.*') ? 'is-current' : '' }}"
                        href="{{ route('admin.administrators.index') }}" @if(request()->routeIs('admin.administrators.*')) aria-current="page" @endif>
                        <span class="ce-nav-symbol" aria-hidden="true">♙</span>{{ __('ce.nav.administrators') }}
                    </a>
                @endif
            </nav>
        </aside>

        <div class="ce-workspace">
            <header class="ce-workspace-header">
                <details class="ce-mobile-menu">
                    <summary>{{ __('ce.navigation') }} <span aria-hidden="true">☰</span></summary>
                    <nav aria-label="{{ __('ce.navigation') }}">
                        <a href="{{ route('admin.dashboard') }}">{{ __('ce.nav.dashboard') }}</a>
                        <a href="{{ route('admin.resources.index') }}">{{ __('ce.nav.resources') }}</a>
                        <a href="{{ route('admin.sessions.index') }}">{{ __('ce.nav.sessions') }}</a>
                        <a href="{{ route('admin.alerts.index') }}">{{ __('alerts.nav') }}</a>
                        <a href="{{ route('admin.audit.index') }}">{{ __('ce.nav.audit') }}</a>
                        @if(auth()->user()->isSuperAdmin())
                            <a href="{{ route('admin.alert-health.index') }}">{{ __('ce.nav.alert_health') }}</a>
                            <a href="{{ route('admin.alert-channels.index') }}">{{ __('ce.nav.alert_channels') }}</a>
                            <a href="{{ route('admin.alert-rules.index') }}">{{ __('alerts.rules_nav') }}</a>
                            <a href="{{ route('admin.ad-settings.index') }}">Active Directory</a>
                            <a href="{{ route('admin.administrators.index') }}">{{ __('ce.nav.administrators') }}</a>
                        @endif
                    </nav>
                </details>
                <span class="ce-workspace-context">{{ __('ce.app_title') }}</span>
                <div class="ce-account-actions">
                    <div class="locale-switcher" aria-label="{{ __('ce.language') }}">
                        @foreach (['uk' => 'UA', 'en' => 'EN'] as $locale => $label)
                            <form method="POST" action="{{ route('locale.update', ['locale' => $locale]) }}">
                                @csrf
                                <button class="locale-button {{ $currentLocale === $locale ? 'active' : '' }}"
                                    aria-label="{{ $label }}" type="submit">{{ $label }}</button>
                            </form>
                        @endforeach
                    </div>
                    <span class="ce-account-name">{{ auth()->user()->name }} · {{ auth()->user()->isSuperAdmin() ? __('ce.role.superadmin') : __('ce.role.admin') }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="logout" type="submit">{{ __('ce.logout') }}</button>
                    </form>
                </div>
            </header>
    @endauth
    @guest
    <header class="topbar topbar-guest">
        <a class="brand" href="{{ route('login') }}">{{ __('ce.product_name') }} <small>{{ __('ce.edition') }}</small></a>
        <div class="locale-switcher" aria-label="{{ __('ce.language') }}">
            @foreach (['uk' => 'UA', 'en' => 'EN'] as $locale => $label)
                <form method="POST" action="{{ route('locale.update', ['locale' => $locale]) }}">
                    @csrf
                    <button class="locale-button {{ $currentLocale === $locale ? 'active' : '' }}"
                        type="submit" aria-label="{{ $label }}">{{ $label }}</button>
                </form>
            @endforeach
        </div>
    </header>
    @endguest

    <main class="container">
        @if (session('success'))
            <div class="flash">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class="form-error">{{ $errors->first() }}</div>
        @endif

        @yield('content')
    </main>
    @auth
        </div>
    </div>
    @endauth
</body>
</html>
