@extends('layouts.app')

@section('title', 'Recovery codes · SAG DB Access Gateway CE')

@push('styles')
<style>
    .shell {
        width: min(680px, 100%);
        margin: 8vh auto 0;
    }
    .card-main {
        padding: 30px;
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: 14px;
    }
    h1 { margin: 0; font-size: 25px; }
    p { color: var(--muted); line-height: 1.55; }
    .warning {
        margin: 18px 0;
        padding: 12px;
        border-radius: 8px;
        color: #f7dfa0;
        border: 1px solid rgba(242,201,76,.30);
        background: rgba(242,201,76,.08);
    }
    .codes {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        margin: 20px 0;
    }
    .code {
        padding: 11px;
        color: #d7e8ff;
        border: 1px solid var(--line);
        border-radius: 8px;
        background: #0d1726;
        font-family: ui-monospace, Consolas, monospace;
        font-weight: 700;
        text-align: center;
    }
    .submit {
        width: 100%;
        margin-top: 12px;
        padding: 12px;
        border: 0;
        border-radius: 8px;
        color: #06111d;
        background: var(--blue);
        font-weight: 800;
        cursor: pointer;
    }
    @media (max-width: 520px) {
        .codes { grid-template-columns: 1fr; }
    }
</style>
@endpush

@section('content')
<div class="shell">
    <section class="card-main">
        <h1>Recovery codes</h1>

        <div class="warning">
            Збережіть ці коди у захищеному місці. Вони показуються лише один раз.
            Кожен код можна використати лише один раз.
        </div>

        <div class="codes">
            @foreach ($codes as $code)
                <div class="code">{{ $code }}</div>
            @endforeach
        </div>

        <form method="POST" action="{{ route('mfa.recovery.acknowledge') }}">
            @csrf

            <button class="submit" type="submit">
                Я зберіг recovery codes
            </button>
        </form>
    </section>
</div>
@endsection
