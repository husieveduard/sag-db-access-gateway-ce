<?php

namespace App\Services\Credentials;

use App\Models\DatabaseResource;
use App\Models\DbAccessSession;
use DomainException;
use Throwable;

/** Runtime-owned references only. AD keys never belong in resource options. */
final class ManagedKerberosProfiles
{
    /** Only public aliases reach the resource form; never serialize profiles. */
    public function aliases(): array
    {
        try {
            return array_keys($this->profiles());
        } catch (Throwable) {
            return [];
        }
    }

    public function supportsResource(DatabaseResource $resource): bool
    {
        try {
            $this->profileFor($resource);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function supportsSession(DbAccessSession $session): bool
    {
        return $session->resource !== null && $this->supportsResource($session->resource)
            && data_get($session->metadata, 'db.backend_auth_mode') === 'mssql_kerberos_service'
            && data_get($session->metadata, 'db.kerberos_profile') === data_get($session->resource->target_options, 'kerberos_profile');
    }

    public function profileFor(DatabaseResource $resource): array
    {
        if (config('sag.db_gateway.kerberos.enabled') !== true || $resource->auth_mode !== 'managed'
            || ! in_array($resource->engine, ['mssql', 'sqlserver'], true)
            || $resource->backendAuthMode() !== 'mssql_kerberos_service'
            || $resource->target_tls_mode !== 'verify-full') {
            throw new DomainException('Kerberos backend profile is unavailable.');
        }
        $alias = data_get($resource->target_options, 'kerberos_profile');
        $profiles = $this->profiles();
        if (! is_string($alias) || ! isset($profiles[$alias])) {
            throw new DomainException('Kerberos backend profile is unavailable.');
        }
        $profile = $profiles[$alias];
        $target = strtolower($resource->target_host).':'.$resource->target_port;
        if (($profile['target'] ?? null) !== $target || ($profile['spn'] ?? null) !== 'MSSQLSvc/'.$target) {
            throw new DomainException('Kerberos backend target binding is invalid.');
        }
        foreach (['username', 'realm', 'config_file', 'keytab_file'] as $field) {
            if (! isset($profile[$field]) || ! is_string($profile[$field]) || $profile[$field] === '') {
                throw new DomainException('Kerberos backend profile is invalid.');
            }
        }
        if (! preg_match('/^[A-Za-z0-9_.\-$]{1,128}$/D', $profile['username'])
            || ! preg_match('/^[A-Z0-9][A-Z0-9.\-]{1,127}$/D', $profile['realm'])) {
            throw new DomainException('Kerberos backend profile is invalid.');
        }
        return ['profile_id' => $alias] + $profile;
    }

    /** Secret material returns only to the process-bound FD 3 bootstrap. */
    public function materialFor(DatabaseResource $resource): array
    {
        $profile = $this->profileFor($resource);
        $material = [
            'kind' => 'kerberos_keytab', 'profile_id' => $profile['profile_id'],
            'username' => $profile['username'], 'realm' => $profile['realm'], 'spn' => $profile['spn'],
            'config_b64' => base64_encode($this->readFile($profile['config_file'], false, 4096)),
            'keytab_b64' => base64_encode($this->readFile($profile['keytab_file'], true, 4096)),
        ];
        if (strlen(json_encode($material, JSON_THROW_ON_ERROR)) > 12000) {
            throw new DomainException('Kerberos bootstrap material is too large.');
        }
        return $material;
    }

    private function profiles(): array
    {
        if (config('sag.db_gateway.kerberos.enabled') !== true) {
            return [];
        }
        $configured = config('sag.db_gateway.kerberos.profiles');
        $profiles = is_array($configured) ? $configured : json_decode($this->readFile(
            (string) config('sag.db_gateway.kerberos.profiles_file'), false, 16384,
        ), true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($profiles) || count($profiles) > 64) {
            throw new DomainException('Kerberos profile configuration is invalid.');
        }
        foreach ($profiles as $alias => $profile) {
            if (! is_string($alias) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $alias) || ! is_array($profile)) {
                throw new DomainException('Kerberos profile configuration is invalid.');
            }
        }
        return $profiles;
    }

    private function readFile(string $path, bool $secret, int $limit): string
    {
        clearstatcache(true, $path);
        $before = @lstat($path);
        $uid = posix_geteuid();
        if ($before === false || ($before['mode'] & 0170000) !== 0100000 || ($before['mode'] & 0022) !== 0
            || ! in_array($before['uid'], [0, $uid], true)
            || ($secret && (($before['mode'] & 0077) !== 0 || $before['uid'] !== $uid))
            || $before['size'] < 1 || $before['size'] > $limit) {
            throw new DomainException('Kerberos runtime file is unavailable.');
        }
        $file = @fopen($path, 'rb');
        if ($file === false) {
            throw new DomainException('Kerberos runtime file is unavailable.');
        }
        try {
            $opened = fstat($file);
            clearstatcache(true, $path);
            $current = @lstat($path);
            if ($opened === false || $current === false || $before['dev'] !== $opened['dev']
                || $before['ino'] !== $opened['ino'] || $opened['ino'] !== $current['ino']
                || $opened['dev'] !== $current['dev'] || $opened['mode'] !== $current['mode']
                || $before['mode'] !== $opened['mode'] || $before['uid'] !== $opened['uid']) {
                throw new DomainException('Kerberos runtime file changed.');
            }
            $bytes = stream_get_contents($file, $limit + 1);
            if ($bytes === false || $bytes === '' || strlen($bytes) > $limit) {
                throw new DomainException('Kerberos runtime file is unavailable.');
            }
            return $bytes;
        } finally {
            fclose($file);
        }
    }
}
