<?php
namespace App\Services\Alerts;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class ChannelSender
{
    public const CHANNELS = ['telegram', 'slack', 'teams', 'nextcloud_talk', 'webhook', 'email'];

    public function enabled(string $channel): bool
    {
        $settings=app(AlertChannelConfiguration::class)->get($channel);
        return (bool)$settings['enabled'] && app(AlertChannelConfiguration::class)->ready($channel,$settings);
    }

    private function settings(string $channel): array
    {
        return app(AlertChannelConfiguration::class)->get($channel);
    }

    public function send(string $channel, array $event): void
    {
        if (!$this->enabled($channel)) {
            throw new RuntimeException('channel_not_configured');
        }
        $settings=$this->settings($channel);
        $message = sprintf('SAG CE alert #%s | %s | %s | resource: %s',
            $event['alert_id'] ?? 'TEST', $event['rule'] ?? 'test',
            $event['severity'] ?? 'info', $event['resource_id'] ?? '-');
        try {
            if ($channel === 'email') {
                if ($settings['source'] === 'web') {
                    $mailer=Mail::build([
                        'transport'=>'smtp','scheme'=>'smtps',
                        'host'=>$settings['smtp_host'],'port'=>465,
                        'username'=>$settings['smtp_username'] ?: null,
                        'password'=>$settings['smtp_password'] ?: null,
                        'timeout'=>8,
                    ]);
                    $mailer->raw($message, fn ($mail) => $mail
                        ->from($settings['from_address'])
                        ->to($settings['email_to'])->subject('SAG CE security alert'));
                } else {
                    Mail::raw($message, fn ($mail) => $mail->to($settings['email_to'])->subject('SAG CE security alert'));
                }
                return;
            }
            if ($channel === 'telegram') {
                $url = 'https://api.telegram.org/bot'.config('sag.alerts.telegram_bot_token').'/sendMessage';
                $payload = ['chat_id' => config('sag.alerts.telegram_chat_id'), 'text' => $message];
            } elseif ($channel === 'nextcloud_talk') {
                $url = rtrim(config('sag.alerts.nextcloud_url'), '/').'/ocs/v2.php/apps/spreed/api/v1/chat/'.rawurlencode(config('sag.alerts.nextcloud_room_token'));
                app(OutboundEndpointPolicy::class)->assertAllowed('nextcloud_talk', $url, $settings['allowed_hosts'] ?? '');
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
                $payload = $channel === 'webhook'
                    ? array_intersect_key($event, array_flip(['alert_id','rule','severity','resource_id','session_id','occurrences','first_seen_at']))
                    : ['text' => $message];
            }
            if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
                throw new RuntimeException('https_required');
            }
            if ($channel === 'telegram') {
                $response = Http::connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false])->post($url, $payload);
                if (!$response->successful() || $response->json('ok') !== true) {
                    throw new RuntimeException('telegram_rejected');
                }
            } else {
                app(OutboundEndpointPolicy::class)->assertAllowed($channel, $url, $settings['allowed_hosts'] ?? '');
                Http::connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false])->post($url, $payload)->throw();
            }
        } catch (\Throwable) {
            throw new RuntimeException('channel_delivery_failed');
        }
    }
}
