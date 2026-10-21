<?php
namespace App\Services\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
class AdSettings {
 public function get(): array {
  if(!Schema::hasTable('ad_settings')) return ['enabled'=>false];
  $row=DB::table('ad_settings')->where('id',1)->first();
  if(!$row)return ['enabled'=>false];
  $protocol=(string)$row->protocol;
  $scheme=$protocol==='ldaps'?'ldaps':'ldap';
  return ['enabled'=>(bool)$row->enabled,'protocol'=>$protocol,'allow_insecure'=>false,
   'url'=>$scheme.'://'.$row->host.':'.$row->port,'host'=>$row->host,'port'=>(int)$row->port,
   'domain'=>$row->domain,'netbios_domain'=>$row->netbios_domain,
   'base_dn'=>$row->base_dn,'allowed_group_dn'=>$row->allowed_group_dn,
   'ca_file'=>$row->ca_uploaded?storage_path('app/private/ad-ca.pem'):''];
 }
}
