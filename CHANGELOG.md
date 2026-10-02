# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added
- Unified trash / restore / hard-delete lifecycle with structured `.trash/` paths.
- Dashboard stability: dynamic route prefix, bulk restore, scan status polling, thumbnail listing filters, optional `ui.require_auth`.
- Usage tracking helpers: `HasUploads::attachUpload()` / `detachUpload()`, `MediaVault::markAsUsed()` / `markAsUnused()`.
- Safer pruning: `media-vault:prune-unused` respects `database.prune_after`; new `media-vault:prune-trash`.
- Queue jobs: `ScanOrphansJob`, `RegenerateThumbnailsJob`, `PruneTrashJob`.
- `MediaVault::temporaryUrl()` helper over Laravel disk temporary URLs / signed local routes.
- `QuotaWarning` event; strict MIME magic-byte validation; ClamAV `socket` / `fail_mode` in published config.
- Pivot/`tables` path discovery in `CustomMediaSource`.
- CI canary job that auto-resolves the latest stable Laravel from Packagist.

### Changed
- PHP requirement raised to `^8.2`.
- Config hygiene: removed dead `logging` / legacy URL-download toggles; clarified compression as image quality override.
- CI consolidated into a single workflow matrix (Laravel 10–13, PHP 8.2–8.4) with dynamic Testbench mapping.
- Branding updated from “Advanced File Upload” to Laravel Media Vault.
- PHPUnit tests migrated from `@test` docblocks to `#[Test]` attributes (PHPUnit 12 ready).
- Dev constraints widened (`orchestra/testbench: >=8.0`, PHPUnit 10–12) for forward-compatible tooling.

## [v1.1.2] - 2026-09-07

### Fixed
- Thumbnails now encode with configured `convert_to` / quality (avoids WebP filenames with wrong bytes).
- Thumbnail generation uses the processed main file after conversion, not the original upload temp path.
- UploadResult mime type and size reflect the file actually stored on disk after image conversion.

## [v3.0.0] - 2026-08-07

### Added
- **Multi-Source Media Support:** Introduced polymorphic media tracking to seamlessly integrate custom Eloquent models alongside the central file repository.
- **Smart Orphaned Files Scanner:** A memory-safe utility to detect unused physical files across designated upload paths.
- **Dynamic Config Handling:** Full support for toggling thumbnails, watermarks, CDN URLs, and image optimizations via feature flags.
- **CLI Utilities:** Added robust Artisan commands (`regenerate-thumbnails`, `import-orphans`, `discover-models`) to manage system state and backfill historical data.

### Changed (Performance & Stability Improvements)
- **OOM Prevention:** Refactored physical file scanning in `getOrphanedFiles` to use Flysystem's memory-safe generators and eliminated massive Collection caching. Memory footprint remains stable regardless of disk size.
- **DB-Level Pagination & Aggregation:** Refactored `CustomMediaSource::getStats` to eliminate high CPU overhead and `preg_match_all` timeouts on large JSON fields. The system now utilizes fast SQL-level aggregate queries.
- **Strict Data Types:** Enforced strict typed Data Transfer Objects (`FileDto`) with `is_missing` flags to safely handle inconsistencies between the database and physical disks without crashing the UI.

### Security (Data Loss Prevention)
- **Bulk Deletion Safety Net:** Introduced a comprehensive `BulkDeletionGuard` system requiring a two-step (Preview → Token → Execute) process to prevent accidental mass deletions, with configurable threshold warnings.
- **Scoped Physical Scanning:** Patched a critical data loss risk where the orphan scanner traversed the entire root disk (e.g., public folder) instead of only the configured upload directories.
- **Fail-Safe Deletions:** Ensured missing thumbnails or permission errors during deletion properly bubble up via `RuntimeException` instead of being silently swallowed, preventing zombie files.
- **SSRF & File Traversal Protections:** Hardened URL upload logic to prevent SSRF and added strict path sanitization to block Null-Byte and Traversal (`../`) attacks during deletion and restoration operations.
