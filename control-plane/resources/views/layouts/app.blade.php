<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('ce.app_title'))</title>
    <link rel="stylesheet" href="{{ asset('css/sag-ce.css') }}">
</head>
<body>
    @php
        $currentLocale = app()->getLocale();
    @endphp

    <header class="topbar {{ auth()->check() ? '' : 'topbar-guest' }}">
        <div class="nav-left">
            <a
                class="brand"
                href="{{ auth()->check() ? route('admin.dashboard') : route('login') }}"
            >
                {{ __('ce.product_name') }} <small>{{ __('ce.edition') }}</small>
            </a>

            @auth
                <nav class="nav">
                    <a
                        href="{{ route('admin.dashboard') }}"
                        class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}"
                    >{{ __('ce.nav.dashboard') }}</a>

                    <a
                        href="{{ route('admin.resources.index') }}"
                        class="{{ request()->routeIs('admin.resources.*') ? 'active' : '' }}"
                    >{{ __('ce.nav.resources') }}</a>

                    <a
                        href="{{ route('admin.sessions.index') }}"
                        class="{{ request()->routeIs('admin.sessions.*') ? 'active' : '' }}"
                    >{{ __('ce.nav.sessions') }}</a>
                </nav>
            @endauth
        </div>

        <div class="user-box">
            <div class="locale-switcher" aria-label="{{ __('ce.language') }}">
                @foreach (['uk' => 'UA', 'en' => 'EN'] as $locale => $label)
                    <form method="POST" action="{{ route('locale.update', ['locale' => $locale]) }}">
                        @csrf
                        <button
                            type="submit"
                            class="locale-button {{ $currentLocale === $locale ? 'active' : '' }}"
                            aria-label="{{ $label }}"
                        >{{ $label }}</button>
                    </form>
                @endforeach
            </div>

            @auth
                <span>{{ auth()->user()->name }} · {{ __('ce.role.operator') }}</span>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="logout" type="submit">{{ __('ce.logout') }}</button>
                </form>
            @endauth
        </div>
    </header>

    <main class="container">
        @if (session('success'))
            <div class="flash">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class="form-error">{{ $errors->first() }}</div>
        @endif

        @yield('content')
    </main>
</body>
</html>
