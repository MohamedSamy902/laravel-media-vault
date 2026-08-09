@extends('media-vault::dashboard.layout')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard Overview')

@section('content')
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-icon indigo"><i class="fas fa-file"></i></div>
        <div>
            <div class="stat-value">{{ number_format($stats['total_files']) }}</div>
            <div class="stat-label">Total Files</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-hdd"></i></div>
        <div>
            <div class="stat-value">{{ $stats['total_size'] > 0 ? round($stats['total_size'] / 1048576, 1) . ' MB' : '0 B' }}</div>
            <div class="stat-label">Total Storage Used</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-link"></i></div>
        <div>
            <div class="stat-value">{{ number_format($stats['used_files']) }}</div>
            <div class="stat-label">Used Files</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-unlink"></i></div>
        <div>
            <div class="stat-value">{{ number_format($stats['unused_files']) }}</div>
            <div class="stat-label">Unused / Orphaned</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="fas fa-image"></i></div>
        <div>
            <div class="stat-value">{{ number_format($stats['images']) }}</div>
            <div class="stat-label">Images</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon yellow"><i class="fas fa-layer-group"></i></div>
        <div>
            <div class="stat-value">{{ number_format($stats['active_sessions']) }}</div>
            <div class="stat-label">Active Upload Sessions</div>
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 8px;">
    {{-- File type breakdown --}}
    <div class="card">
        <h3 style="font-size:14px; font-weight:600; margin-bottom:20px; color: var(--text-secondary);">
            <i class="fas fa-chart-donut" style="color:var(--accent)"></i> Files by Type
        </h3>
        @php
            $typeData = [
                ['label' => 'Images',    'count' => $stats['images'],    'color' => '#8b5cf6', 'icon' => 'fa-image'],
                ['label' => 'Videos',    'count' => $stats['videos'],    'color' => '#3b82f6', 'icon' => 'fa-video'],
                ['label' => 'Documents', 'count' => $stats['documents'], 'color' => '#f59e0b', 'icon' => 'fa-file-alt'],
                ['label' => 'Other',     'count' => $stats['other'],     'color' => '#64748b', 'icon' => 'fa-file'],
            ];
            $total = max($stats['total_files'], 1);
        @endphp
        <div style="display: flex; flex-direction: column; gap: 14px;">
            @foreach($typeData as $t)
            @php $pct = round($t['count'] / $total * 100); @endphp
            <div>
                <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
                    <span style="font-size:13px; display:flex; align-items:center; gap:8px;">
                        <i class="fas {{ $t['icon'] }}" style="color: {{ $t['color'] }};"></i>
                        {{ $t['label'] }}
                    </span>
                    <span style="font-size:12px; color:var(--text-muted);">{{ $t['count'] }} ({{ $pct }}%)</span>
                </div>
                <div class="progress">
                    <div class="progress-bar" style="width: {{ $pct }}%; background: {{ $t['color'] }};"></div>
                </div>
            </div>
            @endforeach
        </div>
    </div>

    {{-- System info --}}
    <div class="card">
        <h3 style="font-size:14px; font-weight:600; margin-bottom:20px; color: var(--text-secondary);">
            <i class="fas fa-info-circle" style="color:var(--accent)"></i> System Status
        </h3>
        @php
            $checks = [
                ['label' => 'Database Tracking',       'ok' => $stats['db_enabled'],                                       'val' => $stats['db_enabled'] ? 'Enabled' : 'Disabled'],
                ['label' => 'Default Disk',            'ok' => true,                                                        'val' => $config['storage']['disk'] ?? 'public'],
                ['label' => 'CDN',                     'ok' => $config['storage']['cdn']['enabled'] ?? false,              'val' => ($config['storage']['cdn']['enabled'] ?? false) ? 'Enabled' : 'Disabled'],
                ['label' => 'Image Processing',        'ok' => $config['processing']['image']['enabled'] ?? false,         'val' => ($config['processing']['image']['enabled'] ?? false) ? 'Enabled' : 'Disabled'],
                ['label' => 'Thumbnails',              'ok' => $config['thumbnails']['enabled'] ?? false,                  'val' => ($config['thumbnails']['enabled'] ?? false) ? 'Enabled' : 'Disabled'],
                ['label' => 'Quota Management',        'ok' => $config['quota']['enabled'] ?? false,                       'val' => ($config['quota']['enabled'] ?? false) ? 'Enabled' : 'Disabled'],
                ['label' => 'Rate Limiting',           'ok' => $config['security']['rate_limit']['enabled'] ?? false,      'val' => ($config['security']['rate_limit']['enabled'] ?? false) ? 'Enabled' : 'Disabled'],
                ['label' => 'Failed Sessions',         'ok' => $stats['failed_sessions'] === 0,                            'val' => $stats['failed_sessions'] . ' session(s)'],
            ];
        @endphp
        <div style="display:flex; flex-direction:column; gap:10px;">
            @foreach($checks as $c)
            <div style="display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom: 1px solid var(--border);">
                <span style="font-size:13px; color: var(--text-secondary);">{{ $c['label'] }}</span>
                <span class="badge {{ $c['ok'] ? 'badge-used' : 'badge-unused' }}">
                    <i class="fas {{ $c['ok'] ? 'fa-check' : 'fa-times' }}"></i>
                    {{ $c['val'] }}
                </span>
            </div>
            @endforeach
        </div>
    </div>
</div>

{{-- Quick Actions --}}
<div class="card" style="margin-top: 20px;">
    <h3 style="font-size:14px; font-weight:600; margin-bottom:16px; color: var(--text-secondary);">
        <i class="fas fa-bolt" style="color:var(--accent)"></i> Quick Actions
    </h3>
    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
        <a href="{{ route('media-vault.media', ['filter' => 'unused']) }}" class="btn btn-danger">
            <i class="fas fa-broom"></i> View Unused Files ({{ $stats['unused_files'] }})
        </a>
        <button class="btn btn-ghost" onclick="runScan()">
            <i class="fas fa-search"></i> Scan for Orphans
        </button>
        <a href="{{ route('media-vault.config') }}" class="btn btn-ghost">
            <i class="fas fa-cog"></i> Edit Configuration
        </a>
        <a href="{{ route('media-vault.sessions') }}" class="btn btn-ghost">
            <i class="fas fa-layer-group"></i> View Upload Sessions
        </a>
    </div>
</div>
@endsection
