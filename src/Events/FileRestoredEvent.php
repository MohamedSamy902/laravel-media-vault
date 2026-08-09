<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FileRestoredEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param string $path The path of the restored file.
     */
    public function __construct(public string $path)
    {
    }
}
