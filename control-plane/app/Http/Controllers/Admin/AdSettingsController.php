<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Services\Auth\AdSettings;
use App\Services\Auth\DirectoryTransport;
use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use RuntimeException;
class AdSettingsController extends Controller {
 public function index(Request $request,AdSettings $settings):View {
  abort_unless($request->user()->isSuperAdmin(),403);
  return view('admin.ad-settings.index',['settings'=>$settings->get()]);
 }
 public function update(Request $request):RedirectResponse {
  abort_unless($request->user()->isSuperAdmin(),403);
  $data=$request->validate([
    'enabled'=>['required','boolean'],'protocol'=>['required','in:ldaps,starttls'],
    'host'=>['required','string','max:255','regex:/^[a-zA-Z0-9][a-zA-Z0-9.\-]*$/'],
    'port'=>['required','integer','between:1,65535'],
    'domain'=>['required','string','max:255'],
    'netbios_domain'=>['nullable','string','max:64'],
    'base_dn'=>['required','string','max:1024'],
    'allowed_group_dn'=>['required','string','max:1024'],
    'ca_cert'=>['nullable','file','max:256','mimes:pem,crt,txt'],
  ]);
  $mode=$data['protocol'];$url=($mode==='ldaps'?'ldaps':'ldap').'://'.$data['host'].':'.$data['port'];
  DirectoryTransport::resolve(['protocol'=>$mode,'url'=>$url],'production');
  $uploaded=false;
  if($request->hasFile('ca_cert')){
   $pem=file_get_contents($request->file('ca_cert')->getRealPath());
   if(strlen($pem)>262144||!str_contains($pem,'-----BEGIN CERTIFICATE-----')||!openssl_x509_read($pem))return back()->withErrors(['ca_cert'=>'Invalid public CA certificate']);
   Storage::disk('local')->put('ad-ca.pem',$pem);$uploaded=true;
  }
  $before=DB::table('ad_settings')->where('id',1)->first();
  DB::table('ad_settings')->where('id',1)->update([
   'enabled'=>(bool)$data['enabled'],'protocol'=>$mode,'host'=>$data['host'],'port'=>(int)$data['port'],
   'domain'=>$data['domain'],'netbios_domain'=>$data['netbios_domain']??'',
   'base_dn'=>$data['base_dn'],'allowed_group_dn'=>$data['allowed_group_dn'],
   'ca_uploaded'=>$uploaded||($before?->ca_uploaded??false),'updated_at'=>now(),
  ]);
  AuditEvent::query()->create(['actor_user_id'=>$request->user()->id,'category'=>'configuration','event_type'=>'ad.settings.updated','severity'=>'high','ip_address'=>$request->ip(),'event_data'=>['enabled'=>(bool)$data['enabled'],'protocol'=>$mode,'host'=>$data['host'],'ca_updated'=>$uploaded],'occurred_at'=>now()]);
  return back()->with('success','Налаштування Active Directory збережено.');
 }
 public function test(Request $request,AdSettings $repository):RedirectResponse {
  abort_unless($request->user()->isSuperAdmin(),403);
  $config=$repository->get();
  try {
   if(!extension_loaded('ldap'))throw new RuntimeException('extension_missing');
   $transport=DirectoryTransport::resolve($config,'production');
   DirectoryTransport::configureTls((string)$config['ca_file']);
   $conn=@ldap_connect($transport['url']);
   if($conn===false)throw new RuntimeException('connect_failed');
   try {
    ldap_set_option($conn,LDAP_OPT_PROTOCOL_VERSION,3);
    ldap_set_option($conn,LDAP_OPT_REFERRALS,0);
    ldap_set_option($conn,LDAP_OPT_NETWORK_TIMEOUT,5);
    if($transport['starttls'])DirectoryTransport::upgradeStartTls($conn);
    elseif(@ldap_read($conn,'','(objectClass=*)',['dn'],0,1,5)===false)throw new RuntimeException('tls_failed');
   }finally{@ldap_unbind($conn);}
   $message='AD TLS-підключення встановлено. Облікові дані не передавались.';
  }catch(\Throwable){return back()->withErrors(['ad'=>'Не вдалося перевірити TLS-з’єднання. Перевірте сервер, CA та доступність порту.']);}
  return back()->with('success',$message);
 }
}
