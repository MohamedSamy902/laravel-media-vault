<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Feature;

use MohamedSamy902\LaravelMediaVault\Exceptions\SsrfException;
use MohamedSamy902\LaravelMediaVault\Security\SsrfValidator;
use MohamedSamy902\LaravelMediaVault\Support\MediaUrl;
use MohamedSamy902\LaravelMediaVault\Support\ThumbnailPath;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;

class HardeningReviewFixesTest extends TestCase
{
    public function test_ssrf_blocks_ipv4_mapped_ipv6_loopback(): void
    {
        $validator = new SsrfValidator();

        $this->expectException(SsrfException::class);
        $validator->validate('http://[::ffff:127.0.0.1]/file.jpg');
    }

    public function test_ui_require_auth_defaults_to_true_in_published_config(): void
    {
        $config = require __DIR__ . '/../../config/media-vault.php';
        $this->assertTrue($config['ui']['require_auth']);
        $this->assertTrue($config['security']['rate_limit']['enabled']);
    }

    public function test_thumbnail_path_helper_builds_conventional_names(): void
    {
        $this->assertSame(
            'uploads/thumb_small_photo.webp',
            ThumbnailPath::for('uploads/photo.webp', 'small')
        );
        $this->assertTrue(ThumbnailPath::isThumbnail('uploads/thumb_small_photo.webp'));
        $this->assertFalse(ThumbnailPath::isThumbnail('uploads/photo.webp'));
    }

    public function test_trashed_media_url_is_blank(): void
    {
        $this->assertSame('', MediaUrl::forMaybeTrashed('public', 'uploads/.trash/a.jpg', true));
    }
}
