<?php

namespace Tests\Feature;

use App\Services\Auth\DirectoryTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DirectoryTransportTest extends TestCase
{
    public static function secureModes(): array
    {
        return [
            'ldaps' => ['ldaps', 'ldaps://dc.example.internal:636', 636, false],
            'starttls' => ['starttls', 'ldap://dc.example.internal:389', 389, true],
        ];
    }

    #[DataProvider('secureModes')]
    public function test_secure_protocols_select_matching_scheme(
        string $mode, string $url, int $port, bool $starttls
    ): void {
        $selection = DirectoryTransport::resolve(['protocol' => $mode, 'url' => $url]);
        $this->assertSame($mode, $selection['mode']);
        $this->assertSame($port, $selection['port']);
        $this->assertTrue($selection['encrypted']);
        $this->assertSame($starttls, $selection['starttls']);
    }

    public function test_plain_ldap_is_rejected_in_production_even_with_opt_in(): void
    {
        $this->expectException(RuntimeException::class);
        DirectoryTransport::resolve([
            'protocol' => 'ldap', 'url' => 'ldap://dc.example.internal:389',
            'allow_insecure' => true,
        ], 'production');
    }

    public function test_plain_ldap_requires_explicit_lab_opt_in(): void
    {
        $this->expectException(RuntimeException::class);
        DirectoryTransport::resolve([
            'protocol' => 'ldap', 'url' => 'ldap://dc.example.internal:389',
        ], 'testing');
    }

    public function test_plain_ldap_opt_in_is_available_only_in_lab(): void
    {
        $transport = DirectoryTransport::resolve([
            'protocol' => 'ldap', 'url' => 'ldap://dc.example.internal:389',
            'allow_insecure' => true,
        ], 'testing');
        $this->assertFalse($transport['encrypted']);
    }

    public function test_protocol_scheme_mismatch_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        DirectoryTransport::resolve([
            'protocol' => 'starttls', 'url' => 'ldaps://dc.example.internal:636',
        ]);
    }

    public function test_credentials_embedded_in_directory_url_are_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        DirectoryTransport::resolve([
            'protocol' => 'ldaps', 'url' => 'ldaps://admin:secret@dc.example.internal:636',
        ]);
    }
}
