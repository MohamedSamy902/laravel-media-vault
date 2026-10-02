<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Unit;

use MohamedSamy902\LaravelMediaVault\Support\TrashPath;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;

class TrashPathTest extends TestCase
{
    public function test_converts_active_path_to_structured_trash_path(): void
    {
        $this->assertSame(
            'uploads/.trash/default/photo.webp',
            TrashPath::toTrash('uploads/default/photo.webp')
        );
    }

    public function test_restores_logical_path_from_trash(): void
    {
        $this->assertSame(
            'uploads/default/photo.webp',
            TrashPath::fromTrash('uploads/.trash/default/photo.webp')
        );
    }

    public function test_detects_trashed_paths(): void
    {
        $this->assertTrue(TrashPath::isTrashed('uploads/.trash/default/a.jpg'));
        $this->assertFalse(TrashPath::isTrashed('uploads/default/a.jpg'));
    }

    public function test_physical_path_respects_trashed_flag(): void
    {
        $logical = 'uploads/default/a.jpg';

        $this->assertSame($logical, TrashPath::physical($logical, false));
        $this->assertSame('uploads/.trash/default/a.jpg', TrashPath::physical($logical, true));
    }
}
