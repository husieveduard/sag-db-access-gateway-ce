<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('ad_settings',function(Blueprint $t){$t->unsignedTinyInteger('id')->primary();$t->boolean('enabled')->default(false);$t->string('protocol',16)->default('ldaps');$t->string('host',255)->default('');$t->unsignedSmallInteger('port')->default(636);$t->string('domain',255)->default('');$t->string('netbios_domain',64)->default('');$t->string('base_dn',1024)->default('');$t->string('allowed_group_dn',1024)->default('');$t->boolean('ca_uploaded')->default(false);$t->timestampTz('updated_at')->nullable();});
  // One-time import of existing installations. No runtime .env fallback.
  $old=['enabled'=>filter_var(env('SAG_CE_LDAP_ENABLED',false),FILTER_VALIDATE_BOOLEAN),'protocol'=>env('SAG_CE_LDAP_PROTOCOL','ldaps'),'url'=>env('SAG_CE_LDAP_URL',''),'domain'=>env('SAG_CE_LDAP_DOMAIN',''),'netbios_domain'=>env('SAG_CE_LDAP_NETBIOS_DOMAIN',''),'base_dn'=>env('SAG_CE_LDAP_BASE_DN',''),'allowed_group_dn'=>env('SAG_CE_LDAP_ALLOWED_GROUP_DN','')];
  $url=(string)($old['url']??'');$parts=parse_url($url)?:[];
  $protocol=(string)($old['protocol']??'ldaps');
  if(!in_array($protocol,['ldaps','starttls'],true)){$protocol='ldaps';}
  $port=(int)($parts['port']??($protocol==='ldaps'?636:389));
  DB::table('ad_settings')->insert(['id'=>1,'enabled'=>(bool)($old['enabled']??false),'protocol'=>$protocol,'host'=>(string)($parts['host']??''),'port'=>$port,'domain'=>(string)($old['domain']??''),'netbios_domain'=>(string)($old['netbios_domain']??''),'base_dn'=>(string)($old['base_dn']??''),'allowed_group_dn'=>(string)($old['allowed_group_dn']??''),'updated_at'=>now()]);
 }
 public function down(): void {Schema::dropIfExists('ad_settings');}
};