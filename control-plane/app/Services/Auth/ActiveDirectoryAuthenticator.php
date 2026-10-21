<?php

namespace App\Services\Auth;

use Illuminate\Support\Str;
use RuntimeException;

class ActiveDirectoryAuthenticator
{
    /**
     * Validate an AD password and required group membership. No AD password is stored.
     *
     * @return array{guid:string,email:string,name:string}|null
     */
    public function authenticate(string $identity, string $password): ?array
    {
        $settings = app(AdSettings::class)->get();

        if (!($settings['enabled'] ?? false) || $password === '') {
            return null;
        }

        $url = trim((string) ($settings['url'] ?? ''));
        $domain = Str::lower(trim((string) ($settings['domain'] ?? '')));
        $baseDn = trim((string) ($settings['base_dn'] ?? ''));
        $groupDn = trim((string) ($settings['allowed_group_dn'] ?? ''));
        $netbios = trim((string) ($settings['netbios_domain'] ?? ''));
        $caFile = trim((string) ($settings['ca_file'] ?? ''));

        if (!extension_loaded('ldap') || $domain === '' || $baseDn === '' || $groupDn === '') {
            throw new RuntimeException('Active Directory authentication is not configured correctly.');
        }
        $transport = DirectoryTransport::resolve($settings, (string) config('app.env'));

        $login = $this->parseIdentity($identity, $domain, $netbios);
        if ($login === null) {
            return null;
        }

        if ($transport['encrypted']) {
            DirectoryTransport::configureTls($caFile);
        }

        $ldap = @ldap_connect($url);
        if ($ldap === false) {
            return null;
        }

        try {
            ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, 5);
            ldap_set_option($ldap, LDAP_OPT_TIMELIMIT, 5);

            if ($transport['starttls']) {
                DirectoryTransport::upgradeStartTls($ldap);
            }

            // Bind with the actual user's credentials; there is no service bind secret.
            if (!@ldap_bind($ldap, $login['principal'], $password)) {
                return null;
            }

            // AD transitive membership includes nested groups. Disabled accounts denied.
            $filter = '(&(objectCategory=person)(objectClass=user)'
                .'(!(userAccountControl:1.2.840.113556.1.4.803:=2))'
                .'('.$login['attribute'].'='.ldap_escape($login['value'], '', LDAP_ESCAPE_FILTER).')'
                .'(memberOf:1.2.840.113556.1.4.1941:='
                .ldap_escape($groupDn, '', LDAP_ESCAPE_FILTER).'))';

            $result = @ldap_search(
                $ldap,
                $baseDn,
                $filter,
                ['objectGUID', 'sAMAccountName', 'userPrincipalName', 'displayName'],
                0,
                2,
                5
            );

            if ($result === false || ldap_count_entries($ldap, $result) !== 1) {
                return null;
            }

            $entry = ldap_first_entry($ldap, $result);
            if ($entry === false) {
                return null;
            }

            $guidValues = ldap_get_values_len($ldap, $entry, 'objectGUID');
            if ($guidValues === false || ($guidValues['count'] ?? 0) !== 1
                || strlen($guidValues[0]) !== 16) {
                return null;
            }

            $sam = $this->attribute($ldap, $entry, 'sAMAccountName');
            $upn = Str::lower($this->attribute($ldap, $entry, 'userPrincipalName'));
            $name = $this->attribute($ldap, $entry, 'displayName');

            if ($sam === '' || $upn === '' || !filter_var($upn, FILTER_VALIDATE_EMAIL)) {
                return null;
            }

            return [
                'guid' => bin2hex($guidValues[0]),
                'email' => $upn,
                'name' => Str::limit($name ?: $sam, 120, ''),
            ];
        } finally {
            @ldap_unbind($ldap);
        }
    }

    /**
     * @return array{principal:string,attribute:string,value:string}|null
     */
    private function parseIdentity(string $identity, string $domain, string $netbios): ?array
    {
        $identity = trim($identity);

        if (str_contains($identity, '\\')) {
            $parts = explode('\\', $identity, 2);
            if ($netbios === '' || !hash_equals(Str::lower($netbios), Str::lower($parts[0]))) {
                return null;
            }
            $identity = $parts[1];
        }

        if (str_contains($identity, '@')) {
            if (!str_ends_with(Str::lower($identity), '@'.$domain)
                || !filter_var($identity, FILTER_VALIDATE_EMAIL)) {
                return null;
            }

            return [
                'principal' => $identity,
                'attribute' => 'userPrincipalName',
                'value' => $identity,
            ];
        }

        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $identity)) {
            return null;
        }

        return [
            'principal' => $identity.'@'.$domain,
            'attribute' => 'sAMAccountName',
            'value' => $identity,
        ];
    }

    private function attribute(\LDAP\Connection $ldap, \LDAP\ResultEntry $entry, string $name): string
    {
        $values = ldap_get_values($ldap, $entry, $name);

        return ($values !== false && ($values['count'] ?? 0) > 0)
            ? trim((string) $values[0])
            : '';
    }
}
