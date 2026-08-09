<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Security;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Scans uploaded files for viruses and malware using ClamAV.
 *
 * Supports ClamAV daemon (TCP socket `tcp://127.0.0.1:3310` or Unix socket `unix:///var/run/clamav/clamd.ctl`)
 * as well as `clamscan` CLI process execution.
 */
class VirusScanner
{
    /**
     * Standard EICAR signature for testing antivirus scanners safely.
     */
    public const EICAR_SIGNATURE = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    /**
     * Scans the file at the given path.
     *
     * @param string $filePath Absolute path to the file to scan
     * @param array<string, mixed>|null $config Optional custom configuration array
     * @throws RuntimeException When a virus is detected or when the scanner is unreachable under `fail_mode = closed`
     */
    public function scan(string $filePath, ?array $config = null): void
    {
        $config = $config ?? config('media-vault.security.virus_scan', []);

        if (!($config['enabled'] ?? false)) {
            return;
        }

        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new RuntimeException("Cannot scan file: File does not exist or is unreadable at [{$filePath}].");
        }

        // Fast check for EICAR test string signature in test/dev environments
        if ($this->containsEicarSignature($filePath)) {
            Log::warning("Infected file detected during scan: [{$filePath}]. Virus: EICAR-Test-File");
            throw new RuntimeException('Uploaded file failed security scan and was rejected.');
        }

        $failMode = strtolower((string) ($config['fail_mode'] ?? 'closed'));
        $socket   = (string) ($config['socket'] ?? 'tcp://127.0.0.1:3310');
        $cliPath  = (string) ($config['path'] ?? '/usr/bin/clamscan');

        try {
            $isInfected = $this->scanViaSocket($filePath, $socket);
            if ($isInfected === null) {
                // Socket connection failed, try CLI fallback if binary exists
                if (file_exists($cliPath) && is_executable($cliPath)) {
                    $isInfected = $this->scanViaCli($filePath, $cliPath);
                } else {
                    throw new RuntimeException("ClamAV scanner service is unavailable at socket [{$socket}] or binary [{$cliPath}].");
                }
            }

            if ($isInfected === true) {
                Log::warning("Infected file detected during scan: [{$filePath}].");
                throw new RuntimeException('Uploaded file failed security scan and was rejected.');
            }
        } catch (RuntimeException $e) {
            // Re-throw if it's our virus detection exception
            if ($e->getMessage() === 'Uploaded file failed security scan and was rejected.') {
                throw $e;
            }

            if ($failMode === 'closed') {
                Log::error("Virus scan failed in CLOSED mode: " . $e->getMessage());
                throw new RuntimeException('Virus scan service is currently unavailable. Upload rejected.');
            }

            Log::warning("Virus scan bypassed in OPEN mode: " . $e->getMessage());
        }
    }

    /**
     * Checks if the file contains the EICAR antivirus test signature string.
     */
    private function containsEicarSignature(string $filePath): bool
    {
        // Avoid reading huge files into memory for EICAR check
        if (filesize($filePath) > 10 * 1024 * 1024) {
            return false;
        }

        $content = file_get_contents($filePath);
        return $content !== false && str_contains($content, self::EICAR_SIGNATURE);
    }

    /**
     * Scans a file via ClamAV clamd socket (TCP or Unix socket).
     *
     * @return bool|null true if infected, false if clean, null if socket connection failed
     */
    private function scanViaSocket(string $filePath, string $socketUrl): ?bool
    {
        $errno = 0;
        $errstr = '';

        $fp = @stream_socket_client($socketUrl, $errno, $errstr, 3);
        if (!$fp) {
            return null;
        }

        try {
            // Use SCAN command for local paths or INSTREAM for streamed chunks
            $realPath = (string) realpath($filePath);
            fwrite($fp, "SCAN {$realPath}\n");
            $response = fgets($fp, 4096);
            fclose($fp);

            if ($response === false) {
                return null;
            }

            if (str_contains($response, 'FOUND')) {
                return true;
            }

            if (str_contains($response, 'OK')) {
                return false;
            }

            return null;
        } catch (\Throwable $e) {
            if (is_resource($fp)) {
                fclose($fp);
            }
            return null;
        }
    }

    /**
     * Scans a file via clamscan CLI binary.
     *
     * @return bool true if infected, false if clean
     */
    private function scanViaCli(string $filePath, string $cliPath): bool
    {
        $cmd = escapeshellcmd($cliPath) . ' --no-summary ' . escapeshellarg($filePath);
        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        // Exit code 1 means infected file found
        return $returnCode === 1;
    }
}
