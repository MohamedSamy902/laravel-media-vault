@extends('media-vault::dashboard.layout')

@section('title', 'Configuration')
@section('page-title', 'Configuration Manager')

@section('content')

@if(!$isPublished)
<div class="alert alert-error">
    <i class="fas fa-exclamation-triangle"></i>
    Config not published yet. Run <code style="background:rgba(0,0,0,0.3);padding:2px 6px;border-radius:4px;">php artisan vendor:publish --tag=config</code> first.
    Changes here will update your <code>.env</code> file directly.
</div>
@endif

<form action="{{ route('media-vault.config.save') }}" method="POST">
    @csrf

    {{-- Storage --}}
    <div class="card config-section" style="margin-bottom:20px;">
        <div class="config-section-title"><i class="fas fa-database"></i> Storage</div>
        <div class="config-grid">
            <div class="form-group">
                <label class="form-label">Default Disk <code>FILE_UPLOAD_DISK</code></label>
                <select name="FILE_UPLOAD_DISK" class="form-control">
                    @foreach(array_keys(config('filesystems.disks', [])) as $d)
                        <option value="{{ $d }}" {{ ($config['storage']['disk'] ?? 'public') === $d ? 'selected' : '' }}>{{ $d }}</option>
                    @endforeach
                </select>
                <span class="form-hint">Which Laravel disk to store uploaded files on.</span>
            </div>
            <div class="form-group">
                <label class="form-label">Upload Path <code>FILE_UPLOAD_PATH</code></label>
                <input type="text" name="FILE_UPLOAD_PATH" class="form-control"
                       value="{{ $config['storage']['path'] ?? 'uploads' }}" placeholder="uploads">
                <span class="form-hint">Base directory inside the disk.</span>
            </div>
        </div>
    </div>

    {{-- CDN --}}
    <div class="card config-section" style="margin-bottom:20px;">
        <div class="config-section-title"><i class="fas fa-globe"></i> CDN</div>
        <div class="config-grid">
            <div class="form-group">
                <label class="form-label">CDN Enabled <code>FILE_UPLOAD_CDN_ENABLED</code></label>
                <div class="toggle-wrap">
                    <label class="toggle">
                        <input type="checkbox" name="FILE_UPLOAD_CDN_ENABLED" value="1"
                               {{ ($config['storage']['cdn']['enabled'] ?? false) ? 'checked' : '' }}>
                        <span class="toggle-slider"></span>
                    </label>
                    <span style="font-size:13px;color:var(--text-secondary);">Rewrite file URLs to CDN domain</span>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">CDN URL <code>FILE_UPLOAD_CDN_URL</code></label>
                <input type="url" name="FILE_UPLOAD_CDN_URL" class="form-control"
                       value="{{ $config['storage']['cdn']['url'] ?? '' }}" placeholder="https://cdn.example.com">
            </div>
        </div>
    </div>

    {{-- Database --}}
    <div class="card config-section" style="margin-bottom:20px;">
        <div class="config-section-title"><i class="fas fa-table"></i> Database Tracking</div>
        <div class="config-grid">
            <div class="form-group">
                <label class="form-label">DB Tracking Enabled <code>FILE_UPLOAD_DB_ENABLED</code></label>
                <div class="toggle-wrap">
                    <label class="toggle">
                        <input type="checkbox" name="FILE_UPLOAD_DB_ENABLED" value="1"
                               {{ ($config['database']['enabled'] ?? true) ? 'checked' : '' }}>
                        <span class="toggle-slider"></span>
                    </label>
                    <span style="font-size:13px;color:var(--text-secondary);">Store a DB record for every uploaded file</span>
                </div>
            </div>
        </div>
        <div style="margin-top:16px; padding:12px; background: var(--bg-primary); border-radius:8px;">
            <div style="font-size:12px; color:var(--text-muted); margin-bottom:8px;">Current Config Values (read-only)</div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; font-size:12px;">
                <div><span style="color:var(--text-muted);">Model:</span> <code style="color:var(--accent);">{{ class_basename($config['database']['model'] ?? '') }}</code></div>
                <div><span style="color:var(--text-muted);">Table:</span> <code style="color:var(--accent);">{{ $config['database']['table'] ?? 'file_uploads' }}</code></div>
                <div><span style="color:var(--text-muted);">Prune After:</span> <code style="color:var(--accent);">{{ $config['database']['prune_after'] ?? 'never' }} days</code></div>
            </div>
        </div>
    </div>

    {{-- Image Processing --}}
    <div class="card config-section" style="margin-bottom:20px;">
        <div class="config-section-title"><i class="fas fa-magic"></i> Image Processing</div>
        <div class="config-grid">
            <div class="form-group">
                <label class="form-label">Image Driver <code>FILE_UPLOAD_IMAGE_DRIVER</code></label>
                <select name="FILE_UPLOAD_IMAGE_DRIVER" class="form-control">
                    <option value="gd" {{ ($config['image_driver'] ?? 'gd') === 'gd' ? 'selected' : '' }}>GD (default)</option>
                    <option value="imagick" {{ ($config['image_driver'] ?? 'gd') === 'imagick' ? 'selected' : '' }}>Imagick</option>
                </select>
                <span class="form-hint">Intervention/Image driver. Imagick = better quality.</span>
            </div>
        </div>

        <div style="margin-top:16px; padding:12px; background: var(--bg-primary); border-radius:8px;">
            <div style="font-size:12px; color:var(--text-muted); margin-bottom:8px;">Processing Config (edit in config/media-vault.php)</div>
            <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:8px; font-size:12px;">
                <div><span style="color:var(--text-muted);">Enabled:</span> <code style="color:{{ ($config['processing']['image']['enabled'] ?? false) ? 'var(--success)' : 'var(--danger)' }};">{{ ($config['processing']['image']['enabled'] ?? false) ? 'Yes' : 'No' }}</code></div>
                <div><span style="color:var(--text-muted);">Convert To:</span> <code style="color:var(--accent);">{{ $config['processing']['image']['convert_to'] ?? 'original' }}</code></div>
                <div><span style="color:var(--text-muted);">Quality:</span> <code style="color:var(--accent);">{{ $config['processing']['image']['quality'] ?? 85 }}%</code></div>
                <div><span style="color:var(--text-muted);">Max Width:</span> <code style="color:var(--accent);">{{ $config['processing']['image']['resize']['width'] ?? '-' }}px</code></div>
                <div><span style="color:var(--text-muted);">Watermark:</span> <code style="color:{{ ($config['processing']['image']['watermark']['enabled'] ?? false) ? 'var(--success)' : 'var(--text-muted)' }};">{{ ($config['processing']['image']['watermark']['enabled'] ?? false) ? 'Enabled' : 'Off' }}</code></div>
                <div><span style="color:var(--text-muted);">Thumbnails:</span> <code style="color:{{ ($config['thumbnails']['enabled'] ?? false) ? 'var(--success)' : 'var(--text-muted)' }};">{{ ($config['thumbnails']['enabled'] ?? false) ? count($config['thumbnails']['sizes'] ?? []) . ' sizes' : 'Off' }}</code></div>
            </div>
        </div>
    </div>

    {{-- URL Upload --}}
    <div class="card config-section" style="margin-bottom:20px;">
        <div class="config-section-title"><i class="fas fa-cloud-download-alt"></i> URL Download</div>
        <div class="config-grid">
            <div class="form-group">
                <label class="form-label">Timeout (seconds) <code>FILE_UPLOAD_URL_TIMEOUT</code></label>
                <input type="number" name="FILE_UPLOAD_URL_TIMEOUT" class="form-control"
                       value="{{ $config['url_upload']['timeout_seconds'] ?? 10 }}" min="1" max="600">
            </div>
            <div class="form-group">
                <label class="form-label">Max File Size (bytes) <code>FILE_UPLOAD_URL_MAX_SIZE</code></label>
                <input type="number" name="FILE_UPLOAD_URL_MAX_SIZE" class="form-control"
                       value="{{ $config['url_upload']['max_size_bytes'] ?? 52428800 }}" min="1048576">
                <span class="form-hint">Current: {{ round(($config['url_upload']['max_size_bytes'] ?? 52428800) / 1048576, 1) }} MB</span>
            </div>
        </div>
    </div>

    {{-- Quota --}}
    <div class="card config-section" style="margin-bottom:20px;">
        <div class="config-section-title"><i class="fas fa-tachometer-alt"></i> Quota Management</div>
        <div class="config-grid">
            <div class="form-group">
                <label class="form-label">Quota Enabled <code>FILE_UPLOAD_QUOTA_ENABLED</code></label>
                <div class="toggle-wrap">
                    <label class="toggle">
                        <input type="checkbox" name="FILE_UPLOAD_QUOTA_ENABLED" value="1"
                               {{ ($config['quota']['enabled'] ?? false) ? 'checked' : '' }}>
                        <span class="toggle-slider"></span>
                    </label>
                    <span style="font-size:13px;color:var(--text-secondary);">Enforce per-user storage limits</span>
                </div>
            </div>
        </div>
        <div style="margin-top:16px; padding:12px; background: var(--bg-primary); border-radius:8px; font-size:12px;">
            <span style="color:var(--text-muted);">Max per user:</span>
            <code style="color:var(--accent);">{{ round(($config['quota']['max_size_per_user'] ?? 1073741824) / 1073741824, 1) }} GB</code>
            &nbsp;&nbsp;
            <span style="color:var(--text-muted);">Key column:</span>
            <code style="color:var(--accent);">{{ $config['quota']['key_column'] ?? 'user_id' }}</code>
            &nbsp;&nbsp;
            <span style="color:var(--text-muted);">Warning at:</span>
            <code style="color:var(--accent);">{{ (($config['quota']['warning_threshold'] ?? 0.9) * 100) }}%</code>
        </div>
    </div>

    {{-- Chunk Uploads --}}
    <div class="card config-section" style="margin-bottom:20px;">
        <div class="config-section-title"><i class="fas fa-layer-group"></i> Chunk Upload Limits</div>
        <div class="config-grid">
            <div class="form-group">
                <label class="form-label">Max File Size (bytes) <code>FILE_UPLOAD_MAX_SIZE</code></label>
                <input type="number" name="FILE_UPLOAD_MAX_SIZE" class="form-control"
                       value="{{ $config['security']['max_size_bytes'] ?? 2147483648 }}" min="1048576">
                <span class="form-hint">Current: {{ round(($config['security']['max_size_bytes'] ?? 2147483648) / 1073741824, 1) }} GB</span>
            </div>
            <div class="form-group">
                <label class="form-label">Default Chunk Size (bytes) <code>FILE_UPLOAD_CHUNK_SIZE</code></label>
                <input type="number" name="FILE_UPLOAD_CHUNK_SIZE" class="form-control"
                       value="{{ $config['chunking']['chunk_size_bytes'] ?? 2097152 }}" min="1048576">
                <span class="form-hint">Current: {{ round(($config['chunking']['chunk_size_bytes'] ?? 2097152) / 1048576, 1) }} MB</span>
            </div>
        </div>
    </div>

    {{-- Clean up (Pruning) --}}
    <div class="card config-section" style="margin-bottom:20px;">
        <div class="config-section-title"><i class="fas fa-broom"></i> Automatic Cleanup</div>
        <div class="config-grid">
             <div class="form-group">
                <label class="form-label">Prune Unused After (Days) <code>FILE_UPLOAD_PRUNE_DAYS</code></label>
                <input type="number" name="FILE_UPLOAD_PRUNE_DAYS" class="form-control"
                       value="{{ $config['database']['prune_after'] ?? 30 }}" min="1">
                <span class="form-hint">Requires setting up Laravel Task Scheduler.</span>
            </div>
        </div>
    </div>

    <div style="display:flex; justify-content:flex-end; gap:12px;">
        <a href="{{ route('media-vault.index') }}" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Save to .env
        </button>
    </div>
</form>
@endsection
