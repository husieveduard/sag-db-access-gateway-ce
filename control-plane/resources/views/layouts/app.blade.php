<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'SAG DB Access Gateway CE')</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #0b1220;
            --panel: #131d2e;
            --line: #2b3b55;
            --text: #e7edf7;
            --muted: #91a2bb;
            --blue: #5aa9ff;
            --green: #36c691;
            --yellow: #f2c94c;
            --red: #f36b6b;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-width: 320px;
            color: var(--text);
            background: var(--bg);
            font-family: Inter, Segoe UI, Arial, sans-serif;
        }
        a { color: inherit; text-decoration: none; }
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 22px;
            padding: 14px 28px;
            background: #0d1726;
            border-bottom: 1px solid var(--line);
        }
        .nav-left {
            display: flex;
            align-items: center;
            gap: 26px;
        }
        .brand {
            white-space: nowrap;
            font-weight: 750;
            letter-spacing: .2px;
        }
        .brand small { color: var(--blue); font-weight: 700; }
        .nav {
            display: flex;
            gap: 6px;
            align-items: center;
        }
        .nav a {
            padding: 7px 9px;
            border-radius: 7px;
            color: var(--muted);
            font-size: 14px;
        }
        .nav a:hover,
        .nav a.active {
            color: var(--text);
            background: rgba(90,169,255,.13);
        }
        .user-box {
            display: flex;
            gap: 14px;
            align-items: center;
            color: var(--muted);
        }
        .logout {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 8px 11px;
            color: var(--text);
            background: transparent;
            cursor: pointer;
        }
        .logout:hover { border-color: var(--blue); }
        .container { max-width: 1600px; margin: 0 auto; padding: 28px; }
        .page-title { margin: 0; font-size: 26px; }
        .subtitle { margin: 8px 0 26px; color: var(--muted); }
        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            overflow: hidden;
        }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 880px; }
        th, td {
            padding: 12px 14px;
            text-align: left;
            vertical-align: top;
            border-bottom: 1px solid var(--line);
        }
        th {
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            background: rgba(255,255,255,.015);
        }
        tr:last-child td { border-bottom: 0; }
        code {
            display: inline-block;
            max-width: 430px;
            overflow: hidden;
            color: #cbd9ed;
            text-overflow: ellipsis;
            white-space: nowrap;
            font: 12px ui-monospace, SFMono-Regular, Consolas, monospace;
        }
        .badge {
            display: inline-block;
            border-radius: 999px;
            padding: 4px 8px;
            font-size: 12px;
            font-weight: 700;
            background: #263650;
            color: #cbd9ed;
        }
        .badge.green { background: rgba(54,198,145,.14); color: var(--green); }
        .badge.yellow { background: rgba(242,201,76,.14); color: var(--yellow); }
        .badge.red { background: rgba(243,107,107,.14); color: var(--red); }
        .muted { color: var(--muted); }
        .flash {
            margin: 0 0 18px;
            padding: 12px 14px;
            border: 1px solid rgba(54,198,145,.3);
            border-radius: 9px;
            color: #b9f0d7;
            background: rgba(54,198,145,.1);
        }
        .form-error {
            margin: 0 0 18px;
            padding: 12px 14px;
            border: 1px solid rgba(243,107,107,.3);
            border-radius: 9px;
            color: #ffc4c4;
            background: rgba(243,107,107,.1);
        }
        @media (max-width: 900px) {
            .topbar,
            .nav-left,
            .user-box {
                align-items: flex-start;
            }
            .topbar { flex-direction: column; padding: 14px 16px; }
            .nav-left { flex-direction: column; gap: 10px; }
            .user-box { gap: 8px; }
            .container { padding: 18px 14px; }
        }
    </style>
    @stack('styles')
</head>
<body>
    @auth
        <header class="topbar">
            <div class="nav-left">
                <a class="brand" href="{{ route('admin.dashboard') }}">
                    SAG DB Access Gateway <small>CE</small>
                </a>

                <nav class="nav">
                    <a
                        href="{{ route('admin.dashboard') }}"
                        class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}"
                    >Dashboard</a>

                    <a
                        href="{{ route('admin.resources.index') }}"
                        class="{{ request()->routeIs('admin.resources.*') ? 'active' : '' }}"
                    >Resources</a>

                    <a
                        href="{{ route('admin.sessions.index') }}"
                        class="{{ request()->routeIs('admin.sessions.*') ? 'active' : '' }}"
                    >Sessions</a>
                </nav>
            </div>

            <div class="user-box">
                <span>{{ auth()->user()->name }} · admin</span>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="logout" type="submit">Вийти</button>
                </form>
            </div>
        </header>
    @endauth

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
