<?php

namespace App\Services\Credentials;

use App\Models\DbAccessSession;
use App\Models\GatewayTemporaryCredential;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class TemporaryGatewayCredentials
{
    /**
     * Issue once for a temporary session. The plaintext password is returned
     * to the trusted caller exactly once and must never be logged or persisted.
     *
     * @return array{username: string, password: string}
     */
    public function issue(DbAccessSession $session): array
    {
        return DB::transaction(function () use ($session): array {
            $locked = DbAccessSession::query()->whereKey($session->id)
                ->lockForUpdate()->firstOrFail();

            if ($locked->mode !== 'temporary' || $locked->status !== 'created'
                || $locked->expires_at === null || $locked->expires_at->isPast()) {
                throw new DomainException('Session is not eligible for temporary credentials.');
            }

            if (GatewayTemporaryCredential::query()->where('session_id', $locked->id)->exists()) {
                throw new DomainException('Temporary credentials already issued.');
            }

            $username = 'sag_'.bin2hex(random_bytes(12));
            $password = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

            $row = new GatewayTemporaryCredential();
            $row->session_id = $locked->id;
            $row->username = $username;
            $row->password_hash = Hash::make($password);
            $row->save();

            return ['username' => $username, 'password' => $password];
        });
    }

    public function verify(DbAccessSession $session, string $username, string $password): bool
    {
        if ($session->mode !== 'temporary'
            || $session->status !== 'started'
            || $session->expires_at === null
            || $session->expires_at->isPast()) {
            return false;
        }

        $row = GatewayTemporaryCredential::query()
            ->where('session_id', $session->id)
            ->where('username', $username)->first();

        return $row !== null && Hash::check($password, $row->password_hash);
    }
}
