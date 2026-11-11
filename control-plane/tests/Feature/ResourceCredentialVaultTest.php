<?php

namespace Tests\Feature;

use App\Services\Credentials\ResourceCredentialVault;
use Illuminate\Contracts\Encryption\DecryptException;
use RuntimeException;
use Tests\TestCase;

class ResourceCredentialVaultTest extends TestCase
{
    public function test_password_is_encrypted_with_dedicated_key_and_round_trips(): void
    {
        config()->set('sag_credentials.encryption_key', 'base64:'.base64_encode(random_bytes(32)));
        $vault = new ResourceCredentialVault();
        $secret = 'db-secret-'.bin2hex(random_bytes(12));

        $encrypted = $vault->encryptPassword($secret);

        $this->assertNotSame($secret, $encrypted);
        $this->assertStringNotContainsString($secret, $encrypted);
        $this->assertSame($secret, $vault->decryptPassword($encrypted));
        $this->assertNotSame($encrypted, $vault->encryptPassword($secret));
    }

    public function test_missing_or_invalid_key_fails_closed(): void
    {
        $vault = new ResourceCredentialVault();

        config()->set('sag_credentials.encryption_key', null);
        $this->expectException(RuntimeException::class);
        $vault->encryptPassword('nonempty');
    }

    public function test_wrong_key_cannot_decrypt(): void
    {
        $vault = new ResourceCredentialVault();
        config()->set('sag_credentials.encryption_key', 'base64:'.base64_encode(random_bytes(32)));
        $encrypted = $vault->encryptPassword('temporary-password');

        config()->set('sag_credentials.encryption_key', 'base64:'.base64_encode(random_bytes(32)));
        $this->expectException(DecryptException::class);
        $vault->decryptPassword($encrypted);
    }

    public function test_empty_password_is_rejected(): void
    {
        config()->set('sag_credentials.encryption_key', 'base64:'.base64_encode(random_bytes(32)));
        $this->expectException(RuntimeException::class);
        (new ResourceCredentialVault())->encryptPassword('');
    }
}
