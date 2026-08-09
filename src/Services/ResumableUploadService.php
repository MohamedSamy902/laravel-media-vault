<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MohamedSamy902\LaravelMediaVault\Models\UploadSession;
use MohamedSamy902\LaravelMediaVault\ValueObjects\UploadResult;
use RuntimeException;

/**
 * Manages resumable chunked file uploads.
 */
final class ResumableUploadService
{
    public function __construct(
        private readonly StorageManager $storageManager,
        private readonly FileValidator  $fileValidator,
    ) {}

    /**
     * Initiates a new upload session and returns its UUID.
     *
     * @param string   $originalName  The client-provided filename
     * @param string   $mimeType      The MIME type declared by the client
     * @param int      $totalSize     Expected total file size in bytes
     * @param int      $totalChunks   Total number of chunks that will be sent
     * @param string   $disk          Target storage disk
     * @param string   $folder        Target storage folder
     * @param array<string, mixed> $options Optional upload options
     * @return UploadSession
     */
    public function startSession(
        string $originalName,
        string $mimeType,
        int    $totalSize,
        int    $totalChunks,
        string $disk    = 'public',
        string $folder  = 'uploads',
        array  $options = [],
    ): UploadSession {
        $this->ensureDatabaseEnabled();
        $this->preValidateSession($originalName, $mimeType, $totalSize, $options);

        $ttlHours = (int) config('media-vault.chunked.session_ttl_hours', 24);

        $session = UploadSession::create([
            'session_id'      => Str::uuid()->toString(),
            'user_id'         => Auth::id(),
            'original_name'   => $originalName,
            'disk'            => $disk,
            'folder'          => $folder,
            'mime_type'       => $mimeType,
            'total_size'      => $totalSize,
            'total_chunks'    => $totalChunks,
            'received_chunks' => [],
            'status'          => 'pending',
            'expires_at'      => now()->addHours($ttlHours),
        ]);

        Log::info("Resumable upload session started [{$session->session_id}] — {$totalChunks} chunks expected.");

        return $session;
    }

    /**
     * Stores a single chunk for an existing upload session.
     *
     * The chunk is written to a temporary directory keyed by session UUID.
     * Already-received chunks are skipped (idempotent — safe to re-send).
     *
     * @param string       $sessionId  The session UUID from startSession()
     * @param int          $chunkIndex Zero-based index of this chunk
     * @param UploadedFile $chunk      The binary chunk data
     *
     * @return array{received: int, total: int, missing: list<int>}
     * @throws RuntimeException When the session is not found, expired, or complete
     */
    public function uploadChunk(string $sessionId, int $chunkIndex, UploadedFile $chunk): array
    {
        $lock = \Illuminate\Support\Facades\Cache::lock("upload_session_{$sessionId}", 10);
        
        try {
            $lock->block(5);
            
            $session = $this->findActiveSession($sessionId, false);

            if ($chunkIndex < 0 || $chunkIndex >= $session->total_chunks) {
                throw new RuntimeException(
                    "Chunk index [{$chunkIndex}] is out of range for session [{$sessionId}] "
                    . "which expects {$session->total_chunks} chunks."
                );
            }

            $chunks = $session->received_chunks ?? [];

            if (!empty($chunks[$chunkIndex])) {
                // Chunk already stored — return current state without re-writing
                Log::info("Chunk [{$chunkIndex}] for session [{$sessionId}] already received, skipping.");
            } else {
                $this->writeChunkToDisk($sessionId, $chunkIndex, $chunk);
                $session->markChunkReceived($chunkIndex);
            }

            return [
                'received' => count(array_filter($session->received_chunks, fn ($v) => $v === true)),
                'total'    => $session->total_chunks,
                'missing'  => $session->missingChunks(),
            ];
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
             throw new RuntimeException("Could not acquire lock for chunk assembly. Please retry.");
        } finally {
            $lock->release();
        }
    }

    /**
     * Assembles all received chunks into a final file and stores it.
     *
     * After a successful assembly, all temporary chunk files are removed.
     * The session status is updated to "complete".
     *
     * @param string $sessionId The session UUID from startSession()
     * @param array<string, mixed>  $options   Per-request overrides forwarded to StorageManager
     *
     * @return UploadResult
     * @throws RuntimeException When chunks are missing or assembly fails
     */
    public function completeSession(string $sessionId, array $options = []): UploadResult
    {
        $session = $this->findActiveSession($sessionId);

        if (!$session->isComplete()) {
            $missing = $session->missingChunks();
            throw new RuntimeException(
                "Cannot complete session [{$sessionId}]: "
                . count($missing) . " chunk(s) are still missing: "
                . implode(', ', $missing)
            );
        }

        $session->status = 'assembling';
        $session->save();

        $assembledPath = null;

        try {
            $assembledPath = $this->assembleChunks($session);
            $uploadedFile  = new UploadedFile(
                $assembledPath,
                $session->original_name,
                $session->mime_type,
                null,
                true,
            );

            $detectedMime = $uploadedFile->getMimeType() ?: $session->mime_type;
            if ($detectedMime !== 'application/octet-stream') {
                $session->mime_type = $detectedMime;
                $session->save();
            }

            /** @var array<string, mixed> $customRules */
            $customRules = is_array($options['validation_rules'] ?? null) ? $options['validation_rules'] : [];

            $this->fileValidator->validate(
                $uploadedFile,
                $session->mime_type,
                empty($customRules) ? '' : 'file',
                $customRules,
            );

            $result = $this->storageManager->store(
                $uploadedFile,
                trim($session->folder, '/'),
                $session->disk,
                $options,
            );

            $session->status        = 'complete';
            $session->assembled_path = $result->path;
            $session->save();

            Log::info("Session [{$sessionId}] completed — file stored at [{$result->path}].");

            return $result;

        } catch (\Exception $e) {
            $session->status = 'failed';
            $session->save();

            Log::error("Session [{$sessionId}] assembly failed: " . $e->getMessage());

            throw new RuntimeException("Session assembly failed: " . $e->getMessage(), 0, $e);

        } finally {
            $this->cleanupChunkDir($sessionId);

            if ($assembledPath !== null && is_file($assembledPath)) {
                unlink($assembledPath);
            }
        }
    }

