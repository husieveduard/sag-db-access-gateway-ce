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

        if ($domain === '' || $baseDn === '' || $groupDn === '') {
            $this->error('AD domain, base DN and group DN are required.');
            return self::FAILURE;
        }

        try {
            $transport = \App\Services\Auth\DirectoryTransport::resolve($ldap, (string) config('app.env'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        if ($transport['encrypted']) {
            try {
                \App\Services\Auth\DirectoryTransport::configureTls($caFile);
            } catch (RuntimeException $exception) {
                $this->error('LDAP CA/TLS configuration invalid.');
                return self::FAILURE;
            }
        }

        $connection = @ldap_connect($transport['url']);
        if ($connection === false) {
            $this->error('AD server connection initialization failed.');
            return self::FAILURE;
        }

        try {
            ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, 5);

            if ($transport['starttls']) {
                \App\Services\Auth\DirectoryTransport::upgradeStartTls($connection);
            } elseif ($transport['mode'] === 'ldaps') {
                // Force a network operation to verify TLS now without credentials.
                if (@ldap_read($connection, '', '(objectClass=*)', ['dn'], 0, 1, 5) === false) {
                    throw new RuntimeException('LDAPS handshake / certificate validation failed.');
                }
            } else {
                // Explicit non-production plaintext mode; no credentials sent yet.
                $this->warn('WARNING: LDAP transport is unencrypted. Lab use only.');
            }
        } catch (RuntimeException $exception) {
            $this->error('AD TLS connection failed; check hostname, CA and protocol.');
            return self::FAILURE;
        } finally {
            @ldap_unbind($connection);
        }

        $this->info('AD transport check completed: '.$transport['mode']);
        $this->line('No credentials were transmitted in the connectivity check.');

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
