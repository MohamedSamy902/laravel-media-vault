<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Services;

use Symfony\Component\Mime\MimeTypes;

/**
 * Resolves MIME types to file extensions and categorizes files by type.
 *
 * This class centralizes all MIME type logic, keeping it out of upload
 * and validation services. It acts as a lookup table and parser.
 */
final class MimeTypeResolver
{
    /**
     * Maps known MIME types to their preferred file extensions.
     *
     * This list covers common types. For uncommon types, the Symfony
     * MimeTypes component is used as a fallback.
     */
    private const EXTENSION_MAP = [
        'image/jpeg'        => 'jpg',
        'image/png'         => 'png',
        'image/gif'         => 'gif',
        'image/webp'        => 'webp',
        'video/mp4'         => 'mp4',
        'video/quicktime'   => 'mov',
        'video/x-msvideo'   => 'avi',
        'video/x-matroska'  => 'mkv',
        'video/webm'        => 'webm',
        'audio/mpeg'        => 'mp3',
        'audio/wav'         => 'wav',
        'audio/ogg'         => 'ogg',
        'application/pdf'   => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel'                                                => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => 'xlsx',
        'application/vnd.ms-powerpoint'                                           => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'text/plain'        => 'txt',
        'text/csv'          => 'csv',
        'text/xml'          => 'xml',
        'application/json'  => 'json',
    ];

    /**
     * MIME types that belong to the "document" category.
     */
    private const DOCUMENT_MIMES = [
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain',
    ];

    /**
     * Resolves the best file extension for the given MIME type.
     *
     * Checks the internal map first, then falls back to the Symfony MIME
     * component, and finally derives the extension from the URL path.
     *
     * @param string $mime The MIME type (e.g. "image/jpeg")
     * @param string $url  The source URL used as a fallback for extension detection
     * @return string      The resolved extension without a leading dot
     */
    public function toExtension(string $mime, string $url = ''): string
    {
        if (isset(self::EXTENSION_MAP[$mime])) {
            return self::EXTENSION_MAP[$mime];
        }

        $extensions = (new MimeTypes())->getExtensions($mime);

        if (!empty($extensions)) {
            // Prefer shorter, more recognizable forms (e.g. "jpg" over "jpeg")
            $aliases = ['jpeg' => 'jpg'];
            return $aliases[$extensions[0]] ?? $extensions[0];
        }

        if ($url !== '') {
            $path = parse_url($url, PHP_URL_PATH);
            $fromUrl = pathinfo((string) $path, PATHINFO_EXTENSION);
            if ($fromUrl !== '') {
                return $fromUrl;
            }
        }

        return 'bin';
    }

    /**
     * Categorizes a MIME type into a logical file type string.
     *
     * Returns one of: image, video, audio, pdf, document, other.
     *
     * @param string $mime The full MIME type string
     * @return string      The logical file category
     */
    public function toFileType(string $mime): string
    {
        [$prefix] = explode('/', $mime, 2);

        if (in_array($prefix, ['image', 'video', 'audio'], true)) {
            return $prefix;
        }

        if ($mime === 'application/pdf') {
            return 'pdf';
        }

        if (in_array($mime, self::DOCUMENT_MIMES, true)) {
            return 'document';
        }

        return 'other';
    }

    /**
     * Strips charset and boundary parameters from a Content-Type header value.
     *
     * For example, "image/jpeg; charset=utf-8" becomes "image/jpeg".
     *
     * @param string $contentType The raw Content-Type header value
     * @return string             The bare MIME type
     */
    public function parseContentType(string $contentType): string
    {
        return trim(explode(';', $contentType)[0]);
    }
}
