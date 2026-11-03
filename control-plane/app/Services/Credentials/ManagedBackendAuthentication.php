<?php

namespace App\Services\Credentials;

use App\Models\DatabaseResource;
use App\Models\DbAccessSession;

/** Public authentication/target binding; never retrieves backend secrets. */
final class ManagedBackendAuthentication
{
    public function supportsResource(DatabaseResource $resource): bool
    {
        return $resource->usesPasswordBackendAuthentication()
            || app(ManagedKerberosProfiles::class)->supportsResource($resource);
    }

    public function supportsSession(DbAccessSession $session): bool
    {
        $resource = $session->resource;
        if ($resource === null) {
            return false;
        }
        $binding = $this->bindingFor($resource);
        $target = data_get($session->metadata, 'db.backend_target');
        if ($target !== null && $target !== $binding['backend_target']) {
            return false;
        }
        if ($resource->usesPasswordBackendAuthentication()) {
            $pinned = data_get($session->metadata, 'db.backend_auth_mode');
            return $pinned === null || $pinned === $resource->backendAuthMode();
        }
        return app(ManagedKerberosProfiles::class)->supportsSession($session);
    }

    public function bindingFor(DatabaseResource $resource): array
    {
        return [
            'backend_auth_mode' => $resource->backendAuthMode(),
            'kerberos_profile' => $resource->backendAuthMode() === 'mssql_kerberos_service'
                ? data_get($resource->target_options, 'kerberos_profile') : null,
            'backend_target' => [$resource->engine, $resource->auth_mode, $resource->target_host,
                (int) $resource->target_port, $resource->target_database, $resource->target_tls_mode],
        ];
    }
}
