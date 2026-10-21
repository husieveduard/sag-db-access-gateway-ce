<?php
namespace Tests\Feature;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\AlertChannelSetting;
use App\Services\Alerts\AlertChannelConfiguration;
use App\Services\Alerts\ChannelSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class AlertChannelWebSettingsTest extends TestCase {
 use RefreshDatabase;
 private function login(string $role='superadmin'): self {
  $user=User::factory()->create();
  $user->forceFill(['role'=>$role,'is_active'=>true,'mfa_totp_secret'=>'TESTSECRET123456','mfa_enabled_at'=>now()->subMinute()])->save();
  return $this->actingAs($user)->withSession(['ce_mfa.verified_user_id'=>$user->id,'ce_mfa.verified_at'=>now()->timestamp]);
 }
 public function test_only_superadmin_can_open_or_update(): void {
  $this->login('admin')->get('/admin/alert-channels')->assertForbidden();
  $this->post('/admin/alert-channels/telegram',['enabled'=>'1','bot_token'=>'123:FakeToken','chat_id'=>'123'])->assertForbidden();
  $this->assertDatabaseCount('alert_channel_settings',0);
 }
 public function test_encrypted_telegram_secrets_are_masked_and_preserved(): void {
  $this->login();
  $this->post('/admin/alert-channels/telegram',['enabled'=>'1','bot_token'=>'123456:VerySecretToken','chat_id'=>'777'])
   ->assertRedirect(route('admin.alert-channels.index'));
  $model=AlertChannelSetting::query()->findOrFail('telegram');
  $this->assertNotEquals('123456:VerySecretToken',$model->getRawOriginal('settings'));
  $this->assertStringNotContainsString('VerySecretToken',$model->getRawOriginal('settings'));
  $this->assertSame('123456:VerySecretToken',$model->settings['bot_token']);
  $page=$this->get('/admin/alert-channels')->assertOk();
  $page->assertSee('Секрет збережено')->assertDontSee('VerySecretToken');
  $this->post('/admin/alert-channels/telegram',['enabled'=>'1','bot_token'=>'','chat_id'=>'999'])->assertRedirect();
  $this->assertSame('123456:VerySecretToken',AlertChannelSetting::query()->findOrFail('telegram')->settings['bot_token']);
  $this->assertDatabaseHas('audit_events',['event_type'=>'alerts.channel.updated']);
 }
 public function test_disabling_web_channel_overrides_legacy_env(): void {
  config()->set('sag.alerts.telegram_bot_token','123:legacy');
  config()->set('sag.alerts.telegram_chat_id','12');
  $this->login();
  $this->post('/admin/alert-channels/telegram',['enabled'=>'0'])->assertRedirect();
  $this->assertFalse(app(ChannelSender::class)->enabled('telegram'));
 }
 public function test_webhook_only_accepts_allowlisted_https_host(): void {
  $this->login();
  $this->post('/admin/alert-channels/webhook',['enabled'=>'1','webhook_url'=>'https://localhost/a','allowed_hosts'=>'localhost'])
   ->assertSessionHasErrors();
  $this->post('/admin/alert-channels/webhook',['enabled'=>'1','webhook_url'=>'https://alerts.example.org/hook','allowed_hosts'=>'other.example.org'])
   ->assertSessionHasErrors();
  $this->post('/admin/alert-channels/webhook',['enabled'=>'1','webhook_url'=>'https://alerts.example.org/hook','allowed_hosts'=>'alerts.example.org'])
   ->assertRedirect();
  Http::fake(['alerts.example.org/*'=>Http::response([],200)]);
  app(ChannelSender::class)->send('webhook',['alert_id'=>2,'sql'=>'SECRET']);
  Http::assertSent(fn($r)=>isset($r['alert_id'])&&!isset($r['sql']));
 }
 public function test_smtps_port_must_be_465(): void {
  $this->login();
  $this->post('/admin/alert-channels/email',['enabled'=>'1','email_to'=>'soc@example.org',
   'smtp_host'=>'smtp.example.org','smtp_port'=>'25','from_address'=>'sag@example.org'])
   ->assertSessionHasErrors('smtp_port');
 }
}
