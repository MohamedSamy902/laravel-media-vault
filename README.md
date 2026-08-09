# Laravel Media Vault

A highly scalable, production-ready file and media management package for Laravel. It provides unified management for centralized uploads and custom polymorphic model fields, seamlessly handling URL downloads, image processing, massive datasets with robust memory safety, and chunked resumable uploads.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mohamedsamy902/laravel-media-vault.svg?style=flat-square)](https://packagist.org/packages/mohamedsamy902/laravel-media-vault)
[![PHP Version Require](https://img.shields.io/badge/PHP-%5E8.2-blue.svg)](https://php.net)
[![Laravel Version Require](https://img.shields.io/badge/Laravel-%3E%3D10.0-red.svg)](https://laravel.com)
[![PHPStan Level](https://img.shields.io/badge/PHPStan-Level%206-brightgreen.svg)](https://phpstan.org)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE.md)
[![Tests](https://img.shields.io/github/actions/workflow/status/MohamedSamy902/laravel-media-vault/tests.yml?branch=main&label=tests)](https://github.com/MohamedSamy902/laravel-media-vault/actions)

![Laravel Media Vault Dashboard](art/dashboard_preview.png)


## Table of Contents
1. [Features](#1-features)
2. [Why Media Vault?](#2-why-media-vault)
3. [Requirements](#3-requirements)
4. [Installation](#4-installation)
5. [Configuration](#5-configuration)
6. [Usage Examples](#6-usage-examples)
7. [Response Format](#7-response-format)
8. [Database Model](#8-database-model)
9. [Dashboard & Media Manager UI](#9-dashboard--media-manager-ui)
10. [Cloud Storage Setup](#10-cloud-storage-setup)
11. [Console Commands](#11-console-commands)
12. [⚠️ Danger Zone / Known Limitations](#12-️-danger-zone--known-limitations)
13. [Testing](#13-testing)
14. [Advanced Security & Performance Features](#14-advanced-security--performance-features)
15. [Security](#15-security)
16. [Contributing](#16-contributing)
17. [Changelog](#17-changelog)
18. [License](#18-license)

---

## 1. Features

- **Upload Handlers:** Direct File Upload, Multiple (Batch) Uploads, Chunked (Resumable) Uploads, and Uploads from URL.
- **Image Processing:** On-the-fly resizing, format conversion (e.g. `webp`), optimization, and watermarking via Intervention Image v3.
- **Storage Integration:** Out-of-the-box support for Local disks, Amazon S3, Google Cloud Storage, and CDN URL rewriting.
- **Multi-Source Media:** Auto-discovery of custom Eloquent models via the `HasMediaFields` trait alongside the central polymorphic repository (`HasUploads`).
- **Database Tracking:** Comprehensive file tracking with usage state, polymorphic ownership, and soft deletion.
- **Dashboard SPA:** Built-in zero-dependency SPA UI (Vanilla JS + PJAX + CSS Tokens) to manage files, view statistics, and handle orphans directly without npm builds.
- **Bulk Deletion Safety Net:** 2-step verification (Preview → Token → Execute) for mass deletions to prevent catastrophic data loss.
- **Security & Quotas:** Strict SSRF URL validation, user-level storage quotas, strict MIME binary validation, and SVG entity expansion sanitization.

---

## 2. Why Media Vault?

Unlike traditional Laravel media management packages (e.g., Spatie Media Library or standard upload helpers) that load entire files into memory leading to Out-Of-Memory (OOM) crashes on large files:

- ⚡ **Zero-OOM Streaming Engine:** Streams remote and chunked uploads directly to storage with minimal memory footprint (< 10MB) even when handling multi-gigabyte files.
- 📱 **Multi-Platform Integration Ready:** Built-in execution patterns for **Traditional Blade Forms**, **JS Client** (with automatic state resumption), and **Mobile REST APIs** (Flutter, React Native, Swift, Kotlin).
- 🖥️ **Zero-Build SPA Media Manager:** Features a standalone SPA Dashboard (Vanilla JS PJAX + CSS Tokens + SweetAlert2) providing zero-reload navigation, live search, and orphan recovery out-of-the-box without npm/Vite compilation.
- 🛡️ **Production Safety Net:** Includes magic-byte binary verification, SSRF protection, ClamAV antivirus scanning, user storage quotas, and a 2-stage cryptographically signed preview token for mass deletions.itization.

---

## 3. Requirements

| Requirement | Version | Notes |
|-------------|---------|-------|
| PHP | `^8.2` | |
| Laravel | `>=10.0` | |
| Extensions | `ext-gd` or `ext-imagick` | Required for image processing |

---

## 4. Installation

**1. Install via Composer:**
```bash
composer require mohamedsamy902/laravel-media-vault
```

**2. Publish Configuration:**
```bash
php artisan vendor:publish --tag="media-vault-config"
```

**3. Run Migrations (Optional, requires `database.enabled = true`):**
```bash
php artisan migrate
```

---

## 5. Configuration

This package is highly customizable through `config/media-vault.php`. Below is a comprehensive list of every configuration key available.

### Storage (`storage`)
| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `storage.disk` | string | `'public'` | The default Laravel storage disk to use. |
| `storage.path` | string | `'uploads'` | The base directory inside the selected disk. |
| `storage.default_folder` | string | `'default'` | The default subfolder used if none is specified during upload. |
| `storage.cdn.enabled` | boolean | `false` | Whether to replace the storage URL domain with a CDN domain. |
| `storage.cdn.url` | string | `''` | The base URL of your CDN. |

### Validation (`validation`)
Contains standard Laravel validation strings mapped to file types (`image`, `video`, `audio`, `document`, `other`, `custom_fields`). Used internally to validate incoming files based on rules like sizes and MIME types.

### URL Upload / Download (`url_download` & `url_upload`)
| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `url_download.enabled` | boolean | `true` | Allow uploading files via a remote URL. |
| `url_download.chunked` | boolean | `true` | True = load into memory; False = stream to disk directly. |
| `url_download.chunk_size` | int | `5242880` | Size of streaming chunks (5MB). |
| `url_download.allowed_mimes`| array | `[...]` | Whitelisted extensions for URL downloads grouped by type. |
| `url_upload.allowed_domains`| array | `[]` | Allowed domains for SSRF protection (empty = allow all public). |
| `url_upload.timeout_seconds`| int | `10` | Hard timeout for the download HTTP request (overrides legacy `url_download.timeout`). |
| `url_upload.max_size_bytes` | int | `52428800`| Max file size allowed from a URL (50MB default) (overrides legacy `url_download.max_size`). |

### Image Processing (`image_driver` & `processing`)
| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `image_driver` | string | `'gd'` | `'gd'` or `'imagick'` (Requires corresponding PHP extension). |
| `processing.image.enabled` | boolean | `true` | Enable/disable all image processing features. |
| `processing.image.resize` | array | `[...]` | Contains `width`, `height`, `maintain_aspect_ratio`, and `upsize`. |
| `processing.image.watermark`| array | `[...]` | Contains `enabled`, `path`, `position`, `opacity`, `x_offset`, `y_offset`. |
| `processing.image.filters` | array | `[]` | Allowed filters: `brightness`, `contrast`, `greyscale`, `blur`. |
| `processing.image.convert_to`| string\|null | `'webp'`| Global format conversion target (e.g. `webp`, `jpg`). |
| `processing.image.quality` | int | `85` | Compression quality (1-100). |
| `processing.image.optimize` | boolean | `false` | Optional integration for Spatie image optimizer. |
| `processing.video.enabled` | boolean | `false` | Enable video processing features. |
| `processing.video.convert_to`| string | `'mp4'` | Target video format. |
| `processing.video.bitrate` | string | `'1000k'` | Target video bitrate. |
| `processing.video.resolution`| string | `'1280x720'`| Target video resolution. |

### Thumbnails (`thumbnails`)
| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `thumbnails.enabled` | boolean | `true` | Automatically generate thumbnails during image upload. |
| `thumbnails.sizes` | array | `[...]` | Associative array of sizes (e.g. `small => ['width' => 150, 'crop' => true]`). |
| `thumbnails.for_videos` | boolean | `false` | Generate thumbnails for video files (requires FFmpeg). |
| `thumbnails.seconds` | int | `5` | Second to capture for video thumbnails. |

### Quota Management (`quota`)
| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `quota.enabled` | boolean | `false` | Enable storage quotas per user. |
| `quota.max_size_per_user` | int | `1073741824`| 1 GB default max size. |
| `quota.key_column` | string | `'user_id'` | DB column to identify owner. Change to `tenant_id` for multi-tenant. |
| `quota.warning_threshold` | float | `0.9` | Fraction (e.g. 0.9 = 90%) to trigger `QuotaWarning` event. |
| `quota.check_method` | string | `'database'`| Method for checking quota (`database` or `session`). |

### Multi-Source & DB Tracking (`media_models`, `database`)
| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `media_models` | array | `[]` | List of Custom Eloquent models using `HasMediaFields`. |
| `max_orphan_scan_limit` | int | `100000` | Cap on files scanned per run to prevent memory exhaustion. |
| `database.enabled` | boolean | `true` | Track uploads in the `file_uploads` table. |
| `database.model` | string | `FileUpload::class` | Class reference for the database model. |
| `database.table` | string | `'file_uploads'`| Database table name. |
| `database.prune_after` | int\|null| `30` | Days to retain soft-deleted/unused records before physical prune. |

### Security & Chunks (`security`, `chunked`)
| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `security.bulk_delete_warning_threshold`| int | `100` | Deletions exceeding this require an extra warning. |
| `security.strict_mime_validation`| boolean | `true` | Validate file binary magic bytes against declared MIME type. |
| `security.rate_limit.enabled`| boolean | `false` | Enable upload rate limiting. |
| `security.rate_limit.max_uploads`| int | `60` | Max uploads per time window. |
| `security.rate_limit.per_minutes`| int | `1` | Time window in minutes. |
| `security.virus_scan.enabled`| boolean | `false` | Enable ClamAV virus scanning. |
| `security.virus_scan.driver`| string | `'clamav'`| Virus scanning driver. |
| `security.virus_scan.path`| string | `'/usr/bin/clamscan'`| Path to the ClamAV executable. |
| `chunked.session_ttl_hours` | int | `24` | Hours to keep pending resumable upload sessions before expiration. |

### Advanced Features (`temp_url`, `compression`, `logging`)
| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `temp_url.route_prefix` | string | `'media-vault-urls'`| Prefix for temporary signed URL routes. |
| `temp_url.middleware` | array | `[]` | Middleware for temporary signed URLs. |
| `compression.enabled` | boolean | `false` | Enable file compression (e.g., zip) before storage. |
| `compression.types` | array | `[...]` | File extensions eligible for compression. |
| `compression.quality` | int | `80` | Compression quality level. |
| `logging.enabled` | boolean | `true` | Enable internal operation logging. |
| `logging.level` | string | `'info'` | The log level to use. |

### UI Dashboard (`ui`)
| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `ui.route_prefix` | string | `'media-vault'`| Base URL for the Media Manager Dashboard. |
| `ui.middleware` | array | `['web']` | **CRITICAL:** Add `'auth'` to protect the dashboard! |

---

## 6. Usage Examples

### Single & Multiple File Upload via HTTP Request
```php
use MohamedSamy902\LaravelMediaVault\Facades\MediaVault;
use Illuminate\Http\Request;

public function store(Request $request) {
    // Single file
    $result = MediaVault::upload($request, [
        'field_name' => 'avatar',
        'folder_name' => 'avatars',
        'convert_to' => 'webp',
        'quality' => 90
    ]);

    // Batch files (using name="files[]" in HTML)
    $results = MediaVault::upload($request, [
        'field_name' => 'files' // Automatically processes all array items
    ]);
}
```

### Direct Upload with UploadedFile
```php
use MohamedSamy902\LaravelMediaVault\Facades\MediaVault;

// Assuming $file is an Illuminate\Http\UploadedFile instance
$result = MediaVault::upload($file, [
    'disk' => 's3',
    'folder_name' => 'documents',
]);
```

### Upload from Remote URL
```php
use MohamedSamy902\LaravelMediaVault\Facades\MediaVault;

// Single URL
$result = MediaVault::uploadFromUrl('https://example.com/image.jpg', [
    'folder_name' => 'downloads',
    'disk' => 's3'
]);

// Multiple URLs
$results = MediaVault::uploadFromUrl([
    'https://example.com/file1.pdf',
    'https://example.com/file2.pdf'
], [
    'folder_name' => 'downloads'
]);
```

### Upload Methods & Integration Scenarios

The package supports three distinct integration approaches depending on your application frontend architecture:

#### Option A: Traditional Blade HTML Form (No JS / Non-Chunked)
For standard Laravel applications submitting traditional HTML forms directly to the backend:

**Blade Template (`resources/views/upload.blade.php`):**
```html
<form action="/upload" method="POST" enctype="multipart/form-data">
    @csrf
    <input type="file" name="avatar" required>
    <button type="submit">Upload File</button>
</form>
```

**Laravel Controller:**
```php
use MohamedSamy902\LaravelMediaVault\Facades\MediaVault;

public function store(Request $request) {
    $request->validate(['avatar' => 'required|file|max:10240']);

    $result = MediaVault::upload($request, [
        'field_name'  => 'avatar',
        'folder_name' => 'avatars',
        'convert_to'  => 'webp',
    ]);

    return back()->with('success', 'File uploaded: ' . $result->url);
}
```

---

#### Option B: Resumable Chunked Uploads (Blade + JS Client)
For web applications requiring client-side progress bars and auto-resumption across page refreshes or network drops:

**Blade Template (`resources/views/resumable-upload.blade.php`):**
```html
<link rel="stylesheet" href="{{ asset('vendor/media-vault/media-vault.css') }}">

<!-- Auto-rendered container for interrupted upload prompts -->
<div id="afu-resume-container"></div>

<input type="file" id="afu-fileInput" multiple>
<button onclick="startUpload()">Start Upload</button>

<script src="{{ asset('vendor/media-vault/media-vault.js') }}"></script>
<script>
    function startUpload() {
        window.afuUploadFile({
            inputId: 'afu-fileInput',
            uploadUrl: '/media-vault/upload',
            onProgress: function(percent, chunk, total, file) {
                console.log(`Progress (${file.name}): ${percent}% (Chunk ${chunk}/${total})`);
            },
            onSuccess: function(file, sessionId) {
                console.log(`Upload completed for ${file.name}`);
            },
            onError: function(err, file) {
                console.error(`Upload error: ${err.message}`);
            }
        });
    }
</script>
```

##### Frontend JS Client Capabilities (`media-vault.js`)
- **Per-File LocalStorage Fingerprint:** Each file is uniquely hashed by `afu_upload_${file.name}_${file.size}_${file.lastModified}`.
- **Incremental Persistence:** Updates `localStorage` after every successful chunk and purges state when complete.
- **Concurrent Multi-File Transfers:** Supports uploading multiple files in parallel (`afuUploadFiles([file1, file2])`).
- **Auto-Detection on Page Load:** Automatically queries the backend for pending sessions on `DOMContentLoaded` and renders a **Resume Card** to re-send missing chunks only.

---

#### Option C: Mobile Applications & REST APIs (Flutter, React Native, Swift, Kotlin)
For mobile applications or single-page applications (SPAs) connecting via REST endpoints:

##### 1. Send Chunks Sequentially
Upload binary chunk data to `POST /media-vault/upload` (or your custom API endpoint):
```http
POST /media-vault/upload HTTP/1.1
Content-Type: multipart/form-data; boundary=----FormBoundary

------FormBoundary
Content-Disposition: form-data; name="file"; filename="blob"
Content-Type: application/octet-stream

<Binary Chunk Data>
------FormBoundary
Content-Disposition: form-data; name="chunkNumber"

1
------FormBoundary
Content-Disposition: form-data; name="totalChunks"

5
------FormBoundary
Content-Disposition: form-data; name="originalName"

video.mp4
------FormBoundary--
```

**Response (First Chunk):**
```json
{
  "status": true,
  "sessionId": "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d",
  "done": 20,
  "missing": [1, 2, 3, 4]
}
```
*Mobile App stores `sessionId` in local storage (`SharedPreferences` / `AsyncStorage` / `Hive`).*

##### 2. Query Session Ground Truth on Re-Open / Resume
If the mobile app crashes or network disconnects, query the session status to get the exact missing chunk indices:

```http
GET /media-vault/sessions/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d/status HTTP/1.1
Accept: application/json
```

**Response:**
```json
{
  "status": true,
  "session_id": "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d",
  "original_name": "video.mp4",
  "total_chunks": 5,
  "uploaded_count": 2,
  "progress_percentage": 40,
  "missing_chunks": [2, 3, 4],
  "is_complete": false,
  "expires_at": "2026-08-10T12:00:00Z"
}
```

##### 3. Send Missing Chunks & Complete Assembly
The mobile app sends only the indices listed in `missing_chunks` along with the `sessionId`. When the final chunk arrives, the server returns the completed `UploadResult` JSON object.

---

### Database Storage Strategies: Central Table vs Model Columns

`Laravel Media Vault` provides two distinct architectural approaches for linking uploaded media to your Eloquent models. Choose the strategy that fits your schema requirements:

| Feature / Aspect | Central Table (`HasUploads`) | Separate Model Columns (`HasMediaFields`) |
|------------------|------------------------------|------------------------------------------|
| **Database Storage** | Central `file_uploads` table (Polymorphic `morphMany`). | Direct columns on the model's own table (e.g. `products.cover_image`). |
| **Required Migration** | Package default `file_uploads` table (`php artisan migrate`). | Columns on your model's table (`string` for single, `json` for multiple). |
| **Best Used For** | Dynamic attachments, user avatars, documents, soft-deleted media tracking. | Standard entity attributes (e.g., product cover, category banner, brand logo). |
| **Dashboard & Scanner** | Tracked automatically in Media Library dashboard. | Auto-discovered via `php artisan media-vault:discover-models`. |
| **Thumbnails & Fallbacks**| Full metadata support with automatic size fallback. | Dynamic filename suffix resolution with original image fallback. |

---

#### Strategy 1: Central Database Tracking (`HasUploads`)

Use `HasUploads` when you want files stored as polymorphic relationships in the central `file_uploads` database table.

**1. Migration Setup:**
Ensure package migrations are executed (`database.enabled = true` in config):
```bash
php artisan migrate
```

**2. Model Definition:**
```php
use Illuminate\Database\Eloquent\Model;
use MohamedSamy902\LaravelMediaVault\Traits\HasUploads;

class User extends Model {
    use HasUploads;
}
```

**3. Uploading & Association:**
```php
use MohamedSamy902\LaravelMediaVault\Facades\MediaVault;

// Upload file
$result = MediaVault::upload($request, ['field_name' => 'avatar']);

// Link to model polymorphically
$user = User::find(1);
$user->uploads()->create([
    'path'          => $result->path,
    'disk'          => $result->disk,
    'original_name' => $result->original_name,
    'mime_type'     => $result->mime_type,
    'size'          => $result->size,
    'type'          => 'image',
    'metadata'      => ['thumbnails' => $result->toArray()['thumbnail_urls'] ?? []],
]);
```

**4. Retrieving URLs & Thumbnails:**
```php
// Retrieve latest original image URL
$originalUrl = $user->getMediaUrl('image', 'original');

// Retrieve specific thumbnail size (e.g., 'small', 'medium', 'large')
$mediumUrl = $user->getMediaUrl('image', 'medium');

// Retrieve map of all available thumbnails: ['small' => '...', 'medium' => '...']
$allThumbnails = $user->getThumbnails();
```

---

#### Strategy 2: Separate Model Columns (`HasMediaFields`)

Use `HasMediaFields` when you want file paths stored directly inside columns of your model's database table, avoiding extra rows in the central `file_uploads` table while preserving dashboard scanning and URL resolution.

**1. Migration Setup:**
Add `string` or `json` columns to your model's database migration:
```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('cover_image')->nullable(); // Single file path (string)
            $table->json('gallery')->nullable();       // Multiple file paths (JSON array)
            $table->timestamps();
        });
    }
};
```

**2. Model Definition:**
```php
use Illuminate\Database\Eloquent\Model;
use MohamedSamy902\LaravelMediaVault\Traits\HasMediaFields;

class Product extends Model {
    use HasMediaFields;

    // Declare columns and their storage disks
    protected array $mediaFields = [
        'cover_image' => ['multiple' => false, 'disk' => 'public'],
        'gallery'     => ['multiple' => true,  'disk' => 's3'],
    ];
}
```
*Run `php artisan media-vault:discover-models` to index custom fields for the dashboard scanner.*

**3. Uploading & Saving:**
```php
// Upload single file
$coverResult = MediaVault::upload($request, ['field_name' => 'cover_image']);

// Save path directly to model column
$product = Product::find(1);
$product->update([
    'cover_image' => $coverResult->path,
]);

// Upload multiple gallery files
$galleryResults = MediaVault::upload($request, ['field_name' => 'gallery']);
$paths = array_map(fn($item) => $item->path, $galleryResults);
$product->update([
    'gallery' => $paths, // Auto-serialized as JSON
]);
```

**4. Retrieving URLs & Thumbnails:**
```php
// Retrieve original image URL from column
$coverUrl = $product->getMediaUrl('cover_image');

// Retrieve specific thumbnail size from column file
$smallCoverUrl = $product->getMediaUrl('cover_image', 'small');

// Retrieve URL of first image from JSON gallery array
$galleryFirstUrl = $product->getMediaUrl('gallery');
```

---

### Image Resolution & Thumbnail Behavior (Enabled vs Disabled)

The `getMediaUrl($fieldOrType, $size)` method is **fail-safe** and works seamlessly whether thumbnails are enabled or disabled in `config/media-vault.php`:

| Configuration State | Requested Size Parameter | Returned URL & Behavior |
|---------------------|--------------------------|-------------------------|
| `thumbnails.enabled = true` | `'original'` | Returns the full-resolution original file URL. |
| `thumbnails.enabled = true` | `'small'`, `'medium'`, `'large'` | Returns the requested generated thumbnail URL. |
| `thumbnails.enabled = true` | `'invalid_size'` (Non-existent size) | **Safe Fallback:** Automatically returns the original file URL (prevents broken images). |
| `thumbnails.enabled = false` | Any size (`'small'`, `'medium'`, or `'original'`) | **Safe Fallback:** Always returns the original file URL directly without errors. |

```php
// Example: Safe URL Resolution in Blade Views
<img src="{{ $user->getMediaUrl('image', 'small') }}" alt="User Avatar">
<!-- If thumbnails.enabled = true  => Outputs: https://cdn.site.com/uploads/thumbs/avatar_small.webp -->
<!-- If thumbnails.enabled = false => Outputs: https://cdn.site.com/uploads/avatar.webp (Original) -->
```

### Deleting Files
```php
use MohamedSamy902\LaravelMediaVault\Facades\MediaVault;

// Delete by Database ID
$result = MediaVault::delete(15);

// Delete by Path
$result = MediaVault::delete('uploads/default/image.webp');

// Bulk Delete by Array of IDs or Paths
$results = MediaVault::delete([15, 16, 'uploads/default/test.png']);
```

### Bulk Deletion Guard
Available in the `BulkDeletionGuard` utility service. Always preview a massive deletion action and generate a secure token to proceed.
```php
use MohamedSamy902\LaravelMediaVault\Security\BulkDeletionGuard;
use MohamedSamy902\LaravelMediaVault\Contracts\FileRepositoryContract;

$guard = new BulkDeletionGuard(app(FileRepositoryContract::class));

// Step 1: Preview and obtain a token
$preview = $guard->preview($paths, 'api');
// returns: ['token' => '...', 'count' => 150, 'requires_extra_warning' => true, 'sample' => [...]]

// Step 2: Execute using the token
$result = $guard->execute($preview['token'], forceHardDelete: true);
```

---

## 7. Response Format

All successful uploads return an `UploadResult` object. It seamlessly implements `ArrayAccess` and `JsonSerializable`.

```json
{
  "status": true,
  "id": 142,
  "path": "uploads/avatars/4f1a2...webp",
  "url": "https://cdn.example.com/uploads/avatars/4f1a2...webp",
  "disk": "s3",
  "original_name": "profile_pic.jpg",
  "mime_type": "image/webp",
  "size": 1048576,
  "thumbnail_urls": {
    "small": "https://cdn.example.com/uploads/avatars/thumbs/4f1a2..._small.webp",
    "medium": "https://cdn.example.com/uploads/avatars/thumbs/4f1a2..._medium.webp"
  }
}
```
Failed items in a batch upload will return an array:
```json
{
  "status": false,
  "error": "The uploaded file is invalid or missing.",
  "original_name": "bad_file.exe"
}
```

---

## 8. Database Model

If `database.enabled` is `true`, all uploads go to the `file_uploads` table. The `FileUpload` model includes powerful query scopes:

```php
use MohamedSamy902\LaravelMediaVault\Models\FileUpload;

$images = FileUpload::images()->get();
$videos = FileUpload::videos()->forUser(auth()->id())->get();
$orphans = FileUpload::unused()->get();

// File properties
$file->url; // Resolves CDN automatically
$file->human_size; // "1.5 MB"
$file->existsOnDisk(); // bool
$file->owner_exists; // Safely checks if the polymorphic owner model still exists
```

---

## 9. Dashboard & Media Manager UI

The package provides a built-in zero-dependency Single Page Application (SPA) dashboard to manage files, view sessions, configure settings, and scan for orphaned files.

- **Frontend Tech Stack:** Built with pure Vanilla JS PJAX SPA architecture, custom CSS Design Tokens, FontAwesome 6 icons, and SweetAlert2 notifications. Requires zero node_modules or Vite/Mix compilation.
- **Route:** `your-app.com/media-vault` (Changeable via `ui.route_prefix`).
- **Security Warning:** You **must** attach the `auth` middleware (or a custom admin middleware) in `config/media-vault.php` under `ui.middleware`. Without this, your entire media library is public.
- **Capabilities:** Real-time search/filter, orphan disk scanner, token-based bulk forced deletions, session manager, and storage quota statistics.

---

## 10. Cloud Storage Setup

To use Amazon S3 or Google Cloud Storage, you must require their Flysystem adapters. The package will intelligently throw a clear exception if they are missing.

- **S3:** `composer require league/flysystem-aws-s3-v3:^3.0`
- **GCS:** `composer require spatie/laravel-google-cloud-storage:^2.0`

---

## 11. Console Commands

The package registers several utilities to simplify file management:

- `php artisan media-vault:discover-models` — Scans the `app/Models` directory for `HasMediaFields` usage and caches them.
- `php artisan media-vault:regenerate-thumbnails {--force}` — Iterates over the `file_uploads` table and regenerates missing sizes based on config.
- `php artisan media-vault:import-orphans {disk?}` — Scans a physical disk and imports untracked files into the `file_uploads` table to prevent them from being considered orphans.
- `php artisan media-vault:prune-unused {--days=30} {--force} {--dry-run}` — Irreversibly deletes files that have been marked as unused for `N` days.
- `php artisan media-vault:prune-sessions` — Removes expired `UploadSession` records and their physical incomplete temporary chunks.

---

## 12. ⚠️ Danger Zone / Known Limitations

> [!CAUTION]
> Pay strict attention to these operational warnings to prevent data loss in a production environment:

1. **Thumbnails Backfill Limitation:** The command `media-vault:regenerate-thumbnails` works flawlessly for the central `file_uploads` repository. However, it **does NOT support Custom Models (Multi-Source)** currently. Enabling `thumbnails.enabled` retroactively will not backfill thumbnails for custom models.
2. **Watermarks are Destructive:** Enabling `watermark.enabled` prints the watermark directly onto the original image. **There is no backfill, and there is no way to remove a watermark once applied.**
3. **Removing Custom Models = Data Loss Risk:** If you remove a model from `media_models` in the config after files have been uploaded, the scanner will immediately classify its files as "Orphaned". Running the Orphan Deletion will **permanently wipe them**. Always migrate or empty tables before changing configuration topology.
4. **Custom Sources Pagination:** To prevent Server Crashes (OOM) on huge DB tables, the Dashboard pagination for Custom Sources operates on Database Rows, not individual files. A JSON array of 5 images counts as 1 row in pagination.
5. **Caching:** If you register a new model or trait, you must run `php artisan config:clear` so the Dashboard recognizes it.

---

## 13. Testing

The package includes a comprehensive test suite. We specifically isolate heavy tests (Benchmarks) to prevent CI timeouts.

**Run Unit & Feature Tests:**
```bash
composer test
# or
vendor/bin/phpunit
```

**Run Memory & Performance Benchmarks:**
```bash
vendor/bin/phpunit --testsuite=Benchmarks
```

*(Current Test Count: 177 Passing/Skipped Tests).*

---

## 14. Advanced Security & Performance Features

### 🛡️ Virus Scanning (ClamAV Integration)
The package includes built-in automated virus and malware scanning via ClamAV before any file is saved to storage.

- **CLI Executable:** Executes via local `clamscan` binary path configured in `security.virus_scan.path`.
- **EICAR Detection:** Industry-standard test signature `EICAR-STANDARD-ANTIVIRUS-TEST-FILE` is detected out-of-the-box in development and production environments.

```php
// config/media-vault.php
'security' => [
    'virus_scan' => [
        'enabled' => env('FILE_UPLOAD_VIRUS_SCAN', false),
        'driver'  => 'clamav',
        'path'    => env('CLAMSCAN_PATH', '/usr/bin/clamscan'),
    ],
],
```

### ⚡ File & Image Compression
Pipeline-integrated file compression reduces storage footprint for non-image document formats.

- **Scope:** Document formats (`pdf`, `doc`, `docx`, `xls`, `xlsx`, `ppt`, `pptx`).
- **Execution:** Runs directly inside the `StorageManager` write pipeline using quality parameters based on `compression.quality`.

```php
// config/media-vault.php
'compression' => [
    'enabled' => env('FILE_UPLOAD_COMPRESSION_ENABLED', false),
    'quality' => env('FILE_UPLOAD_COMPRESSION_QUALITY', 80), // 1 - 100 quality percentage
    'types'   => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'],
],
```

### ⏱️ Dual-Layer Rate Limiting
The package distinguishes between two distinct rate limiting safeguards:

1. **Package API Route Limiting (`throttle:media-vault-api`):**
   Protects your application's endpoints (`POST /media-vault/upload`, `POST /media/bulk-destroy`, etc.) from abuse and denial-of-service attacks.
   ```php
   // config/media-vault.php
   'security' => [
       'rate_limit' => [
           'enabled'     => env('FILE_UPLOAD_RATE_LIMIT_ENABLED', true),
           'max_uploads' => 60,
           'per_minutes' => 1,
       ],
   ],
   ```
   When exceeded, endpoints return `HTTP 429 Too Many Requests` with `{ "status": false, "message": "Too Many Requests. Rate limit exceeded for file operations." }`.

2. **External Remote URL Throttling (`UrlDownloader`):**
   Handles `HTTP 429` status responses gracefully when downloading assets from remote third-party CDNs during URL uploads, failing fast with informative error messages.

---

## 15. Security

If you discover any security-related issues (such as bypasses for SSRF, traversal attacks, or token leaks in the Bulk Deletion Guard), please email `mohamedsamy902@gmail.com` directly instead of opening a public issue. Alternatively, you can use GitHub Security Advisories if enabled on the repository.

---

## 16. Contributing

1. Fork the repository.
2. Create your feature branch (`git checkout -b feature/amazing-feature`).
3. Commit your changes (`git commit -m 'Add some amazing feature'`).
4. Ensure all tests pass (`composer test`).
5. Push to the branch (`git push origin feature/amazing-feature`).
6. Open a Pull Request.

---

## 17. Changelog

Please see the [CHANGELOG.md](CHANGELOG.md) for more information on what has changed recently.

---

## 18. License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
