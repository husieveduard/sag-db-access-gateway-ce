<?php

namespace App\Services\Notifications;

use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Models\AuditEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SuperadminNotificationFeed
{
    // Deliberately exclude routine SQL, access and lifecycle activity.
    private const AUDIT_TYPES = [
        'auth.admin.created',
        'auth.admin.status_changed',
        'auth.admin.role_changed',
        'ad.settings.updated',
        'alerts.channel.updated',
    ];

    /**
     * A bounded 30-day feed. Existing alert deduplication defines alert identity;
     * a new occurrence after reading makes that alert unread again.
     */
    public function feed(User $user, int $limit = 15): array
    {
        abort_unless($user->isSuperAdmin(), 403);

        $items = $this->sources();

        $readRows = DB::table('admin_notification_reads')
            ->where('user_id', $user->id)
            ->get(['source_type', 'source_id', 'read_at'])
            ->keyBy(fn ($row) => $row->source_type.':'.$row->source_id);

        foreach ($items as &$item) {
            $read = $readRows->get($item['key']);
            $item['unread'] = !$read
                || Carbon::parse($read->read_at)->lt($item['occurred_at']);
            $item['occurred_at'] = $item['occurred_at']->toIso8601String();
        }
        unset($item);

        return [
            'unread_count' => count(array_filter($items, fn ($item) => $item['unread'])),
            'items' => array_slice($items, 0, max(1, min($limit, 100))),
            'window_days' => 30,
        ];
    }

    public function markRead(User $user, string $type, int $id): bool
    {
        abort_unless($user->isSuperAdmin(), 403);

        $valid = collect($this->sources())
            ->contains(fn ($item) => $item['source_type'] === $type && $item['source_id'] === $id);
        if (!$valid) {
            return false;
        }

        $this->writeReads($user, [['source_type' => $type, 'source_id' => $id]]);

        return true;
    }

    public function markAllRead(User $user): void
    {
        abort_unless($user->isSuperAdmin(), 403);

        $this->writeReads($user, array_map(fn ($item) => [
            'source_type' => $item['source_type'],
            'source_id' => $item['source_id'],
        ], $this->sources()));
    }

    private function writeReads(User $user, array $sources): void
    {
        if (!$sources) {
            return;
        }

        $now = now();
        $rows = array_map(fn ($source) => [
            'user_id' => $user->id,
            'source_type' => $source['source_type'],
            'source_id' => $source['source_id'],
            'read_at' => $now,
        ], $sources);

        DB::table('admin_notification_reads')->upsert(
            $rows, ['user_id', 'source_type', 'source_id'], ['read_at']
        );
    }

    private function sources(): array
    {
        $cutoff = now()->subDays(30);
        $items = [];

        foreach (Alert::query()
            ->with(['rule:id,title', 'resource:id,name'])
            ->whereIn('severity', ['medium', 'high', 'critical'])
            ->where('last_seen_at', '>=', $cutoff)
            ->orderByDesc('last_seen_at')->limit(100)->get() as $alert) {
            $items[] = [
                'key' => 'alert:'.$alert->id,
                'source_type' => 'alert',
                'source_id' => $alert->id,
                'title' => $alert->rule?->title ?: __('notifications.alert_title'),
                'detail' => ($alert->resource?->name ?: __('notifications.unknown_resource'))
                    .' · '.trans_choice('notifications.occurrences', $alert->occurrence_count, ['count' => $alert->occurrence_count]),
                'severity' => $alert->severity,
                'occurred_at' => $alert->last_seen_at,
                'url' => route('admin.alerts.index'),
            ];
        }

        foreach (AlertDelivery::query()
            ->with(['alert.resource:id,name'])
            ->where('status', 'failed')
            ->where('updated_at', '>=', $cutoff)
            ->orderByDesc('updated_at')->limit(100)->get() as $delivery) {
            $items[] = [
                'key' => 'delivery:'.$delivery->id,
                'source_type' => 'delivery',
                'source_id' => $delivery->id,
                'title' => __('notifications.delivery_failed'),
                'detail' => strtoupper($delivery->channel).' · '
                    .($delivery->alert?->resource?->name ?: __('notifications.unknown_resource')),
                'severity' => 'high',
                'occurred_at' => $delivery->updated_at,
                'url' => route('admin.alert-health.index'),
            ];
        }

        foreach (AuditEvent::query()
            ->with(['actor:id,name', 'resource:id,name'])
            ->whereIn('event_type', self::AUDIT_TYPES)
            ->where('occurred_at', '>=', $cutoff)
            ->orderByDesc('occurred_at')->limit(100)->get() as $audit) {
            $items[] = [
                'key' => 'audit:'.$audit->id,
                'source_type' => 'audit',
                'source_id' => $audit->id,
                'title' => __('notifications.audit.'.$audit->event_type),
                'detail' => $audit->actor?->name ?: __('notifications.system_actor'),
                'severity' => $audit->event_type === 'auth.admin.role_changed' ? 'high' : 'medium',
                'occurred_at' => $audit->occurred_at,
                'url' => route('admin.audit.index'),
            ];
        }

        usort($items, static fn ($a, $b) =>
            $b['occurred_at']->getTimestamp() <=> $a['occurred_at']->getTimestamp()
            ?: strcmp($b['key'], $a['key'])
        );

        return $items;
    }
}
