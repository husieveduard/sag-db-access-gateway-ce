<?php
namespace Tests\Feature;
use App\Services\Alerts\ChannelSender;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
class AlertChannelTest extends TestCase {
    public function test_unconfigured_channel_is_rejected(): void {
        config()->set('sag.alerts.slack_webhook_url','');
        $this->expectException(\RuntimeException::class);
        app(ChannelSender::class)->send('slack',['rule'=>'test']);
    }
    public function test_slack_notification_uses_sanitized_metadata(): void {
        config()->set('sag.alerts.slack_webhook_url','https://hooks.slack.com/services/example');
        Http::fake(['hooks.slack.com/*'=>Http::response('ok',200)]);
        app(ChannelSender::class)->send('slack',[
            'alert_id'=>42,'rule'=>'high_risk_query','severity'=>'high',
            'sql'=>'SECRET PASSWORD',
        ]);
        Http::assertSent(function($request) {
            return str_contains($request['text'],'high_risk_query')
                && !str_contains($request['text'],'SECRET PASSWORD');
        });
    }
    public function test_email_log_mailer_is_not_considered_delivery(): void {
        config()->set('mail.default','log');
        config()->set('sag.alerts.email_to','security@example.test');
        $this->assertFalse(app(ChannelSender::class)->enabled('email'));
    }
    public function test_generic_webhook_rejects_unapproved_internal_host(): void {
        config()->set('sag.alerts.webhook_url','https://localhost/alert');
        config()->set('sag.alerts.allowed_hosts','localhost');
        Http::fake();
        $this->expectException(\RuntimeException::class);
        app(ChannelSender::class)->send('webhook',['alert_id'=>2]);
    }
    public function test_generic_webhook_payload_has_no_sql_or_secrets(): void {
        config()->set('sag.alerts.webhook_url','https://alerts.example.com/incoming');
        config()->set('sag.alerts.allowed_hosts','alerts.example.com');
        Http::fake(['alerts.example.com/*'=>Http::response(['ok'=>true],200)]);
        app(ChannelSender::class)->send('webhook',[
            'alert_id'=>1,'rule'=>'high_risk_query','sql'=>'DROP secret','credential'=>'secret',
        ]);
        Http::assertSent(fn($request)=>
            $request['alert_id']===1 && !isset($request['sql']) && !isset($request['credential'])
        );
    }
    public function test_telegram_requires_ok_response_not_only_http_200(): void {
        config()->set('sag.alerts.telegram_bot_token','000000000:fake');
        config()->set('sag.alerts.telegram_chat_id','12345678');
        Http::fake(['api.telegram.org/*'=>Http::response(['ok'=>false],200)]);
        $this->expectException(\RuntimeException::class);
        app(ChannelSender::class)->send('telegram',['alert_id'=>3]);
    }


    public function test_telegram_notification_shows_resource_name_and_identifier_without_sql(): void
    {
        config()->set('sag.alerts.telegram_bot_token', '123456:fake');
        config()->set('sag.alerts.telegram_chat_id', '12345678');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        app(ChannelSender::class)->send('telegram', [
            'alert_id' => 5,
            'rule' => 'high_risk_query',
            'rule_title' => 'Небезпечний SQL-запит',
            'severity' => 'high',
            'resource_id' => 1,
            'resource_name' => "SAG CE PostgreSQL LAB\nFORGED",
            'occurrences' => 4,
            'sql' => 'PASSWORD secret',
        ]);

        Http::assertSent(function ($request) {
            $text = $request['text'];
            return str_contains($text, 'SAG CE PostgreSQL LAB FORGED (#1)')
                && str_contains($text, 'high_risk_query')
                && str_contains($text, 'Алерт: #5')
                && str_contains($text, 'Повторень: 4')
                && !str_contains($text, 'PASSWORD secret')
                && !str_contains($text, "LAB\\nFORGED");
        });
    }

}
