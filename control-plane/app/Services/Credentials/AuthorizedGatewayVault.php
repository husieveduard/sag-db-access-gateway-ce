<?php

namespace App\Services\Credentials;

use App\Models\DbAccessSession;
use DomainException;

final class AuthorizedGatewayVault
{
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
