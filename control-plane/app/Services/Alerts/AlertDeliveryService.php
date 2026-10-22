<?php

namespace App\Services\Alerts;

use App\Models\AlertDelivery;
use RuntimeException;
use Throwable;

class AlertDeliveryService
{
    public function deliver(int $limit = 50): int
    {
        // Recover interrupted sends; at-least-once delivery requires idempotent receivers.
        AlertDelivery::query()->where('status', 'sending')
            ->where('updated_at', '<', now()->subMinutes(5))
            ->update(['status' => 'retry', 'next_attempt_at' => now()]);

        $items = AlertDelivery::query()->with(['alert.rule', 'alert.resource'])
            ->whereIn('status', ['queued', 'retry'])
            ->where('next_attempt_at', '<=', now())
            ->orderBy('id')->limit($limit)->get();

        foreach ($items as $item) {
            $claimed = AlertDelivery::query()->whereKey($item->id)
                ->whereIn('status', ['queued', 'retry'])
                ->update(['status' => 'sending', 'updated_at' => now()]);
            if (!$claimed) {
                continue;
            }
            try {
                $this->send($item);
                $item->forceFill([
                    'status' => 'delivered',
                    'attempts' => $item->attempts + 1,
                    'delivered_at' => now(),
                    'last_error_code' => null,
                ])->save();
            } catch (Throwable $e) {
                $attempt = $item->attempts + 1;
                $item->forceFill([
                    'status' => $attempt >= 10 ? 'failed' : 'retry',
                    'attempts' => $attempt,
                    'next_attempt_at' => now()->addSeconds(min(3600, 30 * (2 ** min($attempt, 6)))),
                    'last_error_code' => 'delivery_failed',
                ])->save();
                // Third-party exception bodies/URLs can contain credentials.
                // Report only safe status codes through the delivery row.
            }
        }

        return $items->count();
    }

    private function send(AlertDelivery $delivery): void
    {
        $alert = $delivery->alert;
        app(ChannelSender::class)->send($delivery->channel, [
            'alert_id' => $alert->id,
            'rule' => $alert->rule->code,
            'rule_title' => $alert->rule->title,
            'severity' => $alert->severity,
            'resource_id' => $alert->resource_id,
            'resource_name' => $alert->resource?->name,
            'session_id' => $alert->session_id,
            'occurrences' => $alert->occurrence_count,
            'first_seen_at' => $alert->first_seen_at?->toIso8601String(),
        ]);
    }
}
