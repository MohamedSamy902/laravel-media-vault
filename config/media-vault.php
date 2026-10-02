<?php

return [

    // =========================================================================
    // Storage
    // =========================================================================

    'storage' => [
        'disk'           => env('MEDIA_VAULT_DISK', env('FILE_UPLOAD_DISK', 'public')),
        'path'           => env('MEDIA_VAULT_PATH', env('FILE_UPLOAD_PATH', 'uploads')),
        'default_folder' => 'default',
        'cdn'            => [
            'enabled' => env('MEDIA_VAULT_CDN_ENABLED', env('FILE_UPLOAD_CDN_ENABLED', false)),
            'url'     => env('MEDIA_VAULT_CDN_URL', env('FILE_UPLOAD_CDN_URL', '')),
        ],
    ],

    // =========================================================================
    // Chunking & Resumable Upload Settings
    // =========================================================================

    'chunking' => [
        // Default chunk size in bytes (5 MB). Consumed by media-vault.js when chunkSize is omitted.
        'default_chunk_size' => env('MEDIA_VAULT_CHUNK_SIZE', 5242880),
        // null defaults to storage_path('app/chunks')
        'temp_directory' => env('MEDIA_VAULT_CHUNK_TEMP_DIR', null),
    ],

    'chunked' => [
        'session_ttl_hours' => 24,
    ],

    // =========================================================================
    // Validation rules (Laravel validation syntax)
    // =========================================================================

    'validation' => [
        'image'    => 'required|image|mimes:jpeg,png,jpg,gif,webp,svg|max:51200',
        'video'    => 'required|mimes:mp4,mov,avi,mkv,webm,flv|max:5242880',
        'audio'    => 'required|mimes:mp3,wav,ogg,m4a,flac|max:512000',
        'document' => 'required|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,json,zip,rar,7z|max:512000',
        'other'    => 'required|file|max:5242880',
        'custom_fields' => [
            'file'    => 'required|file|mimes:jpeg,png,jpg,gif,webp,svg,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,json,zip,mp3,mp4,mov,webm|max:5242880',
            'files'   => 'required|array',
            'files.*' => 'required|file|mimes:jpeg,png,jpg,gif,webp,svg,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,json,zip,mp3,mp4,mov,webm|max:5242880',
        ],
    ],

    // =========================================================================
    // URL Upload / Download
    // =========================================================================

    'url_download' => [
        // Allowed MIME map for remote URL ingest (empty type lists allow none for that type)
        'allowed_mimes' => [
            'image'       => ['jpeg', 'png', 'jpg', 'gif', 'webp', 'svg'],
            'video'       => ['mp4', 'mov', 'avi', 'mkv', 'webm'],
            'audio'       => ['mp3', 'wav', 'ogg'],
            'application' => [
                'pdf', 'msword', 'zip', 'x-zip-compressed',
                'vnd.openxmlformats-officedocument.wordprocessingml.document',
                'vnd.ms-excel',
                'vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'vnd.ms-powerpoint',
                'vnd.openxmlformats-officedocument.presentationml.presentation',
            ],
            'text' => ['plain', 'csv', 'xml'],
        ],
    ],

    'url_upload' => [
        'allowed_domains'  => [],
        'timeout_seconds'  => env('MEDIA_VAULT_URL_TIMEOUT', env('FILE_UPLOAD_URL_TIMEOUT', 10)),
        'max_size_bytes'   => env('MEDIA_VAULT_URL_MAX_SIZE', env('FILE_UPLOAD_URL_MAX_SIZE', 52428800)),
    ],

    // =========================================================================
    // Image Processing (Intervention/Image v3)
    // =========================================================================

    'image_driver' => env('MEDIA_VAULT_IMAGE_DRIVER', env('FILE_UPLOAD_IMAGE_DRIVER', 'gd')),

    'processing' => [
        'image' => [
            'enabled' => true,
            'resize'  => [
                'width'                 => 1200,
                'height'                => 1200,
                'maintain_aspect_ratio' => true,
                'upsize'                => false,
            ],
            'watermark' => [
                'enabled'  => false,
                'path'     => null,   // relative to public_path()
                'position' => 'bottom-right',
                // Passed to Intervention place() opacity (0–100)
                'opacity'  => 50,
                'x_offset' => 10,
                'y_offset' => 10,
            ],
            'filters' => [
                // 'brightness' => 10,
                // 'contrast'   => 10,
                // 'greyscale'  => true,
                // 'blur'       => 0,
            ],
            'convert_to' => 'webp',
            'quality'    => 85,
            // Requires: composer require spatie/image-optimizer
            'optimize'   => false,
        ],
        // Optional FFmpeg module (not bundled). Keep disabled unless you provide an FFmpeg binary.
        'video' => [
            'enabled'    => false,
            'convert_to' => 'mp4',
            'bitrate'    => '1000k',
            'resolution' => '1280x720',
        ],
    ],

    // =========================================================================
    // Thumbnails
    // =========================================================================

    'thumbnails' => [
        'enabled' => true,
        'sizes'   => [
            'small'  => ['width' => 150, 'height' => 150, 'crop' => false],
            'medium' => ['width' => 300, 'height' => 300, 'crop' => false],
            'large'  => ['width' => 600, 'height' => 600, 'crop' => false],
            'mobile' => ['width' => 480, 'height' => 850, 'crop' => false],
        ],
        // Generate thumbnails after upload via queue job when true.
        'async'      => env('MEDIA_VAULT_THUMBS_ASYNC', false),
        // Video frame capture requires FFmpeg (optional / not implemented in core).
        'for_videos' => false,
        'seconds'    => 5,
    ],

    // =========================================================================
    // Quota Management
    // =========================================================================

    'quota' => [
        'enabled'           => env('MEDIA_VAULT_QUOTA_ENABLED', env('FILE_UPLOAD_QUOTA_ENABLED', false)),
        'max_size_per_user' => 1073741824,  // 1 GB
        'key_column'        => 'user_id',   // or tenant_id for multi-tenant
        // Fire QuotaWarning when usage/limit exceeds this fraction
        'warning_threshold' => 0.9,
    ],

    // =========================================================================
    // Multi-Source Media & Scanning
    // =========================================================================

    'media_models' => [],
    'max_orphan_scan_limit' => env('MEDIA_VAULT_MAX_SCAN_LIMIT', env('FILE_UPLOAD_MAX_SCAN_LIMIT', 100000)),

    // =========================================================================
    // Database Tracking
    // =========================================================================

    'database' => [
        'enabled'     => env('MEDIA_VAULT_DB_ENABLED', env('FILE_UPLOAD_DB_ENABLED', true)),
        'model'       => \MohamedSamy902\LaravelMediaVault\Models\FileUpload::class,
        'table'       => 'file_uploads',
        // Days before pruning unused (is_used=false) records; null = never
        'prune_after' => 30,
    ],

    // =========================================================================
    // Security
    // =========================================================================

    'security' => [
        'bulk_delete_warning_threshold' => 100,
        // Compare finfo magic bytes against declared MIME / extension
        'strict_mime_validation' => true,
        'rate_limit'             => [
            'enabled'     => env('MEDIA_VAULT_RATE_LIMIT_ENABLED', true),
            'max_uploads' => 60,
            'per_minutes' => 1,
        ],
        'virus_scan' => [
            'enabled'   => false,
            'path'      => env('CLAMSCAN_PATH', '/usr/bin/clamscan'),
            'socket'    => env('CLAMD_SOCKET', 'tcp://127.0.0.1:3310'),
            'fail_mode' => env('CLAMAV_FAIL_MODE', 'closed'), // closed | open
        ],
    ],

    // =========================================================================
    // Temporary Signed URLs
    // Uses Storage::temporaryUrl() when the disk supports it; otherwise a
    // signed local route under route_prefix (TemporaryUrl helper).
    // =========================================================================

    'temp_url' => [
        'enabled'      => true,
        'route_prefix' => 'media-vault-urls',
        'route_name'   => 'media-vault.temp',
        'middleware'   => ['web'],
    ],

    // =========================================================================
    // Image quality override for encoded outputs (not document compression)
    // =========================================================================

    'compression' => [
        'enabled' => false,
        'quality' => 80, // applied when enabled as image encode quality override
    ],

    // =========================================================================
    // Media Manager UI Dashboard
    // =========================================================================

        'ui' => [
        'route_prefix' => env('MEDIA_VAULT_UI_PREFIX', env('FILE_UPLOAD_UI_PREFIX', 'media-vault')),
        'require_auth' => env('MEDIA_VAULT_UI_REQUIRE_AUTH', env('FILE_UPLOAD_UI_REQUIRE_AUTH', true)),
        // When true (default), mutating dashboard actions require the file owner or a null owner.
        'enforce_ownership' => env('MEDIA_VAULT_UI_ENFORCE_OWNERSHIP', true),
        // Add 'auth' manually, or enable ui.require_auth above.
        'middleware'   => ['web'],
    ],

];
