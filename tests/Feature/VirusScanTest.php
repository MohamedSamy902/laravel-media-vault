<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MohamedSamy902\LaravelMediaVault\Security\VirusScanner;
use MohamedSamy902\LaravelMediaVault\Services\MediaVaultService;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;
use RuntimeException;

class VirusScanTest extends TestCase
{
    private MediaVaultService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->service = $this->app->make(MediaVaultService::class);
    }

    /** @test */
    public function virus_scan_rejects_file_with_eicar_signature(): void
    {
        $this->app['config']->set('media-vault.security.virus_scan.enabled', true);

        $eicarContent = VirusScanner::EICAR_SIGNATURE;
        $tempPath = sys_get_temp_dir() . '/eicar_test.txt';
        file_put_contents($tempPath, $eicarContent);

        $file = new UploadedFile($tempPath, 'eicar_test.txt', 'text/plain', null, true);

        try {
            $this->service->upload($file, [
                'validation_rules' => ['file' => 'required|file'],
            ]);
            $this->fail('Expected virus scan to reject infected file.');
        } catch (RuntimeException $e) {
            $this->assertEquals('Uploaded file failed security scan and was rejected.', $e->getMessage());
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /** @test */
    public function virus_scan_closed_fail_mode_rejects_when_scanner_unavailable(): void
    {
        $this->app['config']->set('media-vault.security.virus_scan', [
            'enabled'   => true,
            'driver'    => 'clamav',
            'socket'    => 'tcp://127.0.0.1:99999', // Invalid port to simulate scanner down
            'fail_mode' => 'closed',
        ]);

        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Virus scan service is currently unavailable. Upload rejected.');

        $this->service->upload($file);
    }

    /** @test */
    public function virus_scan_open_fail_mode_allows_upload_when_scanner_unavailable(): void
    {
        $this->app['config']->set('media-vault.security.virus_scan', [
            'enabled'   => true,
            'driver'    => 'clamav',
            'socket'    => 'tcp://127.0.0.1:99999', // Invalid port to simulate scanner down
            'fail_mode' => 'open',
        ]);

        $file = UploadedFile::fake()->create('safe_document.pdf', 100, 'application/pdf');

        $result = $this->service->upload($file);

        $this->assertTrue($result->status);
        Storage::disk('public')->assertExists($result->path);
    }

    /** @test */
    public function virus_scan_skips_when_real_clamav_daemon_is_not_available(): void
    {
        $socket = config('media-vault.security.virus_scan.socket', 'tcp://127.0.0.1:3310');
        $errno = 0;
        $errstr = '';

        $fp = @stream_socket_client($socket, $errno, $errstr, 1);
        if (!$fp) {
            $this->markTestSkipped('ClamAV daemon not available in this environment');
        } else {
            fclose($fp);
            $this->assertTrue(true);
        }
    }
}
