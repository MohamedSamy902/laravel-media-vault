<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Support;

/**
 * Shared file-category helpers used by validators, scanners, and the dashboard.
 */
final class FileCategories
{
    /** @var list<string> */
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'bmp'];

    /** @var list<string> */
    public const VIDEO_EXTENSIONS = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'flv'];

    /** @var list<string> */
    public const AUDIO_EXTENSIONS = ['mp3', 'wav', 'ogg', 'm4a', 'flac'];

    /** @var list<string> */
    public const DOCUMENT_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'json', 'zip', 'rar', '7z',
    ];

    public static function categoryFromExtension(string $extension): string
    {
        $ext = strtolower($extension);

        if (in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            return 'image';
        }
        if (in_array($ext, self::VIDEO_EXTENSIONS, true)) {
            return 'video';
        }
        if (in_array($ext, self::AUDIO_EXTENSIONS, true)) {
            return 'audio';
        }
        if (in_array($ext, self::DOCUMENT_EXTENSIONS, true)) {
            return 'document';
        }

        return 'other';
    }

    public static function isImagePath(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true);
    }

    public static function isVideoPath(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::VIDEO_EXTENSIONS, true);
    }

    public static function isDocumentPath(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::DOCUMENT_EXTENSIONS, true);
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1073741824) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return round($bytes / 1073741824, 1) . ' GB';
    }
}
