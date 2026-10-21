<?php
namespace App\Services\Alerts;
use App\Models\AlertChannelSetting;
use Illuminate\Support\Facades\Schema;
class AlertChannelConfiguration {
 public const FIELDS=[
  'telegram'=>['bot_token','chat_id'],
  'slack'=>['webhook_url'],
  'teams'=>['webhook_url','allowed_hosts'],
  'nextcloud_talk'=>['nextcloud_url','user','app_password','room_token','allowed_hosts'],
  'webhook'=>['webhook_url','allowed_hosts'],
  'email'=>['email_to','smtp_host','smtp_port','smtp_username','smtp_password','from_address'],
 ];
 public const SECRET=[
  'telegram'=>['bot_token'],'slack'=>['webhook_url'],'teams'=>['webhook_url'],
  'nextcloud_talk'=>['app_password','room_token'],'webhook'=>['webhook_url'],
  'email'=>['smtp_password'],
 ];
 public function get(string $channel): array {
  if(!isset(self::FIELDS[$channel])) throw new \InvalidArgumentException('Unsupported channel');
  if(Schema::hasTable('alert_channel_settings')) {
   $row=AlertChannelSetting::query()->find($channel);
   if($row) return array_merge($row->settings,['enabled'=>(bool)$row->enabled,'source'=>'web']);
  }
  $a=config('sag.alerts');
  $settings=match($channel){
   'telegram'=>['bot_token'=>$a['telegram_bot_token']??'','chat_id'=>$a['telegram_chat_id']??''],
   'slack'=>['webhook_url'=>$a['slack_webhook_url']??''],
   'teams'=>['webhook_url'=>$a['teams_webhook_url']??'','allowed_hosts'=>$a['allowed_hosts']??''],
   'nextcloud_talk'=>['nextcloud_url'=>$a['nextcloud_url']??'','user'=>$a['nextcloud_user']??'','app_password'=>$a['nextcloud_app_password']??'','room_token'=>$a['nextcloud_room_token']??'','allowed_hosts'=>$a['allowed_hosts']??''],
   'webhook'=>['webhook_url'=>$a['webhook_url']??'','allowed_hosts'=>$a['allowed_hosts']??''],
   'email'=>['email_to'=>$a['email_to']??'','smtp_host'=>'','smtp_port'=>465,'smtp_username'=>'','smtp_password'=>'','from_address'=>config('mail.from.address')],
  };
  return $settings+['enabled'=>$channel==='email' ? (filled($a['email_to']??'')&&!in_array(config('mail.default'),['log','array'],true)) : $this->ready($channel,$settings),'source'=>'environment'];
 }
 public function ready(string $channel,array $settings): bool {
  foreach(self::FIELDS[$channel] as $key) {
   if($channel==='email'&&in_array($key,['smtp_username','smtp_password'],true)) continue;
   if(!filled($settings[$key]??null))return false;
  }
  if($channel==='email')return (int)$settings['smtp_port']===465;
  if(in_array($channel,['teams','webhook','nextcloud_talk'],true)) {
   $url=$settings[$channel==='nextcloud_talk'?'nextcloud_url':'webhook_url']??'';
   $host=strtolower((string)parse_url((string)$url,PHP_URL_HOST));
   $hosts=array_map(fn($h)=>strtolower(trim($h)),explode(',',(string)($settings['allowed_hosts']??'')));
   return $host!==''&&in_array($host,$hosts,true);
  }
  return true;
 }
 public function save(string $channel,bool $enabled,array $settings): void {
  AlertChannelSetting::query()->updateOrCreate(['channel'=>$channel],['enabled'=>$enabled,'settings'=>$settings]);
 }
}
