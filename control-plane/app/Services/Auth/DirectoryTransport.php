<?php

namespace App\Services\Auth;

use RuntimeException;

/**
 * Validate the selected AD transport before opening a connection.
 *
 * ldaps: LDAP over TLS, typically TCP/636
 * starttls: LDAP TCP/389, upgraded to TLS BEFORE binding
 * ldap: unencrypted, explicitly opted-in non-production diagnostics only
 */
final class DirectoryTransport
{
    /**
     * @return array{mode:string,url:string,host:string,port:int,encrypted:bool,starttls:bool}
     */
    public static function resolve(array $settings, string $environment = 'production'): array
    {
        $mode = strtolower(trim((string) ($settings['protocol'] ?? 'ldaps')));
        $url = trim((string) ($settings['url'] ?? ''));

        if (!in_array($mode, ['ldaps', 'starttls', 'ldap'], true)) {
            throw new RuntimeException('Unsupported AD LDAP transport. Choose ldaps, starttls or ldap.');
        }

        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        $expectedScheme = $mode === 'ldaps' ? 'ldaps' : 'ldap';

        if (!is_array($parts)
            || $scheme !== $expectedScheme
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['path'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new RuntimeException('Active Directory URI does not match the selected protocol.');
        }

        $port = (int) ($parts['port'] ?? ($mode === 'ldaps' ? 636 : 389));
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Active Directory port is invalid.');
        }

        if ($mode === 'ldap') {
            // Never allow LDAP simple bind passwords over an unencrypted
            // network on a production CE deployment.
            if ($environment === 'production' || !($settings['allow_insecure'] ?? false)) {
                throw new RuntimeException('Unencrypted LDAP is restricted to opted-in non-production test environments.');
            }
        }

        return [
            'mode' => $mode,
            'url' => $url,
            'host' => $host,
            'port' => $port,
            'encrypted' => $mode !== 'ldap',
            'starttls' => $mode === 'starttls',
        ];
    }

    public static function configureTls(string $caFile): void
    {
        // OpenLDAP TLS options are global and must precede ldap_connect.
        if (!ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_HARD)) {
            throw new RuntimeException('Could not enforce LDAP TLS certificate verification.');
        }

        if ($caFile !== '') {
            if (!is_readable($caFile)) {
                throw new RuntimeException('Configured Active Directory CA file is unreadable.');
            }

            if (!ldap_set_option(null, LDAP_OPT_X_TLS_CACERTFILE, $caFile)) {
                throw new RuntimeException('Could not configure Active Directory CA trust.');
            }
        }
    }

    public static function upgradeStartTls(\LDAP\Connection $ldap): void
    {
        if (!@ldap_start_tls($ldap)) {
            throw new RuntimeException('Active Directory StartTLS failed; credentials were not transmitted.');
        }
    }
}
