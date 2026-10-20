<?php

namespace App\Console\Commands;

use App\Services\Auth\ActiveDirectoryAuthenticator;
use Illuminate\Console\Command;
use RuntimeException;

class SagCeAdCheck extends Command
{
    protected $signature = 'sag:ce-ad-check
        {--user= : Optionally validate a domain user and their required AD group membership}';

    protected $description = 'Check CE AD/LDAPS TLS connectivity and optional administrator authorization';

    public function handle(ActiveDirectoryAuthenticator $directory): int
    {
        $ldap = (array) config('sag.ldap', []);

        if (!($ldap['enabled'] ?? false)) {
            $this->error('AD authentication is disabled (SAG_CE_LDAP_ENABLED=false).');

            return self::FAILURE;
        }

        if (!extension_loaded('ldap')) {
            $this->error('PHP LDAP extension is missing.');

            return self::FAILURE;
        }

        $url = trim((string) ($ldap['url'] ?? ''));
        $baseDn = trim((string) ($ldap['base_dn'] ?? ''));
        $groupDn = trim((string) ($ldap['allowed_group_dn'] ?? ''));
        $domain = trim((string) ($ldap['domain'] ?? ''));
        $caFile = trim((string) ($ldap['ca_file'] ?? ''));

        if (parse_url($url, PHP_URL_SCHEME) !== 'ldaps'
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null
            || $domain === '' || $baseDn === '' || $groupDn === '') {
            $this->error('Invalid AD configuration: LDAPS URL, domain, base DN and allowed group DN are required.');

            return self::FAILURE;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT) ?: 636;

        if (!is_string($host) || $host === '' || !is_int($port)
            || $port < 1 || $port > 65535) {
            $this->error('Invalid AD LDAPS hostname or port.');

            return self::FAILURE;
        }

        if ($caFile !== '' && !is_readable($caFile)) {
            $this->error('Configured AD CA certificate is not readable inside the container.');

            return self::FAILURE;
        }

        $sslOptions = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => trim($host, '[]'),
            'SNI_enabled' => true,
        ];
        if ($caFile !== '') {
            $sslOptions['cafile'] = $caFile;
        }

        $context = stream_context_create(['ssl' => $sslOptions]);

        $errorCode = 0;
        $errorMessage = '';
        // An SSL handshake verifies the DC's certificate and hostname without
        // transmitting a username, password or binding anonymously to AD.
        $socket = @stream_socket_client(
            'tls://'.$host.':'.$port,
            $errorCode,
            $errorMessage,
            5,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            $this->error(
                'LDAPS TLS connection or certificate validation failed. '
                .'Check the domain controller DNS name, TCP port and trusted CA.'
            );

            return self::FAILURE;
        }

        fclose($socket);

        $this->info('LDAPS TLS handshake succeeded; certificate and hostname verified.');
        $this->line('AD authorization group configured. No credentials were transmitted.');

        $identity = trim((string) $this->option('user'));
        if ($identity === '') {
            $this->line('To test the AD password and group authorization, use --user=DOMAIN\\login.');

            return self::SUCCESS;
        }

        // Never accept a password as a CLI option, environment variable or
        // command-line argument; secret() disables terminal echo.
        $password = (string) $this->secret('AD password (hidden, not saved)');
        if ($password === '') {
            $this->error('No password was supplied.');

            return self::FAILURE;
        }

        try {
            $authorized = $directory->authenticate($identity, $password);
        } catch (RuntimeException $exception) {
            $this->error('AD authentication is not available. Check LDAP configuration and TLS CA.');

            return self::FAILURE;
        } finally {
            unset($password);
        }

        if ($authorized === null) {
            $this->error('AD bind failed or the account is not enabled / not a member of the allowed group.');

            return self::FAILURE;
        }

        $this->info('AD password and allowed group membership verified.');
        $this->line('No user profile or access session was created.');

        return self::SUCCESS;
    }
}
