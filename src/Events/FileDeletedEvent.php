<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FileDeletedEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param string $path The path of the deleted file.
     * @param bool $isHardDelete True if permanently deleted, false if moved to .trash.
     */
    public function __construct(public string $path, public bool $isHardDelete)
    {
    }
}
