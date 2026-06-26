@extends('layouts.app')

@section('title', 'Recovery codes · SAG DB Access Gateway CE')

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
