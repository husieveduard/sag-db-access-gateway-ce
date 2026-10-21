@extends('layouts.app')
@section('title','Налаштування каналів')
@section('content')
<div class="settings-page">
 <div class="settings-page-header">
  <div>
   <h1 class="page-title">Налаштування каналів</h1>
   <p class="subtitle">Керуйте адресами і секретами сповіщень без SSH. Дані зберігаються зашифрованими.</p>
  </div>
  <a class="button secondary" href="{{ route('admin.alert-health.index') }}">← Стан сповіщень</a>
 </div>
 @php
 $labels=[
  'telegram'=>'Telegram','slack'=>'Slack','teams'=>'Microsoft Teams',
  'nextcloud_talk'=>'Nextcloud Talk','webhook'=>'HTTPS Webhook','email'=>'Email / SMTPS',
 ];
 $fields=[
  'bot_token'=>'Токен бота','chat_id'=>'Chat ID','webhook_url'=>'Webhook URL',
  'allowed_hosts'=>'Дозволені DNS-хости (через кому)','nextcloud_url'=>'Nextcloud HTTPS URL',
  'user'=>'Користувач Nextcloud','app_password'=>'App password',
  'room_token'=>'Токен кімнати','email_to'=>'Адреса отримувача',
  'smtp_host'=>'SMTP сервер (FQDN)','smtp_port'=>'SMTPS порт (465)',
  'smtp_username'=>'SMTP логін','smtp_password'=>'SMTP пароль',
  'from_address'=>'Email відправника',
 ];
 @endphp
 @foreach($cards as $card)
 <section class="card settings-panel" style="margin-bottom:18px">
  <div class="settings-panel-heading">
   <h2>{{ $labels[$card['channel']] }}</h2>
   <p>{{ $card['source']==='web'?'Керування через веб':'Поточні параметри з environment (до першого збереження)' }}</p>
  </div>
  <form method="POST" action="{{ route('admin.alert-channels.update',$card['channel']) }}" autocomplete="off">
   @csrf
   <div class="settings-form-grid">
    <div class="settings-field">
     <label for="{{ $card['channel'] }}-enabled">Стан каналу</label>
     <select id="{{ $card['channel'] }}-enabled" name="enabled">
      <option value="0" @selected(!$card['enabled'])>Вимкнено</option>
      <option value="1" @selected($card['enabled'])>Увімкнено</option>
     </select>
    </div>
    @foreach(\App\Services\Alerts\AlertChannelConfiguration::FIELDS[$card['channel']] as $key)
     @php $secret=in_array($key,\App\Services\Alerts\AlertChannelConfiguration::SECRET[$card['channel']],true); @endphp
     <div class="settings-field {{ in_array($key,['webhook_url','allowed_hosts','nextcloud_url'],true)?'settings-span-full':'' }}">
      <label for="{{ $card['channel'] }}-{{ $key }}">{{ $fields[$key] }}</label>
      <input
       id="{{ $card['channel'] }}-{{ $key }}"
       name="{{ $key }}"
       type="{{ $secret?'password':($key==='smtp_port'?'number':(in_array($key,['email_to','from_address'],true)?'email':'text')) }}"
       value="{{ $secret?'':$card['values'][$key] }}"
       @if($secret) autocomplete="new-password" placeholder="{{ $card['secretSet'][$key]?'Збережено — залиште порожнім для збереження':'Введіть секрет' }}" @endif
       @if($key==='smtp_port') min="465" max="465" @endif
       maxlength="2048"
      >
      @if($secret&&$card['secretSet'][$key])<p class="settings-field-hint">Секрет збережено. Для заміни введіть новий; поточний не відображається.</p>@endif
     </div>
    @endforeach
   </div>
   <div class="settings-actions">
    <button class="button primary" type="submit">Зберегти {{ $labels[$card['channel']] }}</button>
    <span class="settings-status {{ $card['configured']&&$card['enabled']?'settings-status-on':'settings-status-off' }}">{{ $card['configured']&&$card['enabled']?'Налаштовано':'Вимкнено / не готовий' }}</span>
   </div>
  </form>
 </section>
 @endforeach
 <p class="settings-field-hint">SMTPS: порт 465 із TLS. Після збереження відкрийте «Стан сповіщень» і надішліть тест. Не використовуйте приватні IP як webhook-адреси.</p>
</div>
@endsection
