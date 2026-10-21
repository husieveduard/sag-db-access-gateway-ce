@extends('layouts.app')
@section('title','Active Directory')
@section('content')
<h1 class="page-title">Active Directory</h1>
<p class="subtitle">Налаштування авторизації через AD. LDAPS або LDAP з обов’язковим StartTLS.</p>
<div class="card"><form method="POST" action="{{ route('admin.ad-settings.update') }}" enctype="multipart/form-data">
@csrf
<label>Доменна авторизація</label><select name="enabled"><option value="0" @selected(!$settings['enabled'])>Вимкнено</option><option value="1" @selected($settings['enabled'])>Увімкнено</option></select>
<label>Протокол</label><select name="protocol"><option value="ldaps" @selected(($settings['protocol']??'ldaps')==='ldaps')>LDAPS (TLS, 636)</option><option value="starttls" @selected(($settings['protocol']??'')==='starttls')>LDAP + StartTLS (389)</option></select>
<label>AD сервер (FQDN)</label><input name="host" required value="{{ old('host',$settings['host']??'') }}">
<label>Порт</label><input type="number" name="port" min="1" max="65535" required value="{{ old('port',$settings['port']??636) }}">
<label>DNS домен</label><input name="domain" required value="{{ old('domain',$settings['domain']??'') }}">
<label>NetBIOS домен</label><input name="netbios_domain" value="{{ old('netbios_domain',$settings['netbios_domain']??'') }}">
<label>Base DN</label><input name="base_dn" required value="{{ old('base_dn',$settings['base_dn']??'') }}">
<label>Група доступу (DN)</label><input name="allowed_group_dn" required value="{{ old('allowed_group_dn',$settings['allowed_group_dn']??'') }}">
<label>Публічний CA сертифікат (PEM, необов’язково)</label><input type="file" name="ca_cert" accept=".pem,.crt,.txt">
<p>CA файл: {{ $settings['ca_file'] ? 'завантажено' : 'системне сховище довіри' }}. Приватні ключі не завантажуйте.</p>
<button class="button" type="submit">Зберегти</button></form></div>
<div class="card"><form method="POST" action="{{ route('admin.ad-settings.test') }}">@csrf<button class="button" type="submit">Перевірити TLS-підключення</button></form>
<p>Перевірка не змінює конфігурацію та не передає пароль.</p></div>
@endsection
