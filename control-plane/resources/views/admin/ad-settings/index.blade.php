@extends('layouts.app')

@section('title', 'Active Directory')

@section('content')
<div class="settings-page">
    <div class="settings-page-header">
        <div>
            <h1 class="page-title">Active Directory</h1>
            <p class="subtitle">Доменний вхід адміністраторів через захищене LDAPS або LDAP з обов’язковим StartTLS.</p>
        </div>
        <span class="settings-status {{ $settings['enabled'] ? 'settings-status-on' : 'settings-status-off' }}">
            {{ $settings['enabled'] ? 'Авторизацію увімкнено' : 'Авторизацію вимкнено' }}
        </span>
    </div>

    <section class="card settings-panel" aria-labelledby="ad-connection-heading">
        <div class="settings-panel-heading">
            <h2 id="ad-connection-heading">Параметри підключення</h2>
            <p>Вкажіть доменний контролер і групу AD, якій дозволено входити до SAG CE.</p>
        </div>

        <form method="POST" action="{{ route('admin.ad-settings.update') }}" enctype="multipart/form-data">
            @csrf

            <div class="settings-form-grid">
                <div class="settings-field">
                    <label for="ad-enabled">Доменна авторизація</label>
                    <select id="ad-enabled" name="enabled" required>
                        <option value="0" @selected((string) old('enabled', $settings['enabled'] ? '1' : '0') === '0')>Вимкнено</option>
                        <option value="1" @selected((string) old('enabled', $settings['enabled'] ? '1' : '0') === '1')>Увімкнено</option>
                    </select>
                    @error('enabled')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="settings-field">
                    <label for="ad-protocol">Протокол</label>
                    <select id="ad-protocol" name="protocol" required>
                        <option value="ldaps" @selected(old('protocol', $settings['protocol'] ?? 'ldaps') === 'ldaps')>LDAPS — TLS (636)</option>
                        <option value="starttls" @selected(old('protocol', $settings['protocol'] ?? 'ldaps') === 'starttls')>LDAP + StartTLS (389)</option>
                    </select>
                    @error('protocol')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="settings-field">
                    <label for="ad-host">AD сервер (FQDN)</label>
                    <input id="ad-host" name="host" type="text" required maxlength="255" autocomplete="off"
                           placeholder="dc01.example.local" value="{{ old('host', $settings['host'] ?? '') }}">
                    @error('host')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="settings-field">
                    <label for="ad-port">Порт</label>
                    <input id="ad-port" name="port" type="number" min="1" max="65535" required
                           value="{{ old('port', $settings['port'] ?? 636) }}">
                    @error('port')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="settings-field">
                    <label for="ad-domain">DNS домен</label>
                    <input id="ad-domain" name="domain" type="text" required maxlength="255"
                           autocomplete="off" placeholder="example.local"
                           value="{{ old('domain', $settings['domain'] ?? '') }}">
                    @error('domain')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="settings-field">
                    <label for="ad-netbios">NetBIOS домен</label>
                    <input id="ad-netbios" name="netbios_domain" type="text" maxlength="64" autocomplete="off"
                           placeholder="EXAMPLE" value="{{ old('netbios_domain', $settings['netbios_domain'] ?? '') }}">
                    <p class="settings-field-hint">Необов’язково; використовується для входу DOMAIN\user.</p>
                    @error('netbios_domain')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="settings-field settings-span-full">
                    <label for="ad-base-dn">Base DN</label>
                    <input id="ad-base-dn" name="base_dn" type="text" required maxlength="1024" autocomplete="off"
                           placeholder="DC=example,DC=local" value="{{ old('base_dn', $settings['base_dn'] ?? '') }}">
                    @error('base_dn')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="settings-field settings-span-full">
                    <label for="ad-group-dn">Група доступу (DN)</label>
                    <input id="ad-group-dn" name="allowed_group_dn" type="text" required maxlength="1024" autocomplete="off"
                           placeholder="CN=SAG-Operators,OU=Groups,DC=example,DC=local"
                           value="{{ old('allowed_group_dn', $settings['allowed_group_dn'] ?? '') }}">
                    <p class="settings-field-hint">Доступ отримають лише користувачі дозволеної AD-групи.</p>
                    @error('allowed_group_dn')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="settings-field settings-span-full settings-certificate">
                    <label for="ad-ca-cert">Публічний CA-сертифікат (PEM, необов’язково)</label>
                    <input id="ad-ca-cert" name="ca_cert" type="file" accept=".pem,.crt,.txt">
                    <p class="settings-field-hint">
                        {{ $settings['ca_file'] ? 'Сертифікат CA вже завантажено.' : 'Використовується системне сховище довіри.' }}
                        Завантажуйте лише публічний сертифікат CA — не приватний ключ.
                    </p>
                    @error('ca_cert')<p class="field-error">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="settings-actions">
                <button class="button primary" type="submit">Зберегти налаштування</button>
                <p class="settings-field-hint">Зміни вплинуть на наступні спроби доменного входу.</p>
            </div>
        </form>
    </section>

    <section class="card settings-panel settings-test-panel" aria-labelledby="ad-test-heading">
        <div>
            <h2 id="ad-test-heading">Перевірка TLS-з’єднання</h2>
            <p>Перевіряється збережена конфігурація. Пароль користувача не надсилається; членство у групі AD тут не перевіряється.</p>
        </div>
        <form method="POST" action="{{ route('admin.ad-settings.test') }}">
            @csrf
            <button class="button secondary" type="submit">Перевірити підключення</button>
        </form>
    </section>
</div>
@endsection