    /**
     * Returns the current state of an upload session.
     *
     * Clients use this after a dropped connection to determine which chunks
     * must be re-sent before calling completeSession().
     *
     * @param string $sessionId The session UUID
     * @return array{session_id: string, status: string, original_name: string, total_size: int, total_chunks: int, received_count: int, received_chunks: list<int>, missing_chunks: list<int>, expires_at: string|null}
     * @throws RuntimeException When the session is not found or has expired
     */
    public function getSession(string $sessionId): array
    {
        $session = $this->findActiveSession($sessionId);

        $receivedChunks = [];
        foreach ($session->received_chunks ?? [] as $index => $received) {
            if ($received === true) {
                $receivedChunks[] = (int) $index;
            }
        }
        sort($receivedChunks);

        return [
            'session_id'      => $session->session_id,
            'status'          => $session->status,
            'original_name'   => $session->original_name,
            'total_size'      => $session->total_size,
            'total_chunks'    => $session->total_chunks,
            'received_count'  => count($receivedChunks),
            'received_chunks' => $receivedChunks,
            'missing_chunks'  => array_values($session->missingChunks()),
            'expires_at'      => $session->expires_at?->toIso8601String(),
        ];
    }

    /**
     * Assembles sequential chunk files into a single contiguous file.
     *
     * Reads chunks in index order from the temp directory and writes
     * them to a single temp file. Returns the path to the assembled file.
     *
     * @param UploadSession $session
     * @return string Absolute path to the assembled temp file
     * @throws RuntimeException When a chunk file is missing from disk
     */
    private function assembleChunks(UploadSession $session): string
    {
        $assembledPath = $this->tempBaseDir() . '/assembled_' . $session->session_id;
        $outputHandle  = fopen($assembledPath, 'wb');

        if ($outputHandle === false) {
            throw new RuntimeException('Failed to create assembly buffer file.');
        }

        stream_set_chunk_size($outputHandle, 1048576);

        try {
            for ($i = 0; $i < $session->total_chunks; $i++) {
                $chunkPath = $this->chunkPath($session->session_id, $i);

                if (!file_exists($chunkPath)) {
                    throw new RuntimeException("Chunk [{$i}] file is missing from disk for session [{$session->session_id}].");
                }

                $chunkHandle = fopen($chunkPath, 'rb');

                if ($chunkHandle === false) {
                    throw new RuntimeException("Failed to open chunk [{$i}] for reading.");
                }

                stream_copy_to_stream($chunkHandle, $outputHandle);

                fclose($chunkHandle);
            }
        } finally {
            fclose($outputHandle);
        }

        return $assembledPath;
    }

    /**
     * Writes a chunk's binary data to the session's temporary chunk directory.
     *
     * @param string       $sessionId
     * @param int          $chunkIndex
     * @param UploadedFile $chunk
     */
    private function writeChunkToDisk(string $sessionId, int $chunkIndex, UploadedFile $chunk): void
    {
        $dir = $this->chunkDir($sessionId);

        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create chunk directory: {$dir}");
        }

        $destination = $dir . "/chunk_{$chunkIndex}";
        $sourcePath  = $chunk->getRealPath();

        if ($sourcePath === false || !file_exists($sourcePath)) {
            throw new RuntimeException("Chunk source file is not accessible for index [{$chunkIndex}].");
        }

