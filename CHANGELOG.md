# Changelog

All notable changes to this project will be documented in this file.

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
