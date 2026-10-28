<?php

namespace App\Services\Credentials;

use App\Models\DbAccessSession;
use DomainException;

final class AuthorizedGatewayVault
{
    /**
     * Non-secret authorization for a specific IPC session/resource pair.
     * The caller must authenticate the IPC peer independently.
     */
    public function isActiveSessionResource(string $sessionUid, int $resourceId): bool
    {
        if ($sessionUid === '' || $resourceId < 1) {
            return false;
        }
        $session = DbAccessSession::query()->with('resource')
            ->where('public_id', $sessionUid)
            ->where('resource_id', $resourceId)
            ->first();
        return $session !== null
            && $session->mode === 'temporary'
            && $session->status === 'started'
            && $session->expires_at !== null
            && $session->expires_at->isFuture()
            && $session->resource?->is_active === true;
    }

    /**
     * Non-secret bootstrap eligibility, not a credential-granting API.
     * The trusted IPC server must independently authenticate the OS peer
     * and obtain its PID/start ticks from the kernel, never from JSON.
     * The session's stored identity is set by the controlled launcher.
     */
    public function isStartingSessionBoundToProcess(
        string $sessionUid,
        int $resourceId,
        int $peerPid,
        string $peerStartTicks,
    ): bool {
        if ($sessionUid === '' || $resourceId < 1 || $peerPid < 2
            || $peerStartTicks === '' || ! ctype_digit($peerStartTicks)) {
            return false;
        }

        $session = DbAccessSession::query()->with('resource')
            ->where('public_id', $sessionUid)
            ->where('resource_id', $resourceId)
            ->first();

        if ($session === null || $session->mode !== 'temporary'
            || $session->status !== 'starting'
            || $session->expires_at === null || ! $session->expires_at->isFuture()
            || $session->resource?->is_active !== true) {
            return false;
        }

        $pid = data_get($session->metadata, 'db.gateway_pid');
        $ticks = data_get($session->metadata, 'db.gateway_proc_start_ticks');

        return is_numeric($pid) && (int) $pid === $peerPid
            && is_string($ticks) && $ticks !== ''
            && hash_equals($ticks, $peerStartTicks);
    }

    /**
     * Bound credential lookup for a trusted local IPC worker.
     * The worker must independently authenticate its Unix peer.
     *
     * @return array{username:string,password:string}
     */
    public function forBoundActiveSession(
        string $sessionUid,
        int $resourceId,
        ResourceCredentialVault $vault,
    ): array {
        if (! $this->isActiveSessionResource($sessionUid, $resourceId)) {
            throw new DomainException('Gateway session is not authorized.');
        }

        // Revalidate the session at secret retrieval; never trust a previous
        // IPC allow response as an indefinite authorization grant.
        $session = DbAccessSession::query()->with('resource')
            ->where('public_id', $sessionUid)
            ->where('resource_id', $resourceId)
            ->first();

        if ($session === null
            || $session->mode !== 'temporary'
            || $session->status !== 'started'
            || $session->expires_at === null
            || $session->expires_at->isPast()
            || ! $session->resource?->is_active) {
            throw new DomainException('Gateway session is not authorized.');
        }

        $credentials = $vault->retrieveForGateway($resourceId);
        if ($credentials === null
            || $credentials['username'] === ''
            || $credentials['password'] === '') {
            throw new DomainException('Backend credentials are unavailable.');
        }

        return $credentials;
    }

    /**
     * Resolve backend credentials only after checking the active session and
     * the resource bound to that session. Never expose this method in routes.
     *
     * @return array{username:string,password:string}
     */
    public function forActiveSession(string $sessionUid, ResourceCredentialVault $vault): array
    {
        $session = DbAccessSession::query()
            ->with('resource')
            ->where('public_id', $sessionUid)
            ->first();

        if ($session === null
            || $session->mode !== 'temporary'
            || $session->status !== 'started'
            || $session->expires_at === null
            || $session->expires_at->isPast()
            || !$session->resource?->is_active) {
            throw new DomainException('Gateway session is not authorized.');
        }

        $credentials = $vault->retrieveForGateway((int) $session->resource_id);

        if ($credentials === null
            || $credentials['username'] === ''
            || $credentials['password'] === '') {
            throw new DomainException('Backend credentials are unavailable.');
        }

        return $credentials;
    }
}
