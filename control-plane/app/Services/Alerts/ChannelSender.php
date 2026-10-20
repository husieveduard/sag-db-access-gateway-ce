<?php
namespace App\Services\Alerts;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class ChannelSender
{
    public function enabled(string $channel): bool
    {
        return match ($channel) {
            'telegram' => filled(config('sag.alerts.telegram_bot_token')) && filled(config('sag.alerts.telegram_chat_id')),
            'slack' => filled(config('sag.alerts.slack_webhook_url')),
            'teams' => filled(config('sag.alerts.teams_webhook_url')),
            'nextcloud_talk' => filled(config('sag.alerts.nextcloud_url')) && filled(config('sag.alerts.nextcloud_room_token')) && filled(config('sag.alerts.nextcloud_user')) && filled(config('sag.alerts.nextcloud_app_password')),
            'webhook' => filled(config('sag.alerts.webhook_url')),
            'email' => filled(config('sag.alerts.email_to')) && !in_array(config('mail.default'), ['log', 'array'], true),
            default => false,
        };
    }

    public function send(string $channel, array $event): void
    {
        if (!$this->enabled($channel)) {
            throw new RuntimeException('channel_not_configured');
        }
        $message = sprintf('SAG CE alert #%s | %s | %s | resource: %s',
            $event['alert_id'] ?? 'TEST', $event['rule'] ?? 'test',
            $event['severity'] ?? 'info', $event['resource_id'] ?? '-');
        try {
            if ($channel === 'email') {
                Mail::raw($message, fn ($mail) => $mail->to(config('sag.alerts.email_to'))->subject('SAG CE security alert'));
                return;
            }
            if ($channel === 'telegram') {
                $url = 'https://api.telegram.org/bot'.config('sag.alerts.telegram_bot_token').'/sendMessage';
                $payload = ['chat_id' => config('sag.alerts.telegram_chat_id'), 'text' => $message];
            } elseif ($channel === 'nextcloud_talk') {
                $url = rtrim(config('sag.alerts.nextcloud_url'), '/').'/ocs/v2.php/apps/spreed/api/v1/chat/'.rawurlencode(config('sag.alerts.nextcloud_room_token'));
                $response = Http::connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false])
                    ->withBasicAuth(config('sag.alerts.nextcloud_user'), config('sag.alerts.nextcloud_app_password'))
                    ->withHeaders(['OCS-APIRequest' => 'true'])->asForm()->post($url, ['message' => $message]);
                if (!$response->successful() || !in_array((int) $response->json('ocs.meta.statuscode'), [100,200,201], true)) {
                    throw new RuntimeException('delivery_rejected');
                }
                return;
            } else {
                $url = match ($channel) {
                    'slack' => config('sag.alerts.slack_webhook_url'),
                    'teams' => config('sag.alerts.teams_webhook_url'),
                    'webhook' => config('sag.alerts.webhook_url'),
                };
                $payload = $channel === 'webhook' ? $event : ['text' => $message];
            }
            if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
                throw new RuntimeException('https_required');
            }
            Http::connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false])->post($url, $payload)->throw();
        } catch (\Throwable) {
            throw new RuntimeException('channel_delivery_failed');
        }
    }
}
