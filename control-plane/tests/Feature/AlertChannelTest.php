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
}
