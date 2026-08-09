<!DOCTYPE html>
<html lang="en" dir="ltr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Media Manager') — Advanced File Upload</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    @stack('styles')
    <style>
        /* Top Progress Bar */
        #pjax-loader {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: var(--accent);
            z-index: 9999;
            transform-origin: left;
            transform: scaleX(0);
            transition: transform 0.3s ease;
            pointer-events: none;
        }

        #pjax-loader.loading {
            transform: scaleX(0.7);
            transition: transform 2s cubic-bezier(0.1, 0.5, 0.1, 1);
        }

        #pjax-loader.done {
            transform: scaleX(1);
            transition: transform 0.2s ease;
        }

        :root {
            --bg-primary: #0a0f1e;
            --bg-secondary: #111827;
            --bg-card: #1a2236;
            --bg-card-hover: #1e2a42;
            --border: #1f2f50;
            --border-light: #2a3f6a;
            --text-primary: #e2e8f0;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            --accent: #6366f1;
            --accent-hover: #4f46e5;
            --accent-glow: rgba(99, 102, 241, 0.25);
            --success: #10b981;
            --success-bg: rgba(16, 185, 129, 0.1);
            --warning: #f59e0b;
            --warning-bg: rgba(245, 158, 11, 0.1);
            --danger: #ef4444;
            --danger-bg: rgba(239, 68, 68, 0.1);
            --info: #3b82f6;
            --info-bg: rgba(59, 130, 246, 0.1);
            --purple: #8b5cf6;
            --purple-bg: rgba(139, 92, 246, 0.1);
            --radius: 12px;
            --radius-sm: 8px;
            --shadow: 0 4px 24px rgba(0, 0, 0, 0.4);
            --transition: all 0.2s ease;
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
        }

        /* ── Sidebar ── */
        .sidebar {
            width: 260px;
            min-height: 100vh;
            background: var(--bg-secondary);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            z-index: 100;
        }

        .sidebar-brand {
            padding: 24px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-brand .brand-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--accent), var(--purple));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            box-shadow: 0 4px 12px var(--accent-glow);
        }

        .sidebar-brand .brand-text {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .sidebar-brand .brand-sub {
            font-size: 11px;
            color: var(--text-muted);
        }

        .sidebar-nav {
            flex: 1;
            padding: 16px 12px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .nav-section-title {
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
            padding: 12px 8px 6px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-radius: var(--radius-sm);
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: var(--transition);
        }

        .nav-link:hover {
            background: var(--bg-card);
            color: var(--text-primary);
        }

        .nav-link.active {
            background: var(--accent-glow);
            color: var(--accent);
            border: 1px solid rgba(99, 102, 241, 0.3);
        }

        .nav-link i {
            width: 18px;
            text-align: center;
            font-size: 14px;
        }

        .nav-badge {
            margin-left: auto;
            background: var(--danger-bg);
            color: var(--danger);
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 20px;
        }

        .sidebar-footer {
            padding: 16px;
            border-top: 1px solid var(--border);
        }

        .sidebar-footer .version {
            font-size: 11px;
            color: var(--text-muted);
            text-align: center;
        }

        /* ── Main ── */
        .main {
            margin-left: 260px;
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .topbar {
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border);
            padding: 0 28px;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .topbar-title {
            font-size: 18px;
            font-weight: 600;
        }

        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .content {
            padding: 28px;
            flex: 1;
        }

        /* ── Cards ── */
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px;
            transition: var(--transition);
        }

        .card:hover {
            border-color: var(--border-light);
        }

        /* ── Stat Cards ── */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: var(--transition);
        }

        .stat-card:hover {
            border-color: var(--border-light);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .stat-icon.indigo {
            background: var(--accent-glow);
            color: var(--accent);
        }

        .stat-icon.green {
            background: var(--success-bg);
            color: var(--success);
        }

        .stat-icon.yellow {
            background: var(--warning-bg);
            color: var(--warning);
        }

        .stat-icon.red {
            background: var(--danger-bg);
            color: var(--danger);
        }

        .stat-icon.blue {
            background: var(--info-bg);
            color: var(--info);
        }

        .stat-icon.purple {
            background: var(--purple-bg);
            color: var(--purple);
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            line-height: 1;
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        /* ── Buttons ── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
        }

        .btn-primary {
            background: var(--accent);
            color: #fff;
        }

        .btn-primary:hover {
            background: var(--accent-hover);
            box-shadow: 0 4px 12px var(--accent-glow);
        }

        .btn-danger {
            background: var(--danger-bg);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .btn-danger:hover {
            background: var(--danger);
            color: #fff;
        }

        .btn-success {
            background: var(--success-bg);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .btn-success:hover {
            background: var(--success);
            color: #fff;
        }

        .btn-ghost {
            background: transparent;
            color: var(--text-secondary);
            border: 1px solid var(--border);
        }

        .btn-ghost:hover {
            border-color: var(--border-light);
            color: var(--text-primary);
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }

        /* ── Alerts ── */
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            margin-bottom: 16px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: var(--success-bg);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .alert-error {
            background: var(--danger-bg);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        /* ── Tables ── */
        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            padding: 12px 16px;
            text-align: left;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border);
        }

        td {
            padding: 12px 16px;
            font-size: 13px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }

        /* ── Badges ── */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-image {
            background: var(--purple-bg);
            color: var(--purple);
        }

        .badge-video {
            background: var(--info-bg);
            color: var(--info);
        }

        .badge-document {
            background: var(--warning-bg);
            color: var(--warning);
        }

        .badge-audio {
            background: var(--success-bg);
            color: var(--success);
        }

        .badge-other {
            background: rgba(100, 116, 139, 0.15);
            color: var(--text-muted);
        }

        .badge-used {
            background: var(--success-bg);
            color: var(--success);
        }

        .badge-unused {
            background: var(--danger-bg);
            color: var(--danger);
        }

        .badge-pending {
            background: var(--warning-bg);
            color: var(--warning);
        }

        .badge-complete {
            background: var(--success-bg);
            color: var(--success);
        }

        .badge-failed {
            background: var(--danger-bg);
            color: var(--danger);
        }

        /* ── Filters Bar ── */
        .filter-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-pills {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .filter-pill {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            border: 1px solid var(--border);
            color: var(--text-secondary);
            text-decoration: none;
            transition: var(--transition);
        }

        .filter-pill:hover {
            border-color: var(--accent);
            color: var(--accent);
        }

        .filter-pill.active {
            background: var(--accent-glow);
            border-color: rgba(99, 102, 241, 0.4);
            color: var(--accent);
        }

        .search-box {
            background: var(--bg-primary);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 8px 14px;
            color: var(--text-primary);
            font-size: 13px;
            font-family: inherit;
            margin-left: auto;
            min-width: 220px;
            outline: none;
        }

        .search-box:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow);
        }

        /* ── Media Grid ── */
        .media-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 16px;
        }

        .media-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            transition: var(--transition);
            position: relative;
        }

        .media-card:hover {
            border-color: var(--accent);
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
        }

        .media-thumb {
            width: 100%;
            aspect-ratio: 1;
            object-fit: cover;
            display: block;
            background: var(--bg-primary);
        }

        .media-thumb-placeholder {
            width: 100%;
            aspect-ratio: 1;
            background: var(--bg-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            color: var(--text-muted);
        }

        .media-info {
            padding: 10px 12px;
        }

        .media-name {
            font-size: 12px;
            font-weight: 500;
            color: var(--text-primary);
            truncate: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .media-meta {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 3px;
            display: flex;
            justify-content: space-between;
        }

        .media-actions {
            position: absolute;
            top: 8px;
            right: 8px;
            display: none;
            gap: 4px;
        }

        .media-card:hover .media-actions {
            display: flex;
        }

        .media-action-btn {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            transition: var(--transition);
        }

        .media-no-file {
            position: absolute;
            top: 8px;
            left: 8px;
        }

        /* ── Config form ── */
        .config-section {
            margin-bottom: 28px;
        }

        .config-section-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--accent);
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border);
        }

        .config-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 16px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 500;
            color: var(--text-secondary);
        }

        .form-control {
            background: var(--bg-primary);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 9px 14px;
            color: var(--text-primary);
            font-size: 13px;
            font-family: inherit;
            outline: none;
            transition: var(--transition);
        }

        .form-control:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow);
        }

        .form-hint {
            font-size: 11px;
            color: var(--text-muted);
        }

        .toggle-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .toggle {
            position: relative;
            width: 44px;
            height: 24px;
            cursor: pointer;
        }

        .toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            inset: 0;
            background: var(--border);
            border-radius: 24px;
            transition: var(--transition);
        }

        .toggle-slider::before {
            content: '';
            position: absolute;
            width: 18px;
            height: 18px;
            background: #fff;
            border-radius: 50%;
            left: 3px;
            top: 3px;
            transition: var(--transition);
        }

        .toggle input:checked+.toggle-slider {
            background: var(--accent);
        }

        .toggle input:checked+.toggle-slider::before {
            transform: translateX(20px);
        }

        /* ── Progress bar ── */
        .progress {
            height: 8px;
            background: var(--bg-primary);
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--accent), var(--purple));
            border-radius: 4px;
            transition: width 0.6s ease;
        }

        /* Removed toast styles since we use SweetAlert2 */

        /* ── Pagination ── */
        .pagination {
            display: flex;
            gap: 6px;
            justify-content: center;
            margin-top: 24px;
        }

        .pagination a,
        .pagination span {
            padding: 8px 14px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            text-decoration: none;
            transition: var(--transition);
        }

        .pagination .page-link {
            background: var(--bg-card);
            border: 1px solid var(--border);
            color: var(--text-secondary);
        }

        .pagination .page-link:hover {
            border-color: var(--accent);
            color: var(--accent);
        }

        .pagination .page-active {
            background: var(--accent);
            color: #fff;
            border: 1px solid var(--accent);
        }

        .pagination .page-disabled {
            background: var(--bg-card);
            border: 1px solid var(--border);
            color: var(--text-muted);
            pointer-events: none;
        }
    </style>
    @stack('styles')