        // Copy instead of move so the source temp file remains valid if the same
        // UploadedFile instance is reused (common in test environments).
        if (!copy($sourcePath, $destination)) {
            throw new RuntimeException("Failed to write chunk [{$chunkIndex}] to: {$destination}");
        }
    }

    /**
     * Removes the temporary directory holding all chunk files for a session.
     *
     * @param string $sessionId
     */
    private function cleanupChunkDir(string $sessionId): void
    {
        $dir = $this->chunkDir($sessionId);

        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*') ?: [];

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($dir)) {
            rmdir($dir);
        }
    }

    /**
     * Base directory for storing temporary chunk files and assembled buffer files.
     */
    private function tempBaseDir(): string
    {
        $dir = config('media-vault.chunking.temp_directory')
            ?: (function_exists('storage_path') ? storage_path('app/chunks') : sys_get_temp_dir() . '/chunks');

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return sys_get_temp_dir();
        }

        return rtrim($dir, '/\\');
    }

    /**
     * Returns the absolute path of the temporary directory for a session's chunks.
     *
     * @param string $sessionId
     * @return string
     */
    private function chunkDir(string $sessionId): string
    {
        return $this->tempBaseDir() . '/chunks_' . $sessionId;
    }

    /**
     * Returns the absolute path for a specific chunk file.
     *
     * @param string $sessionId
     * @param int    $chunkIndex
     * @return string
     */
    private function chunkPath(string $sessionId, int $chunkIndex): string
    {
        return $this->chunkDir($sessionId) . "/chunk_{$chunkIndex}";
    }

    /**
     * Finds an upload session by UUID and validates that it is still active.
     *
     * @param string $sessionId
     * @return UploadSession
     * @throws RuntimeException When the session is not found, expired, or already complete
     */
    private function findActiveSession(string $sessionId, bool $lock = false): UploadSession
    {
        $this->ensureDatabaseEnabled();
        $query = UploadSession::where('session_id', $sessionId);
        
        if ($lock) {
            $query->lockForUpdate();
        }

        $session = $query->first();

        if ($session === null) {
            throw new RuntimeException("Upload session [{$sessionId}] was not found.");
        }

        if ($session->user_id !== null && (string) $session->user_id !== (string) Auth::id()) {
            throw new RuntimeException("Unauthorized access to upload session [{$sessionId}].");
        }

        if (!$session->isValid()) {
            throw new RuntimeException("Upload session [{$sessionId}] has expired.");
        }

        if ($session->status === 'complete') {
            throw new RuntimeException("Upload session [{$sessionId}] is already complete.");
        }

        return $session;
    }

    /**
     * Pre-validates file extension, total size, and user quota before starting a session.
     *
     * @param string $originalName
     * @param string $mimeType
     * @param int    $totalSize
     * @param array<string, mixed> $options
     * @throws RuntimeException When pre-validation fails
     */
    private function preValidateSession(
        string $originalName,
        string $mimeType,
        int    $totalSize,
        array  $options = [],
    ): void {
        // 1. Quota check if enabled
        if (config('media-vault.quota.enabled', false)) {
            $quotaManager = app(QuotaManager::class);
            $userId       = Auth::id();
            if ($userId !== null && !$quotaManager->hasAvailableSpace((string)$userId, $totalSize)) {
                $mbSize = round($totalSize / 1048576, 2);
                throw new RuntimeException("Uploading this file ({$mbSize} MB) exceeds your remaining storage quota.");
            }
        }

        // 2. Validate max size and extension against configured validation rules
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $config = config('media-vault.validation', []);

        $category = 'other';
        if (in_array($ext, ['jpeg', 'png', 'jpg', 'gif', 'webp', 'svg'], true)) {
            $category = 'image';
        } elseif (in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'flv'], true)) {
            $category = 'video';
        } elseif (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'flac'], true)) {
            $category = 'audio';
        } elseif (in_array($ext, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'json', 'zip', 'rar', '7z'], true)) {
            $category = 'document';
        }

        /** @var string|array<mixed> $ruleVal */
        $ruleVal = $options['validation_rules']['file']
            ?? $options['validation_rules'][$category]
            ?? $config['custom_fields']['file']
            ?? $config[$category]
            ?? $config['other']
            ?? 'required|file|max:5242880';

        $ruleStr = is_array($ruleVal) ? implode('|', $ruleVal) : (string) $ruleVal;

        if (preg_match('/max:(\d+)/i', $ruleStr, $matches)) {
            $maxKb    = (int) $matches[1];
            $maxBytes = $maxKb * 1024;

            if ($totalSize > $maxBytes) {
                $fileMb    = round($totalSize / 1048576, 2);
                $allowedMb = round($maxBytes / 1048576, 2);
                throw new RuntimeException("File [{$originalName}] ({$fileMb} MB) exceeds maximum allowed size limit of {$allowedMb} MB.");
            }
        }

        if (preg_match('/mimes:([a-zA-Z0-9,]+)/i', $ruleStr, $matches)) {
            $allowedMimes = explode(',', strtolower($matches[1]));
            if ($ext !== '' && !in_array($ext, $allowedMimes, true)) {
                throw new RuntimeException("File extension [.{$ext}] is not allowed for upload.");
            }
        }
    }

    /**
     * Ensures that database tracking is enabled before performing session operations.
     *
     * @throws RuntimeException When database tracking is disabled
     */
    private function ensureDatabaseEnabled(): void
    {
        if (!config('media-vault.database.enabled', true)) {
            throw new RuntimeException(
                'Resumable chunked uploads require database tracking to be enabled (media-vault.database.enabled = true).'
            );
        }
    }
}
