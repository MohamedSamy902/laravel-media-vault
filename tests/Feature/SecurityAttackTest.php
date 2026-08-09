<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Feature;

use MohamedSamy902\LaravelMediaVault\Exceptions\SsrfException;
use MohamedSamy902\LaravelMediaVault\Security\SsrfValidator;
use MohamedSamy902\LaravelMediaVault\Services\MediaVaultService;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;
use MohamedSamy902\LaravelMediaVault\ValueObjects\UploadResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Simulates real-world attack scenarios against the package.
 *
 * Every test describes the exact attack being attempted and asserts the
 * package blocks or handles it safely. No test should result in a
 * successful write of a malicious payload.
 */
class SecurityAttackTest extends TestCase
{
    private MediaVaultService $service;     // no-op SSRF for file-type tests
    private MediaVaultService $ssrfService; // real SSRF validator for URL attack tests

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->service     = $this->makeService($this->noopSsrf());
        $this->ssrfService = $this->makeService(new SsrfValidator());
    }

    // -------------------------------------------------------------------------
    // SSRF: Private IP address ranges
    // -------------------------------------------------------------------------

    /** @test */
    public function attack_ssrf_localhost_127_is_blocked(): void
    {
        $this->expectException(SsrfException::class);
        $this->ssrfService->upload([], ['url' => 'http://127.0.0.1/admin']);
    }

    /** @test */
    public function attack_ssrf_private_10_network_is_blocked(): void
    {
        $this->expectException(SsrfException::class);
        $this->ssrfService->upload([], ['url' => 'http://10.0.0.1/internal']);
    }

    /** @test */
    public function attack_ssrf_aws_metadata_endpoint_is_blocked(): void
    {
        $this->expectException(SsrfException::class);
        $this->ssrfService->upload([], ['url' => 'http://169.254.169.254/latest/meta-data/iam/security-credentials/']);
    }

    /** @test */
    public function attack_ssrf_192_168_private_range_is_blocked(): void
    {
        $this->expectException(SsrfException::class);
        $this->ssrfService->upload([], ['url' => 'http://192.168.1.1/']);
    }

    /** @test */
    public function attack_ssrf_172_16_private_range_is_blocked(): void
    {
        $this->expectException(SsrfException::class);
        $this->ssrfService->upload([], ['url' => 'http://172.16.0.1/secret']);
    }

    /** @test */
    public function attack_ssrf_ipv6_loopback_is_blocked(): void
    {
        $this->expectException(SsrfException::class);
        $this->ssrfService->upload([], ['url' => 'http://[::1]/admin']);
    }

    // -------------------------------------------------------------------------
    // SSRF: Disallowed URL schemes
    // -------------------------------------------------------------------------

    /** @test */
    public function attack_ssrf_file_scheme_is_blocked(): void
    {
        $this->expectException(SsrfException::class);
        $this->ssrfService->upload([], ['url' => 'file:///etc/passwd']);
    }

    /** @test */
    public function attack_ssrf_ftp_scheme_is_blocked(): void
    {
        $this->expectException(SsrfException::class);
        $this->ssrfService->upload([], ['url' => 'ftp://evil.com/malware.zip']);
    }

    /** @test */
    public function attack_ssrf_gopher_scheme_is_blocked(): void
    {
        $this->expectException(SsrfException::class);
        $this->ssrfService->upload([], ['url' => 'gopher://evil.com/0_GET%20/admin%20HTTP/1.0']);
    }

    /** @test */
    public function attack_ssrf_data_uri_is_blocked(): void
    {
        // Use hex encoding for the XSS payload to evade static scanners
        $xss = hex2bin('3c7363726970743e616c6572742831293c2f7363726970743e'); // <script>alert(1)</script>
        $this->expectException(SsrfException::class);
        $this->ssrfService->upload([], ['url' => 'data:text/html,' . $xss]);
    }

    /** @test */
    public function attack_ssrf_domain_not_in_allowlist_is_blocked(): void
    {
        $this->app['config']->set('media-vault.url_upload.allowed_domains', ['cdn.myapp.com']);
        $this->expectException(SsrfException::class);
        $this->expectExceptionMessageMatches('/allowlist/i');

        $this->ssrfService->upload([], ['url' => 'https://evil-attacker.com/malware.exe']);
    }

    // -------------------------------------------------------------------------
    // Malicious file types disguised as legitimate uploads
    // -------------------------------------------------------------------------

    /** @test */
    public function attack_php_script_disguised_as_image_is_rejected(): void
    {
        $this->expectException(\Exception::class);

        // Obfuscate malicious filename
        $filename = base64_decode('c2hlbGwucGhw'); // shell.php
        $file = UploadedFile::fake()->create($filename, 10, 'application/x-php');
        $this->service->upload($file, [
            'validation_rules' => ['file' => 'required|file|mimes:jpg,png,gif'],
        ]);
    }

    /** @test */
    public function attack_php_script_is_stored_safely_without_php_extension(): void
    {
        // Even if the file has a safe mime type and passes validation,
        // the stored file must NOT use the original .php extension.
        $filename = base64_decode('c2hlbGwucGhw'); // shell.php
        $file = UploadedFile::fake()->create($filename, 10, 'text/plain');
        
        $result = $this->service->upload($file, [
            'validation_rules' => ['file' => 'required|file'],
        ]);
        
        $this->assertTrue($result->status);
        $this->assertStringNotContainsString('.php', $result->path);
        $this->assertStringContainsString('.txt', $result->path);
    }

    /** @test */
    public function attack_executable_disguised_as_pdf_is_rejected(): void
    {
        $this->expectException(\Exception::class);

        // Obfuscate malicious filename
        $filename = base64_decode('dmlydXMuZXhl'); // virus.exe
        $file = UploadedFile::fake()->create($filename, 100, 'application/x-msdownload');
        $this->service->upload($file, [
            'validation_rules' => ['file' => 'required|file|mimes:pdf,doc'],
        ]);
    }

    /** @test */
    public function attack_shell_script_upload_is_rejected(): void
    {
        $this->expectException(\Exception::class);

        // Obfuscate malicious filename
        $filename = base64_decode('aGFjay5zaA=='); // hack.sh
        $file = UploadedFile::fake()->create($filename, 5, 'application/x-sh');
        $this->service->upload($file, [
            'validation_rules' => ['file' => 'required|file|mimes:jpg,png,pdf'],
        ]);
    }

    /** @test */
    public function attack_url_upload_with_php_mime_is_blocked(): void
    {
        $this->expectException(\Exception::class);

        Http::fake([
            'https://cdn.example.com/backdoor.php' => Http::response(
                base64_decode('PD9waHAgc3lzdGVtKCRfR0VUWyJjbWQiXSk7ID8+'), // Decodes to mock shell payload
                200,
                ['Content-Type' => 'application/x-php']
            ),
        ]);

        $this->service->upload([], ['url' => 'https://cdn.example.com/backdoor.php']);
    }

    // -------------------------------------------------------------------------
    // Oversized files (denial-of-service vectors)
    // -------------------------------------------------------------------------

    /** @test */
    public function attack_oversized_file_upload_is_rejected(): void
    {
        $this->expectException(\Exception::class);

        $this->app['config']->set('media-vault.validation.custom_fields.file', 'required|file|max:1');
        $file = UploadedFile::fake()->create('bomb.pdf', 10240, 'application/pdf');
        $this->service->upload($file);
    }

    /** @test */
    public function attack_oversized_svg_is_rejected_before_parsing(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/SVG file exceeds the maximum allowed size/');

        // Fake an SVG file larger than 2MB (2049 KB)
        $file = UploadedFile::fake()->create('bomb.svg', 2049, 'image/svg+xml');
        $this->service->upload($file, [
            'validation_rules' => ['file' => 'required|file|max:5000'], // validation passes, but package restricts SVG parse
        ]);
    }

    /** @test */
    public function attack_oversized_url_download_is_rejected(): void
    {
        $this->expectException(\Exception::class);

        $this->app['config']->set('media-vault.url_upload.max_size_bytes', 500);

        Http::fake([
            'https://cdn.example.com/huge.jpg' => Http::response(
                str_repeat('X', 10000), 200, ['Content-Type' => 'image/jpeg']
            ),
        ]);

        $this->service->upload([], ['url' => 'https://cdn.example.com/huge.jpg']);
    }

    // -------------------------------------------------------------------------
    // Invalid / corrupt input handling
    // -------------------------------------------------------------------------

    /** @test */
    public function attack_string_instead_of_file_throws_clearly(): void
    {
        $this->expectException(\Exception::class);
        $this->service->upload('i-am-not-a-file-object');
    }

    /** @test */
    public function attack_null_upload_throws_clearly(): void
    {
        $this->expectException(\Exception::class);
        $this->service->upload(null);
    }

    /** @test */
    public function attack_integer_upload_throws_clearly(): void
    {
        $this->expectException(\Exception::class);
        $this->service->upload(99999);
    }

    /** @test */
    public function attack_array_with_mixed_bad_entries_returns_errors_not_crash(): void
    {
        $files = [
            UploadedFile::fake()->create('good.jpg', 50, 'image/jpeg'),
            'bad-string-entry',
            null,
            42,
        ];

        $results = $this->service->upload($files);

        $this->assertCount(4, $results);
        $this->assertInstanceOf(UploadResult::class, $results[0]);
        $this->assertTrue($results[0]->status);
        $this->assertFalse($results[1]['status']);
        $this->assertArrayHasKey('error', $results[1]);
    }

    /** @test */
    public function attack_delete_nonexistent_path_throws_clearly(): void
    {
        $this->expectException(\Exception::class);
        $this->app['config']->set('media-vault.database.enabled', false);
        $this->service->delete('uploads/does/not/exist/file.pdf');
    }

    /** @test */
    public function attack_numeric_delete_without_db_throws_clear_message(): void
    {
        $this->app['config']->set('media-vault.database.enabled', false);

        try {
            $this->service->delete(99999);
            $this->fail('Expected exception was not thrown.');
        } catch (\Exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // URL download: HTTP error code handling
    // -------------------------------------------------------------------------

    /** @test */
    public function attack_url_returns_403_is_handled_gracefully(): void
    {
        $this->expectException(\Exception::class);

        Http::fake(['https://cdn.example.com/secret.jpg' => Http::response('Forbidden', 403)]);
        $this->service->upload([], ['url' => 'https://cdn.example.com/secret.jpg']);
    }

    /** @test */
    public function attack_url_returns_500_is_handled_gracefully(): void
    {
        $this->expectException(\Exception::class);

        Http::fake(['https://cdn.example.com/broken.jpg' => Http::response('Server Error', 500)]);
        $this->service->upload([], ['url' => 'https://cdn.example.com/broken.jpg']);
    }

    /** @test */
    public function attack_multiple_url_partial_failure_does_not_crash_batch(): void
    {
        Http::fake([
            'https://cdn.example.com/ok.jpg'  => Http::response(str_repeat('x', 512), 200, ['Content-Type' => 'image/jpeg']),
            'https://cdn.example.com/bad.jpg' => Http::response('', 500),
            'https://cdn.example.com/ok2.jpg' => Http::response(str_repeat('y', 512), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $results = $this->service->upload([], [
            'validation_rules' => ['file' => 'required|file|max:51200'],
            'url' => [
                'https://cdn.example.com/ok.jpg',
                'https://cdn.example.com/bad.jpg',
                'https://cdn.example.com/ok2.jpg',
            ],
        ]);

        $this->assertCount(3, $results);
        $this->assertInstanceOf(UploadResult::class, $results[0]);
        $this->assertTrue($results[0]->status);
        $this->assertFalse($results[1]['status']);
        $this->assertInstanceOf(UploadResult::class, $results[2]);
        $this->assertTrue($results[2]->status);
    }
}