</head>

<body>
    <div id="pjax-loader"></div>

    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="brand-icon">📁</div>
            <div>
                <div class="brand-text">Media Manager</div>
                <div class="brand-sub">Advanced File Upload</div>
            </div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-section-title">Overview</div>
            <a href="{{ route('media-vault.index') }}"
                class="nav-link {{ request()->routeIs('media-vault.index') ? 'active' : '' }}">
                <i class="fas fa-chart-pie"></i> Dashboard
            </a>

            <div class="nav-section-title">Files</div>
            <a href="{{ route('media-vault.media') }}"
                class="nav-link {{ request()->routeIs('media-vault.media') ? 'active' : '' }}">
                <i class="fas fa-images"></i> Media Library
            </a>
            <a href="{{ route('media-vault.media', ['filter' => 'unused']) }}"
                class="nav-link {{ request()->input('filter') === 'unused' ? 'active' : '' }}">
                <i class="fas fa-unlink"></i> Unused Files
            </a>
            <a href="{{ route('media-vault.media', ['filter' => 'deleted']) }}"
                class="nav-link {{ request()->input('filter') === 'deleted' ? 'active' : '' }}">
                <i class="fas fa-trash"></i> Deleted Files
            </a>

            <div class="nav-section-title">System</div>
            <a href="{{ route('media-vault.sessions') }}"
                class="nav-link {{ request()->routeIs('media-vault.sessions') ? 'active' : '' }}">
                <i class="fas fa-layer-group"></i> Upload Sessions
            </a>
            <a href="{{ route('media-vault.config') }}"
                class="nav-link {{ request()->routeIs('media-vault.config') ? 'active' : '' }}">
                <i class="fas fa-sliders-h"></i> Configuration
            </a>
        </nav>
        <div class="sidebar-footer">
            <div style="display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 6px;">
                <i class="fas fa-code" style="color: var(--accent); font-size: 14px;"></i>
                <span style="font-size: 12px; font-weight: 600; color: var(--text-primary);">Mohamed Samy</span>
            </div>
            <div class="version" style="font-size: 10px; opacity: 0.7;">Advanced File Upload v1.0.0</div>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <h1 class="topbar-title">@yield('page-title', 'Dashboard')</h1>
            <div class="topbar-actions">
                <button class="btn btn-ghost btn-sm" onclick="runScan()">
                    <i class="fas fa-search"></i> Scan Orphans
                </button>
                @yield('topbar-actions')
            </div>
        </header>
        <main class="content">
            <div id="pjax-container">
                @yield('content')
            </div>
        </main>
    </div>

    <script>
        const CSRF = document.querySelector('meta[name="csrf-token"]').content;

        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            background: 'var(--bg-card)',
            color: 'var(--text-primary)',
            customClass: {
                popup: 'swal-dark-toast'
            }
        });

        function toast(message, type = 'success') {
            Toast.fire({
                icon: type,
                title: message
            });
        }

        function runScan() {
            Swal.fire({
                title: 'Scanning...',
                text: 'Checking database vs physical storage...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading(),
                background: 'var(--bg-card)',
                color: 'var(--text-primary)'
            });

            fetch('{{ route('media-vault.scan') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': CSRF,
                        'Accept': 'application/json'
                    }
                })
                .then(r => r.json())
                .then(d => {
                    Swal.fire({
                        icon: d.orphaned_count > 0 ? 'warning' : 'success',
                        title: 'Scan Complete',
                        text: `Scanned ${d.checked} files. Found ${d.orphaned_count} orphaned DB records.`,
                        background: 'var(--bg-card)',
                        color: 'var(--text-primary)'
                    });
                })
                .catch(() => toast('Scan failed', 'error'));
        }

        function deleteFile(id, force = false) {
            Swal.fire({
                title: force ? 'Permanently Delete?' : 'Move to Trash?',
                text: force ? "This will delete the file from disk. You won't be able to revert this!" :
                    "You can restore this file later.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: 'var(--danger)',
                cancelButtonColor: 'var(--text-muted)',
                confirmButtonText: 'Yes, delete it!',
                background: 'var(--bg-card)',
                color: 'var(--text-primary)'
            }).then((result) => {
                if (result.isConfirmed) {
                    const url = force ?
                        `/media-vault/media/${id}/force` :
                        `/media-vault/media/${id}`;
                    fetch(url, {
                            method: 'DELETE',
                            headers: {
                                'X-CSRF-TOKEN': CSRF,
                                'Accept': 'application/json'
                            }
                        })
                        .then(r => r.json())
                        .then(d => {
                            toast(d.message, d.status ? 'success' : 'error');
                            if (d.status) {
                                document.getElementById(`file-${id}`)?.remove();
                            }
                        })
                        .catch(() => toast('Delete failed', 'error'));
                }
            });
        }

        function restoreFile(id) {
            fetch(`/media-vault/media/${id}/restore`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': CSRF,
                        'Accept': 'application/json'
                    }
                })
                .then(r => r.json())
                .then(d => {
                    toast(d.message, d.status ? 'success' : 'error');
                    if (d.status) {
                        location.reload();
                    }
                })
                .catch(() => toast('Restore failed', 'error'));
        }

        // -------------------------------------------------------------------------
        // SPA / PJAX Navigation Logic
        // -------------------------------------------------------------------------
        document.addEventListener('DOMContentLoaded', () => {
            // Intercept clicks on links
            document.body.addEventListener('click', function(e) {
                const link = e.target.closest('a');
                if (link && link.href && !link.hasAttribute('download') && link.target !== '_blank') {
                    // Check if it's the same origin and part of our dashboard
                    const url = new URL(link.href);
                    if (url.origin === window.location.origin && url.pathname.startsWith(
                            '/media-vault')) {
                        e.preventDefault();
                        loadPage(link.href);

                        // Update sidebar active state
                        if (link.closest('.sidebar-nav')) {
                            document.querySelectorAll('.sidebar-nav a').forEach(a => a.classList.remove(
                                'active'));
                            link.classList.add('active');
                        }
                    }
                }
            });

            // Intercept PJAX forms (like search)
            document.body.addEventListener('submit', function(e) {
                if (e.target.closest('form.pjax-form')) {
                    e.preventDefault();
                    const form = e.target.closest('form');
                    const url = new URL(form.action);
                    const formData = new FormData(form);
                    const search = new URLSearchParams(formData);
                    url.search = search.toString();
                    loadPage(url.toString());
                }
            });

            window.addEventListener('popstate', () => {
                loadPage(window.location.href, false);
            });
        });

        function loadPage(url, pushState = true) {
            // Save focus state
            const activeElement = document.activeElement;
            const activeId = activeElement ? activeElement.id : null;
            let selectionStart = null;
            let selectionEnd = null;
            if (activeElement && activeElement.tagName === 'INPUT') {
                selectionStart = activeElement.selectionStart;
                selectionEnd = activeElement.selectionEnd;
            }

            const loader = document.getElementById('pjax-loader');
            loader.className = 'loading';

            fetch(url, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest', // Laravel detects this as AJAX
                        'Accept': 'text/html'
                    }
                })
                .then(res => {
                    if (!res.ok) throw new Error(`HTTP ${res.status}`);
                    return res.text();
                })
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const newContent = doc.getElementById('pjax-container');

                    if (newContent) {
                        document.getElementById('pjax-container').innerHTML = newContent.innerHTML;
                        if (pushState) window.history.pushState({}, '', url);

                        // Re-initialize specific scripts if they exist
                        if (typeof initMediaEvents === 'function') {
                            initMediaEvents();
                        }
                        if (typeof initAutoSubmit === 'function') {
                            initAutoSubmit();
                        }

                        loader.className = 'done';
                        setTimeout(() => {
                            loader.className = '';
                        }, 300);

                        // Restore focus state
                        if (activeId) {
                            const el = document.getElementById(activeId);
                            if (el) {
                                el.focus();
                                if (selectionStart !== null && el.tagName === 'INPUT') {
                                    el.setSelectionRange(selectionStart, selectionEnd);
                                }
                            }
                        }
                    } else {
                        window.location.href = url;
                    }
                })
                .catch(err => {
                    loader.className = '';
                    Swal.fire({
                        icon: 'error',
                        title: 'Oops...',
                        text: 'Something went wrong loading the page! ' + err.message,
                        background: 'var(--bg-card)',
                        color: 'var(--text-primary)'
                    });
                });
        }
    </script>
    @stack('scripts')
</body>

</html>
