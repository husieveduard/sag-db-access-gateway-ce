<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="ce-display-timezone" content="{{ app(\App\Services\Settings\DisplayClock::class)->timezone() }}">
    <title>@yield('title', __('ce.app_title'))</title>
    <link rel="stylesheet" href="{{ asset('css/sag-ce.css') }}?v=20261022-audit-ux-1">
    <script src="{{ asset('js/ce-ui.js') }}?v=20261022-timezone-alerts-1" defer></script>
</head>
<body>
    @php
        $currentLocale = app()->getLocale();
    @endphp

    @php
        $settingsActive = request()->routeIs('admin.settings.*')
            || request()->routeIs('admin.ad-settings.*')
            || request()->routeIs('admin.administrators.*')
            || request()->routeIs('admin.alert-channels.*')
            || request()->routeIs('admin.alert-rules.*');
    @endphp

    @auth
    <div class="ce-app-shell">
        <aside class="ce-sidebar" aria-label="{{ __('ce.navigation') }}">
            <a class="ce-sidebar-brand" href="{{ route('admin.dashboard') }}" title="{{ __('ce.nav.dashboard') }}" aria-label="{{ __('ce.nav.dashboard') }}">
                <span class="ce-brand-icon" aria-hidden="true">S</span>
                <span class="ce-brand-text">{{ __('ce.product_name') }} <small>{{ __('ce.edition') }}</small></span>
            </a>
            <button id="ce-sidebar-toggle" type="button" class="ce-sidebar-toggle"
                aria-label="{{ __('ce.sidebar.collapse') }}"
                title="{{ __('ce.sidebar.collapse') }}"
                aria-expanded="true"
                data-ce-label-expand="{{ __('ce.sidebar.expand') }}"
                data-ce-label-collapse="{{ __('ce.sidebar.collapse') }}">
                <span class="ce-sidebar-toggle-icon" aria-hidden="true">‹</span>
                <span class="ce-nav-text">{{ __('ce.sidebar.collapse') }}</span>
            </button>
            <nav class="ce-side-nav" aria-label="{{ __('ce.navigation') }}">
                <div class="ce-nav-group-label">{{ __('ce.groups.workspace') }}</div>
                <a class="ce-side-link {{ request()->routeIs('admin.dashboard') ? 'is-current' : '' }}"
                    href="{{ route('admin.dashboard') }}" @if(request()->routeIs('admin.dashboard')) aria-current="page" @endif>
                    <span class="ce-nav-symbol" aria-hidden="true">◫</span><span class="ce-nav-text">{{ __('ce.nav.dashboard') }}</span>
                </a>
                <a class="ce-side-link {{ request()->routeIs('admin.resources.*') ? 'is-current' : '' }}"
                    href="{{ route('admin.resources.index') }}" title="{{ __('ce.nav.resources') }}" aria-label="{{ __('ce.nav.resources') }}" @if(request()->routeIs('admin.resources.*')) aria-current="page" @endif>
                    <span class="ce-nav-symbol" aria-hidden="true">▤</span><span class="ce-nav-text">{{ __('ce.nav.resources') }}</span>
                </a>
                <a class="ce-side-link {{ request()->routeIs('admin.sessions.*') ? 'is-current' : '' }}"
                    href="{{ route('admin.sessions.index') }}" title="{{ __('ce.nav.sessions') }}" aria-label="{{ __('ce.nav.sessions') }}" @if(request()->routeIs('admin.sessions.*')) aria-current="page" @endif>
                    <span class="ce-nav-symbol" aria-hidden="true">◷</span><span class="ce-nav-text">{{ __('ce.nav.sessions') }}</span>
                </a>

                <div class="ce-nav-group-label">{{ __('ce.groups.monitoring') }}</div>
                <a class="ce-side-link {{ request()->routeIs('admin.alerts.*') ? 'is-current' : '' }}"
                    href="{{ route('admin.alerts.index') }}" title="{{ __('alerts.nav') }}" aria-label="{{ __('alerts.nav') }}" @if(request()->routeIs('admin.alerts.*')) aria-current="page" @endif>
                    <span class="ce-nav-symbol" aria-hidden="true">◇</span><span class="ce-nav-text">{{ __('alerts.nav') }}</span>
                </a>
                @if(auth()->user()->isSuperAdmin())
                    <a class="ce-side-link {{ request()->routeIs('admin.alert-health.*') ? 'is-current' : '' }}"
                        href="{{ route('admin.alert-health.index') }}" title="{{ __('ce.nav.alert_health') }}" aria-label="{{ __('ce.nav.alert_health') }}" @if(request()->routeIs('admin.alert-health.*')) aria-current="page" @endif>
                        <span class="ce-nav-symbol" aria-hidden="true">◉</span><span class="ce-nav-text">{{ __('ce.nav.alert_health') }}</span>
                    </a>
                @endif
                <a class="ce-side-link {{ request()->routeIs('admin.audit.*') ? 'is-current' : '' }}"
                    href="{{ route('admin.audit.index') }}" title="{{ __('ce.nav.audit') }}" aria-label="{{ __('ce.nav.audit') }}" @if(request()->routeIs('admin.audit.*')) aria-current="page" @endif>
                    <span class="ce-nav-symbol" aria-hidden="true">≡</span><span class="ce-nav-text">{{ __('ce.nav.audit') }}</span>
                </a>
                @if(auth()->user()->isSuperAdmin())
                    <div class="ce-nav-group-label">{{ __('ce.groups.administration') }}</div>
                    <details class="ce-settings-group" @if($settingsActive) open @endif>
                        <summary class="ce-side-link {{ $settingsActive ? 'is-current' : '' }}" title="{{ __('ce.nav.settings') }}" aria-label="{{ __('ce.nav.settings') }}">
                            <span class="ce-nav-symbol" aria-hidden="true">⚙</span>
                            <span class="ce-nav-text">{{ __('ce.nav.settings') }}</span>
                            <span class="ce-settings-chevron" aria-hidden="true">⌄</span>
                        </summary>
                        <div class="ce-settings-subnav">
                            <a class="ce-sub-link {{ request()->routeIs('admin.settings.*') ? 'is-current' : '' }}"
                                href="{{ route('admin.settings.index') }}" @if(request()->routeIs('admin.settings.*')) aria-current="page" @endif>
                                {{ __('ce.nav.settings_overview') }}
                            </a>
                            <a class="ce-sub-link {{ request()->routeIs('admin.administrators.*') ? 'is-current' : '' }}"
                                href="{{ route('admin.administrators.index') }}" @if(request()->routeIs('admin.administrators.*')) aria-current="page" @endif>
                                {{ __('ce.nav.administrators') }}
                            </a>
                            <a class="ce-sub-link {{ request()->routeIs('admin.ad-settings.*') ? 'is-current' : '' }}"
                                href="{{ route('admin.ad-settings.index') }}" @if(request()->routeIs('admin.ad-settings.*')) aria-current="page" @endif>
                                Active Directory
                            </a>
                            <a class="ce-sub-link {{ request()->routeIs('admin.alert-channels.*') ? 'is-current' : '' }}"
                                href="{{ route('admin.alert-channels.index') }}" @if(request()->routeIs('admin.alert-channels.*')) aria-current="page" @endif>
                                {{ __('ce.nav.alert_channels') }}
                            </a>
                            <a class="ce-sub-link {{ request()->routeIs('admin.alert-rules.*') ? 'is-current' : '' }}"
                                href="{{ route('admin.alert-rules.index') }}" @if(request()->routeIs('admin.alert-rules.*')) aria-current="page" @endif>
                                {{ __('alerts.rules_nav') }}
                            </a>
                        </div>
                    </details>
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
                            <details class="ce-mobile-settings" @if($settingsActive) open @endif>
                                <summary>{{ __('ce.nav.settings') }}</summary>
                                <a href="{{ route('admin.settings.index') }}">{{ __('ce.nav.settings_overview') }}</a>
                                <a href="{{ route('admin.administrators.index') }}">{{ __('ce.nav.administrators') }}</a>
                                <a href="{{ route('admin.ad-settings.index') }}">Active Directory</a>
                                <a href="{{ route('admin.alert-channels.index') }}">{{ __('ce.nav.alert_channels') }}</a>
                                <a href="{{ route('admin.alert-rules.index') }}">{{ __('alerts.rules_nav') }}</a>
                            </details>
                        @endif
                    </nav>
                </details>
                <span class="ce-workspace-context">{{ __('ce.app_title') }}</span>
                <div class="ce-account-actions">
                    @if(auth()->user()->isSuperAdmin())
                        <div class="ce-notification" id="ce-notification"
                            data-feed-url="{{ route('admin.notifications.feed') }}"
                            data-read-url="{{ route('admin.notifications.read') }}"
                            data-read-all-url="{{ route('admin.notifications.read-all') }}">
                            <button type="button" id="ce-notification-trigger" class="ce-notification-trigger"
                                aria-label="{{ __('notifications.heading') }}" aria-haspopup="true" aria-expanded="false"
                                aria-controls="ce-notification-panel" title="{{ __('notifications.heading') }}">
                                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/>
                                    <path d="M10 21h4"/>
                                </svg>
                                <span id="ce-notification-count" class="ce-notification-count" hidden></span>
                            </button>
                            <div class="ce-notification-panel" id="ce-notification-panel" hidden>
                                <div class="ce-notification-panel-head">
                                    <strong>{{ __('notifications.heading') }}</strong>
                                    <button type="button" class="ce-notification-mark-all" id="ce-notification-read-all">{{ __('notifications.mark_all') }}</button>
                                </div>
                                <div class="ce-notification-items" id="ce-notification-items" aria-live="polite">
                                    <p class="ce-notification-empty">{{ __('notifications.loading') }}</p>
                                </div>
                                <a class="ce-notification-all" href="{{ route('admin.notifications.index') }}">{{ __('notifications.all') }} →</a>
                            </div>
                            <template id="ce-notification-labels"
                                data-empty="{{ __('notifications.empty_short') }}"
                                data-unavailable="{{ __('notifications.unavailable') }}"
                                data-read="{{ __('notifications.mark_read') }}"
                                data-open="{{ __('notifications.open') }}"></template>
                        </div>
                    @endif

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
