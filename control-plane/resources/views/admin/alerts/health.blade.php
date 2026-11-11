@extends('layouts.app')

@section('title', 'Стан сповіщень')

@section('content')
@php
    $channelNames = [
        'telegram' => 'Telegram',
        'slack' => 'Slack',
        'teams' => 'Microsoft Teams',
        'nextcloud_talk' => 'Nextcloud Talk',
        'webhook' => 'Webhook',
        'email' => 'Email',
    ];
@endphp

<div class="alert-health-page">
    <div class="settings-page-header">
        <div>
            <h1 class="page-title">Стан сповіщень</h1>
            <p><a class="button secondary" href="{{ route('admin.alert-channels.index') }}">Налаштувати канали</a></p>
            <p class="subtitle">Черги подій, доставки та перевірка каналів Alert Engine.</p>
        </div>
    </div>

    <div class="alert-health-stats">
        <section class="card alert-health-metric" aria-label="Черга аудиту">
            <div class="alert-health-metric-label">Необроблені події аудиту</div>
            <div class="alert-health-metric-value">{{ $lag }}</div>
            <div class="alert-health-metric-note">Події, які очікують обробки</div>
        </section>
        <section class="card alert-health-metric" aria-label="Очікують доставки">
            <div class="alert-health-metric-label">Очікують доставки</div>
            <div class="alert-health-metric-value">{{ $pending }}</div>
            <div class="alert-health-metric-note">В черзі, повторно або в обробці</div>
        </section>
        <section class="card alert-health-metric" aria-label="Помилки доставки">
            <div class="alert-health-metric-label">Помилки доставки</div>
            <div class="alert-health-metric-value {{ $failed > 0 ? 'alert-health-metric-error' : '' }}">{{ $failed }}</div>
            <div class="alert-health-metric-note">Доставки зі статусом «Помилка»</div>
        </section>
    </div>

    <section class="card alert-health-processor" aria-label="Стан обробника">
        <span class="alert-health-processor-label">Останнє оновлення обробника</span>
        <strong>{{ $lastRun ? app(\App\Services\Settings\DisplayClock::class)->format($lastRun) : 'Ще не запускався' }}</strong>
    </section>

    <section class="card alert-health-channels" aria-labelledby="alert-channels-heading">
        <div class="settings-panel-heading">
            <h2 id="alert-channels-heading">Канали сповіщень</h2>
            <p>Неналаштовані канали не надсилають повідомлення. Кнопка тесту доступна після налаштування каналу.</p>
        </div>

        <div class="table-wrap">
            <table class="alert-health-table">
                <thead>
                    <tr>
                        <th scope="col">Канал</th>
                        <th scope="col">Налаштування</th>
                        <th scope="col">Останній тест</th>
                        <th scope="col">Дія</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($channels as $entry)
                        <tr>
                            <td><strong>{{ $channelNames[$entry['name']] ?? $entry['name'] }}</strong></td>
                            <td>
                                <span class="settings-status {{ $entry['configured'] ? 'settings-status-on' : 'settings-status-off' }}">
                                    {{ $entry['configured'] ? 'Налаштовано' : 'Не налаштовано' }}
                                </span>
                            </td>
                            <td>
                                @if (!$entry['last'])
                                    <span class="muted">Не перевірявся</span>
                                @elseif ($entry['last']->status === 'success')
                                    <span class="settings-status settings-status-on">Успішно</span>
                                @else
                                    <span class="settings-status settings-status-error">Помилка</span>
                                @endif
                            </td>
                            <td>
                                @if ($entry['configured'])
                                    <form method="POST" action="{{ route('admin.alert-health.test') }}">
                                        @csrf
                                        <input type="hidden" name="channel" value="{{ $entry['name'] }}">
                                        <button class="button secondary" type="submit">Надіслати тест</button>
                                    </form>
                                @else
                                    <span class="muted">Недоступно</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
