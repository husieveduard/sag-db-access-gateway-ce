<?php

namespace App\Services\Alerts;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\AuditEvent;
use Illuminate\Support\Facades\DB;

class AlertEngine
{
    public function process(int $limit = 200): int
    {
        return DB::transaction(function () use ($limit): int {
            $state = DB::table('alert_processor_state')->where('id', 1)->lockForUpdate()->first();
            $lastId = (int) ($state?->last_audit_event_id ?? 0);
            $events = AuditEvent::query()->where('id', '>', $lastId)->orderBy('id')->limit($limit)->get();
            $rules = AlertRule::query()->where('enabled', true)->get()->keyBy('event_type');
            foreach ($events as $event) {
                $rule = $rules->get($event->event_type);
                if ($rule) {
                    $this->match($rule, $event);
                }
                $lastId = $event->id;
            }
            if ($events->isNotEmpty()) {
                DB::table('alert_processor_state')->updateOrInsert(['id' => 1], [
                    'last_audit_event_id' => $lastId, 'updated_at' => now(),
                ]);
            }
            return $events->count();
        });
    }

    private function match(AlertRule $rule, AuditEvent $event): void
    {
        // Restrict the fingerprint to safe, already indexed identifiers.
        // Do not hash or serialize the raw event_data (may contain sensitive data).
        $scope = implode('|', [
            $rule->code, $event->resource_id ?: 0,
            $event->session_id ?: 0, $event->ip_address ?: '',
        ]);
        $fingerprint = hash('sha256', $scope);
        $seen = $event->occurred_at ?? now();
        $query = Alert::query()->where('fingerprint', $fingerprint)
            ->whereIn('status', ['new', 'acknowledged', 'investigating'])
            ->where('last_seen_at', '>=', now()->subMinutes($rule->cooldown_minutes))
            ->lockForUpdate();
        $alert = $query->first();

        if ($alert) {
            $alert->increment('occurrence_count');
            $alert->forceFill([
                'last_seen_at' => $seen, 'last_audit_event_id' => $event->id,
            ])->save();
        } else {
            $alert = Alert::query()->create([
                'rule_id' => $rule->id, 'resource_id' => $event->resource_id,
                'session_id' => $event->session_id, 'owner_user_id' => $event->actor_user_id,
                'last_audit_event_id' => $event->id, 'fingerprint' => $fingerprint,
                'severity' => $rule->severity, 'status' => 'new',
                'summary' => $rule->title,
                'first_seen_at' => $seen, 'last_seen_at' => $seen,
            ]);
            foreach (['telegram', 'slack', 'teams', 'nextcloud_talk', 'webhook', 'email'] as $channel) {
                if ($this->channelEnabled($channel)) {
                    $alert->deliveries()->create(['channel' => $channel, 'next_attempt_at' => now()]);
                }
            }
        }

        DB::table('alert_matches')->insertOrIgnore([
            'rule_id' => $rule->id, 'alert_id' => $alert->id,
            'audit_event_id' => $event->id, 'source_key' => hash('sha256', (string) $event->id),
            'created_at' => now(),
        ]);
    }

    private function channelEnabled(string $channel): bool
    {
        return app(ChannelSender::class)->enabled($channel);
    }
}
