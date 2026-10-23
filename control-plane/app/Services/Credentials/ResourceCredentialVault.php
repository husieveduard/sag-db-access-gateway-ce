<?php

namespace App\Services\Credentials;

use App\Models\DatabaseResourceCredential;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ResourceCredentialVault
{
    private function encrypter(): Encrypter
    {
        $encoded = config('sag_credentials.encryption_key');
        if (!is_string($encoded) || !str_starts_with($encoded, 'base64:')) {
            throw new RuntimeException('Credential vault encryption key is not configured.');
        }

        $key = base64_decode(substr($encoded, 7), true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Credential vault encryption key is invalid.');
        }

        return new Encrypter($key, 'aes-256-gcm');
    }

    public function encryptPassword(string $password): string
    {
        if ($password === '') {
            throw new RuntimeException('Empty database credential password is not allowed.');
        }

        return $this->encrypter()->encryptString($password);
    }

    public function decryptPassword(string $ciphertext): string
    {
        return $this->encrypter()->decryptString($ciphertext);
    }

    /**
     * Internal-only storage operation. Caller must authorize resource management.
     * Never pass a password into audit events, logs, views or API responses.
     */
    public function replace(int $resourceId, string $username, string $password, int $actorUserId): void
    {
        $username = trim($username);
        if ($resourceId < 1 || $actorUserId < 1 || $username === '' || strlen($username) > 128) {
            throw new RuntimeException('Invalid credential metadata.');
        }

        $ciphertext = $this->encryptPassword($password);

        DB::transaction(function () use ($resourceId, $username, $ciphertext, $actorUserId): void {
            $row = DatabaseResourceCredential::query()
                ->where('resource_id', $resourceId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                $row = new DatabaseResourceCredential();
                $row->resource_id = $resourceId;
                $row->created_by_user_id = $actorUserId;
            }

            $row->username = $username;
            $row->password_encrypted = $ciphertext;
            $row->encryption_version = 1;
            $row->updated_by_user_id = $actorUserId;
            $row->save();
        });
    }

    /**
     * Only the future trusted gateway broker may invoke this method.
     * Do not expose its return value through HTTP or serialized models.
     *
     * @return array{username:string,password:string}|null
     */
    public function retrieveForGateway(int $resourceId): ?array
    {
        $row = DatabaseResourceCredential::query()->where('resource_id', $resourceId)->first();
        if ($row === null) {
            return null;
        }
        if ((int) $row->encryption_version !== 1) {
            throw new RuntimeException('Unsupported credential encryption version.');
        }

        return [
            'username' => $row->username,
            'password' => $this->decryptPassword($row->password_encrypted),
        ];
    }
}
