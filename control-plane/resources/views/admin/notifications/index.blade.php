@extends('layouts.app')

@section('title', __('notifications.heading').' · '.__('ce.app_title'))

@section('content')
<div class="ce-notification-page">
    <h1 class="page-title">{{ __('notifications.heading') }}</h1>
    <p class="subtitle">{{ __('notifications.window') }}</p>
    <div class="ce-notification-page-summary">{{ __('notifications.unread') }}: <strong>{{ $feed['unread_count'] }}</strong></div>
    <div class="card ce-notification-history">
        @forelse($feed['items'] as $item)
            <article class="ce-notification-history-item {{ $item['unread'] ? 'is-unread' : '' }}">
                <div class="ce-notification-history-main">
                    <div class="ce-notification-history-title">
                        <span class="badge {{ in_array($item['severity'], ['high', 'critical'], true) ? 'red' : ($item['severity'] === 'medium' ? 'yellow' : '') }}">{{ $item['severity'] }}</span>
                        <strong>{{ $item['title'] }}</strong>
                        @if($item['unread'])<span class="ce-unread-dot" title="{{ __('notifications.unread') }}"></span>@endif
                    </div>
                    <p>{{ $item['detail'] }}</p>
                    <small>{{ app(\App\Services\Settings\DisplayClock::class)->format($item['occurred_at']) }}</small>
                </div>
                <div class="ce-notification-history-actions">
                    <a class="button" href="{{ $item['url'] }}">{{ __('notifications.open') }}</a>
                    @if($item['unread'])
                    <form method="POST" action="{{ route('admin.notifications.read') }}">
                        @csrf
                        <input type="hidden" name="source_type" value="{{ $item['source_type'] }}">
                        <input type="hidden" name="source_id" value="{{ $item['source_id'] }}">
                        <button class="button" type="submit">{{ __('notifications.mark_read') }}</button>
                    </form>
                    @endif
                </div>
            </article>
        @empty
            <p class="ce-notification-empty">{{ __('notifications.empty') }}</p>
        @endforelse
    </div>
    @if($feed['unread_count'] > 0)
    <form method="POST" action="{{ route('admin.notifications.read-all') }}" class="ce-notification-page-footer">
        @csrf
        <button type="submit" class="button">{{ __('notifications.mark_all') }}</button>
    </form>
    @endif
</div>
@endsection
