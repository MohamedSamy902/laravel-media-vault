<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Represents a resumable upload session.
 *
 * An upload session is created when a client initiates a multi-chunk upload.
 * Each chunk is tracked in the received_chunks JSON array by its zero-based index.
 * The session moves from "pending" to "assembling" to "complete" as chunks arrive.
 *
 * @property int         $id
 * @property string      $session_id      UUID used in client requests
 * @property int|null    $user_id
 * @property string      $original_name
 * @property string      $disk
 * @property string      $folder
 * @property string      $mime_type
 * @property int         $total_size      Expected total file size in bytes
 * @property int         $total_chunks    Total number of chunks expected
 * @property array       $received_chunks Array indexed by chunk number, true = received
 * @property string      $status          pending | assembling | complete | failed
 * @property string|null $assembled_path  Storage path after all chunks are assembled
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @mixin \Illuminate\Database\Eloquent\Builder<static>
 */
class UploadSession extends Model
{
    protected $table = 'upload_sessions';

    protected $fillable = [
        'session_id',
        'user_id',
        'original_name',
        'disk',
        'folder',
        'mime_type',
        'total_size',
        'total_chunks',
        'received_chunks',
        'status',
        'assembled_path',
        'expires_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'received_chunks' => 'array',
        'expires_at'      => 'datetime',
        'total_size'      => 'integer',
        'total_chunks'    => 'integer',
    ];

    /**
     * Returns true when all chunks have been received.
     *
     * Counts the number of chunk indices marked as received and compares
     * to the expected total.
     */
    public function isComplete(): bool
    {
        $received = array_filter($this->received_chunks ?? [], fn ($v) => $v === true);
        return count($received) >= $this->total_chunks;
    }

    /**
     * Returns an array of missing chunk indices.
     *
     * Used by clients to determine which chunks to re-send after resuming.
     *
     * @return list<int> Zero-based indices of chunks not yet received
     */
    public function missingChunks(): array
    {
        $missing = [];

        for ($i = 0; $i < $this->total_chunks; $i++) {
            if (empty($this->received_chunks[$i])) {
                $missing[] = $i;
            }
        }

        return $missing;
    }

    /**
     * Marks a single chunk index as received and persists the change.
     *
     * @param int $chunkIndex Zero-based index of the received chunk
     */
    public function markChunkReceived(int $chunkIndex): void
    {
        $chunks = $this->received_chunks ?? [];
        $chunks[$chunkIndex] = true;
        $this->received_chunks = $chunks;
        $this->save();
    }

    /**
     * Returns true when the session has not yet reached its expiry time.
     */
    public function isValid(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
