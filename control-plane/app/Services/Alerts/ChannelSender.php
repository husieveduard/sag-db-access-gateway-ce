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
        $message = $this->humanMessage($event);
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
                $url = 'https://api.telegram.org/bot'.$settings['bot_token'].'/sendMessage';
                $payload = ['chat_id' => $settings['chat_id'], 'text' => $message];
            } elseif ($channel === 'nextcloud_talk') {
                $url = rtrim($settings['nextcloud_url'], '/').'/ocs/v2.php/apps/spreed/api/v1/chat/'.rawurlencode($settings['room_token']);
                app(OutboundEndpointPolicy::class)->assertAllowed('nextcloud_talk', $url, $settings['allowed_hosts'] ?? '');
                $response = Http::connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false])
                    ->withBasicAuth($settings['user'], $settings['app_password'])
                    ->withHeaders(['OCS-APIRequest' => 'true'])->asForm()->post($url, ['message' => $message]);
                if (!$response->successful() || !in_array((int) $response->json('ocs.meta.statuscode'), [100,200,201], true)) {
                    throw new RuntimeException('delivery_rejected');
                }
                return;
            } else {
                $url = match ($channel) {
                    'slack' => $settings['webhook_url'],
                    'teams' => $settings['webhook_url'],
                    'webhook' => $settings['webhook_url'],
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

    /**
     * Human-readable notification, with identifiers kept for audit correlation.
     * Do not include SQL text, connection credentials or arbitrary event_data.
     */
    private function humanMessage(array $event): string
    {
        $risk = (string) ($event['severity'] ?? 'info');
        $riskLabel = match ($risk) {
            'critical' => 'Критичний ризик',
            'high' => 'Високий ризик',
            'medium' => 'Середній ризик',
            'low' => 'Низький ризик',
            default => 'Інформація',
        };
        $alertId = $event['alert_id'] ?? 'TEST';
        $rule = $this->safeLabel($event['rule'] ?? 'channel_test');
        $title = $this->safeLabel($event['rule_title'] ?? '');
        $resourceId = $event['resource_id'] ?? null;
        $resourceName = $this->safeLabel($event['resource_name'] ?? '');
        $resource = $resourceName !== '' ? $resourceName : '—';
        if ($resourceId !== null && $resourceId !== '') {
            $resource .= ' (#'.(int) $resourceId.')';
        }
        $lines = [
            'SAG CE | '.$riskLabel,
            'Алерт: #'.$alertId,
            'Подія: '.($title !== '' ? $title.' ('.$rule.')' : $rule),
            'Ресурс: '.$resource,
        ];
        if (isset($event['occurrences']) && (int) $event['occurrences'] > 1) {
            $lines[] = 'Повторень: '.(int) $event['occurrences'];
        }
        return implode("\n", $lines);
    }

    private function safeLabel(mixed $value): string
    {
        $clean = preg_replace('/[\\x00-\\x1F\\x7F]+/u', ' ', (string) $value);
        return \Illuminate\Support\Str::limit(trim($clean ?? ''), 120);
    }
}
