<?php

namespace MohamedSamy902\LaravelMediaVault\Tests\Unit;

use MohamedSamy902\LaravelMediaVault\Exceptions\SsrfException;
use MohamedSamy902\LaravelMediaVault\Security\SsrfValidator;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;

class SsrfValidatorTest extends TestCase
{
    private SsrfValidator $validator;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new SsrfValidator();
    }

    // =========================================================================
    // Scheme validation
    // =========================================================================

    public function test_allows_https_scheme(): void
    {
        // Should NOT throw — cdn.example.com is a public external host.
        // We mock DNS by using an IP that is public (fake but structurally valid).
        // The validator resolves via gethostbyname; in unit tests we bypass DNS
        // by passing an already-valid public IP directly as host.
        $this->expectNotToPerformAssertions();

        try {
            // Use a raw public IP URL to avoid DNS resolution in unit tests
            $this->validator->validate('https://93.184.216.34/file.jpg'); // example.com IP
        } catch (SsrfException) {
            // Public IP, should not throw — re-throw to fail test if it does
            $this->fail('Public IP should not be blocked by SSRF validator.');
        }
    }

    public function test_blocks_ftp_scheme(): void
    {
        $this->expectException(SsrfException::class);
        $this->validator->validate('ftp://example.com/file.zip');
    }

    public function test_blocks_file_scheme(): void
    {
        $this->expectException(SsrfException::class);
        $this->validator->validate('file:///etc/passwd');
    }

    public function test_blocks_gopher_scheme(): void
    {
        $this->expectException(SsrfException::class);
        $this->validator->validate('gopher://example.com/');
    }

    public function test_blocks_data_scheme(): void
    {
        $this->expectException(SsrfException::class);
        $this->validator->validate('data:text/html,<h1>hi</h1>');
    }

    // =========================================================================
    // IPv4 private ranges
    // =========================================================================

    public function test_blocks_loopback_127_0_0_1(): void
    {
        $this->expectException(SsrfException::class);
        $this->validator->validate('http://127.0.0.1/secret');
    }

    public function test_blocks_loopback_127_x_x_x(): void
    {
        $this->expectException(SsrfException::class);
        $this->validator->validate('http://127.255.255.255/secret');
    }

    public function test_blocks_private_10_range(): void
    {
        $this->expectException(SsrfException::class);
        $this->validator->validate('http://10.0.0.1/internal');
    }

    public function test_blocks_private_10_range_any_subnet(): void
    {
        $this->expectException(SsrfException::class);
        $this->validator->validate('http://10.200.100.50/api');
    }

    public function test_blocks_private_172_16_to_172_31(): void
    {
        $this->expectException(SsrfException::class);
        $this->validator->validate('http://172.16.0.1/');
    }

    public function test_blocks_private_172_31_subnet(): void
    {
        $this->expectException(SsrfException::class);
        $this->validator->validate('http://172.31.255.255/');
    }

    public function test_allows_172_32_as_public(): void
    {
        // 172.32.x.x is NOT in the private 172.16.0.0/12 range
        $this->expectNotToPerformAssertions();
        try {
            $this->validator->validate('http://172.32.0.1/file.jpg');
        } catch (SsrfException $e) {
            $this->fail('172.32.0.1 is public and should not be blocked: ' . $e->getMessage());
        }
    }

    public function test_blocks_private_192_168(): void
    {
        $this->expectException(SsrfException::class);
        $this->validator->validate('http://192.168.1.1/router');
    }

    public function test_blocks_link_local_169_254(): void
    {
        $this->expectException(SsrfException::class);
        $this->validator->validate('http://169.254.169.254/metadata'); // AWS metadata endpoint
    }

    // =========================================================================
    // IPv6 private ranges
    // =========================================================================

    public function test_blocks_ipv6_loopback(): void
    {
        $this->expectException(SsrfException::class);
        $this->validator->validate('http://[::1]/secret');
    }

    public function test_blocks_ipv6_ula_fc00(): void
    {
        $this->expectException(SsrfException::class);
        $this->validator->validate('http://[fc00::1]/internal');
    }

    public function test_blocks_ipv6_link_local(): void
    {
        $this->expectException(SsrfException::class);
        $this->validator->validate('http://[fe80::1]/');
    }

    // =========================================================================
    // Domain allowlist
    // =========================================================================

    public function test_blocks_domain_not_in_allowlist(): void
    {
        $this->app['config']->set('media-vault.url_upload.allowed_domains', ['cdn.myapp.com']);

        $this->expectException(SsrfException::class);
        $this->validator->validate('https://evil.com/file.jpg');
    }

    public function test_allows_domain_in_allowlist(): void
    {
        $this->app['config']->set('media-vault.url_upload.allowed_domains', ['cdn.myapp.com']);

        // cdn.myapp.com passes allowlist, then proceeds to DNS check
        // In a unit test we can't resolve real DNS, so we assert the allowlist
        // doesn't block it — DNS failure would throw a different message.
        try {
            $this->validator->validate('https://cdn.myapp.com/photo.jpg');
        } catch (SsrfException $e) {
            // Should fail at DNS, not at allowlist
            $this->assertStringContainsString('resolve', $e->getMessage());
        }
    }

    public function test_empty_allowlist_allows_all_public_domains(): void
    {
        $this->app['config']->set('media-vault.url_upload.allowed_domains', []);

        // With an empty allowlist, any public domain should pass the allowlist check.
        // In a test environment DNS may not resolve, so we accept either:
        //   (a) success — domain resolved and is public
        //   (b) SsrfException mentioning 'resolve' or 'Blocked' — but NOT 'allowlist'
        $caughtMessage = null;
        try {
            $this->validator->validate('https://example.com/file.jpg');
        } catch (SsrfException $e) {
            $caughtMessage = $e->getMessage();
        }

        // The allowlist must not have been the reason for any exception
        if ($caughtMessage !== null) {
            $this->assertStringNotContainsString('allowlist', strtolower($caughtMessage));
        } else {
            // Resolved fine — just assert we reached this point
            $this->assertTrue(true);
        }
    }

    // =========================================================================
    // Invalid URL
    // =========================================================================

    public function test_blocks_invalid_url(): void
    {
        $this->expectException(SsrfException::class);
        $this->validator->validate('not-a-url-at-all');
    }
}
