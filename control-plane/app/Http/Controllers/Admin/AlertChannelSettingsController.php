<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Services\Alerts\AlertChannelConfiguration as Channels;
use App\Services\Alerts\OutboundEndpointPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
class AlertChannelSettingsController extends Controller {
 public function index(Request $request,Channels $channels): View {
  abort_unless($request->user()->isSuperAdmin(),403);
  $cards=[];
  foreach(Channels::FIELDS as $channel=>$fields){
   $settings=$channels->get($channel);
   $values=[];$secretSet=[];
   foreach($fields as $key){
    $secret=in_array($key,Channels::SECRET[$channel],true);
    $values[$key]=$secret?'':($settings[$key]??'');
    if($secret)$secretSet[$key]=filled($settings[$key]??'');
   }
   $cards[]=['channel'=>$channel,'enabled'=>$settings['enabled'],
    'configured'=>$channels->ready($channel,$settings),'source'=>$settings['source'],
    'values'=>$values,'secretSet'=>$secretSet];
  }
  return view('admin.alerts.channel-settings',['cards'=>$cards]);
 }
 public function update(Request $request,string $channel,Channels $channels,OutboundEndpointPolicy $policy): RedirectResponse {
  abort_unless($request->user()->isSuperAdmin(),403);
  abort_unless(isset(Channels::FIELDS[$channel]),404);
  $rules=['enabled'=>['required','boolean']];
  foreach(Channels::FIELDS[$channel] as $key){
   $rules[$key]=['nullable','string','max:2048'];
   if(in_array($key,['email_to','from_address'],true))$rules[$key][]='email';
  }
  if($channel==='email') $rules['smtp_port']=['nullable','integer','in:465'];
  $data=$request->validate($rules);
  $old=$channels->get($channel);
  $settings=[];
  foreach(Channels::FIELDS[$channel] as $key) {
   $value=trim((string)($data[$key]??''));
   if($value===''&&in_array($key,Channels::SECRET[$channel],true))$value=(string)($old[$key]??'');
   $settings[$key]=$value;
  }
  if($channel==='email') {
   $settings['smtp_port']=(int)($settings['smtp_port']?:465);
   if(filled($settings['smtp_username'])!==filled($settings['smtp_password'])){
    throw ValidationException::withMessages(['smtp_username'=>'SMTP логін і пароль потрібно вказати разом.']);
   }
   if(filled($settings['smtp_host'])&&!preg_match('/^(?=.{4,253}$)[a-zA-Z0-9][a-zA-Z0-9.-]*[a-zA-Z0-9]$/D',$settings['smtp_host'])){
    throw ValidationException::withMessages(['smtp_host'=>'Вкажіть DNS-ім’я SMTP сервера, не IP-адресу.']);
   }
   $host=strtolower($settings['smtp_host']);
   if($host==='localhost'||str_ends_with($host,'.localhost')||filter_var($host,FILTER_VALIDATE_IP)){
    throw ValidationException::withMessages(['smtp_host'=>'Локальні та IP-адреси заборонено.']);
   }
  }elseif(in_array($channel,['slack','teams','webhook','nextcloud_talk'],true)){
   $url=$settings[$channel==='nextcloud_talk'?'nextcloud_url':'webhook_url'];
   if(filled($url)){
    try{$policy->assertAllowed($channel,$url,$settings['allowed_hosts']??'');}
    catch(\Throwable){throw ValidationException::withMessages(['webhook_url'=>'HTTPS адреса або дозволені DNS-хости некоректні.']);}
   }
  }elseif($channel==='telegram'&&filled($settings['bot_token'])&&!preg_match('/^[0-9]+:[A-Za-z0-9_-]+$/D',$settings['bot_token'])){
   throw ValidationException::withMessages(['bot_token'=>'Некоректний формат токена Telegram.']);
  }
  $enabled=(bool)$data['enabled'];
  if($enabled&&!$channels->ready($channel,$settings)){
   throw ValidationException::withMessages(['enabled'=>'Заповніть необхідні поля та дозволені хости перед увімкненням.']);
  }
  DB::transaction(function()use($channels,$channel,$enabled,$settings,$request,$old){
   $channels->save($channel,$enabled,$settings);
   AuditEvent::query()->create([
    'actor_user_id'=>$request->user()->id,'category'=>'configuration',
    'event_type'=>'alerts.channel.updated','severity'=>'high',
    'ip_address'=>$request->ip(),
    'event_data'=>['channel'=>$channel,'enabled'=>$enabled,'previous_enabled'=>$old['enabled'],
      'source'=>'web_console','changed_keys'=>array_keys(Channels::FIELDS[$channel] ? $settings : [])],
    'occurred_at'=>now(),
   ]);
  });
  return redirect()->route('admin.alert-channels.index')->with('success','Канал збережено. Секрети приховані.');
 }
}
