<?php

namespace App\Services\Alerts;

use App\Models\AlertDelivery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class AlertDeliveryService
{
    public function deliver(int $limit = 50): int
    {
        $items = AlertDelivery::query()->with('alert.rule')
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
                report($e);
            }
        }

        return $items->count();
    }

    private function send(AlertDelivery $delivery): void
    {
        $alert = $delivery->alert;
        // Deliberately transmit only event metadata, never SQL or event_data.
        $payload = [
            'alert_id' => $alert->id,
            'rule' => $alert->rule->code,
            'severity' => $alert->severity,
            'resource_id' => $alert->resource_id,
            'session_id' => $alert->session_id,
            'occurrences' => $alert->occurrence_count,
            'first_seen_at' => $alert->first_seen_at?->toIso8601String(),
        ];

        if ($delivery->channel === 'webhook') {
            $url = (string) config('sag.alerts.webhook_url');
            if (!str_starts_with($url, 'https://')) {
                throw new RuntimeException('Alert webhook must use HTTPS.');
            }
            $response = Http::timeout(5)->acceptJson()
                ->withHeaders(['X-SAG-Alert-Id' => (string) $alert->id])
                ->post($url, $payload);
            $response->throw();
        } elseif ($delivery->channel === 'email') {
            Mail::raw(
                'SAG security alert #'.$alert->id.' ('.$alert->severity.'): '.$alert->rule->title
                    ."\nResource ID: ".($alert->resource_id ?? 'n/a')
                    ."\nSession ID: ".($alert->session_id ?? 'n/a'),
                fn ($mail) => $mail->to(config('sag.alerts.email_to'))->subject('SAG CE Security Alert #'.$alert->id)
            );
        } else {
            throw new RuntimeException('Unsupported alert delivery channel.');
        }
    }
}
