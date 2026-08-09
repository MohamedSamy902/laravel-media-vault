@extends('media-vault::dashboard.layout')

@section('title', 'Media Library')
@section('page-title', 'Media Library')

@section('content')
@if(isset($stats) && !empty($stats))
<div class="stats-grid" style="margin-bottom: 30px; display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px;">
    <!-- Card 1 -->
    <div style="background: linear-gradient(145deg, var(--bg-card), var(--bg-surface)); border: 1px solid var(--border); border-radius: 16px; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); display: flex; align-items: center; gap: 16px;">
        <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(99, 102, 241, 0.1); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 20px;">
            <i class="fas fa-folder-open"></i>
        </div>
        <div>
            <div style="font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Filtered Files</div>
            <div style="font-size: 24px; font-weight: 700; color: var(--text-primary); line-height: 1.2;">{{ number_format($stats['total']) }}</div>
        </div>
    </div>
    <!-- Card 2 -->
    <div style="background: linear-gradient(145deg, var(--bg-card), var(--bg-surface)); border: 1px solid var(--border); border-radius: 16px; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); display: flex; align-items: center; gap: 16px;">
        <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(16, 185, 129, 0.1); color: var(--success); display: flex; align-items: center; justify-content: center; font-size: 20px;">
            <i class="fas fa-hdd"></i>
        </div>
        <div>
            <div style="font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Filtered Size</div>
            <div style="font-size: 24px; font-weight: 700; color: var(--text-primary); line-height: 1.2;">{{ $stats['size'] }}</div>
        </div>
    </div>
    <!-- Card 3 -->
    <div style="background: linear-gradient(145deg, var(--bg-card), var(--bg-surface)); border: 1px solid var(--border); border-radius: 16px; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); display: flex; align-items: center; gap: 16px;">
        <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(245, 158, 11, 0.1); color: var(--warning); display: flex; align-items: center; justify-content: center; font-size: 20px;">
            <i class="fas fa-link"></i>
        </div>
        <div>
            <div style="font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Used</div>
            <div style="font-size: 24px; font-weight: 700; color: var(--text-primary); line-height: 1.2;">{{ number_format($stats['used']) }}</div>
        </div>
    </div>
    <!-- Card 4 -->
    <div style="background: linear-gradient(145deg, var(--bg-card), var(--bg-surface)); border: 1px solid var(--border); border-radius: 16px; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); display: flex; align-items: center; gap: 16px;">
        <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(239, 68, 68, 0.1); color: var(--danger); display: flex; align-items: center; justify-content: center; font-size: 20px;">
            <i class="fas fa-unlink"></i>
        </div>
        <div>
            <div style="font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Unused</div>
            <div style="font-size: 24px; font-weight: 700; color: var(--text-primary); line-height: 1.2;">{{ number_format($stats['unused']) }}</div>
        </div>
    </div>
</div>
@endif

<div class="filter-bar" style="flex-wrap: wrap; gap: 12px;">
    <div class="filter-pills" style="flex: 1; min-width: 300px;">
        @foreach(['all' => 'All', 'images' => 'Images', 'videos' => 'Videos', 'documents' => 'Documents', 'used' => 'Used', 'unused' => 'Unused', 'deleted' => 'Deleted'] as $key => $label)
            <a href="{{ route('media-vault.media', ['filter' => $key, 'model_type' => $modelFilter ?? '']) }}"
               class="filter-pill {{ $filter === $key ? 'active' : '' }} pjax-link">
                {{ $label }}
            </a>
        @endforeach
    </div>
    <form id="media-filter-form" method="GET" action="{{ route('media-vault.media') }}" class="pjax-form" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
        <input type="hidden" name="filter" value="{{ $filter }}">
        
        @if(isset($models) && count($models) > 0)
        <div style="position: relative;">
            <select name="model_type" class="premium-select" onchange="document.getElementById('media-filter-form').dispatchEvent(new Event('submit', {cancelable: true, bubbles: true}))">
                <option value="">All Models</option>
                @foreach($models as $model)
                    <option value="{{ $model }}" {{ ($modelFilter ?? '') === $model ? 'selected' : '' }}>
                        {{ class_basename($model) }}
                    </option>
                @endforeach
            </select>
            <i class="fas fa-chevron-down" style="position: absolute; right: 14px; top: 50%; transform: translateY(-50%); pointer-events: none; color: var(--text-muted); font-size: 12px;"></i>
        </div>
        @endif

        <div style="position: relative; flex: 1; min-width: 200px; max-width: 300px;">
            <i class="fas fa-search" style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 14px;"></i>
            <input type="text" name="search" id="search-input" value="{{ $search }}" placeholder="Search by name…" class="premium-search">
        </div>
    </form>
</div>

@push('styles')
<style>
.premium-select {
    appearance: none;
    background: var(--bg-card);
    border: 1px solid var(--border);
    color: var(--text-primary);
    padding: 10px 40px 10px 16px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 2px 8px rgba(0,0,0,0.02);
}
.premium-select:hover, .premium-select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    outline: none;
}
.premium-search {
    width: 100%;
    background: var(--bg-card);
    border: 1px solid var(--border);
    color: var(--text-primary);
    padding: 10px 16px 10px 40px;
    border-radius: 20px;
    font-size: 14px;
    transition: all 0.2s ease;
    box-shadow: 0 2px 8px rgba(0,0,0,0.02);
}
.premium-search:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    outline: none;
}
</style>
@endpush

{{-- Bulk Action Bar (Hidden by default) --}}
<div id="bulk-action-bar" style="display: none; background: var(--bg-card); border: 1px solid var(--accent); border-radius: var(--radius); padding: 12px 20px; margin-bottom: 20px; align-items: center; justify-content: space-between; box-shadow: 0 4px 12px var(--accent-glow);">
    <div style="display: flex; align-items: center;">
        <label class="premium-checkbox-wrapper" style="margin-right: 12px; margin-bottom: 0;">
            <input type="checkbox" id="select-all">
            <span class="premium-checkmark"></span>
        </label>
        <label for="select-all" style="font-size: 14px; font-weight: 600; cursor:pointer;"><span id="selected-count">0</span> files selected</label>
    </div>
    <div style="display: flex; gap: 8px;">
        <button onclick="bulkDelete(false)" class="btn btn-danger btn-sm" style="background:var(--warning-bg); color:var(--warning); border-color:var(--warning);"><i class="fas fa-trash"></i> Move to Trash</button>
        <button onclick="bulkDelete(true)" class="btn btn-danger btn-sm"><i class="fas fa-fire"></i> Permanently Delete</button>
    </div>
</div>

@push('styles')
<style>
.premium-checkbox-wrapper {
    display: inline-block;
    position: relative;
    cursor: pointer;
    user-select: none;
    width: 20px;
    height: 20px;
}
.premium-checkbox-wrapper input {
    position: absolute;
    opacity: 0;
    cursor: pointer;
    height: 0;
    width: 0;
}
.premium-checkmark {
    position: absolute;
    top: 0;
    left: 0;
    height: 20px;
    width: 20px;
    background-color: rgba(255, 255, 255, 0.9);
    border-radius: 6px;
    border: 2px solid var(--border);
    transition: all 0.2s ease;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.premium-checkbox-wrapper:hover input ~ .premium-checkmark {
    border-color: var(--primary);
}
.premium-checkbox-wrapper input:checked ~ .premium-checkmark {
    background-color: var(--primary);
    border-color: var(--primary);
}
.premium-checkmark:after {
    content: "";
    position: absolute;
    display: none;
}
.premium-checkbox-wrapper input:checked ~ .premium-checkmark:after {
    display: block;
}
.premium-checkbox-wrapper .premium-checkmark:after {
    left: 6px;
    top: 2px;
    width: 5px;
    height: 10px;
    border: solid white;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}
</style>
@endpush

@if($files->isEmpty())
    <div class="card" style="text-align:center; padding: 60px 24px;">
        <div style="font-size:48px; margin-bottom:16px; opacity:.4;">📂</div>
        <div style="font-size:16px; font-weight:600; margin-bottom:8px;">No files found</div>
        <div style="font-size:13px; color:var(--text-muted);">
            @if(!$dbEnabled)
                Database tracking is disabled. Enable it in configuration to manage files here.
            @else
                Try a different filter or search term.
            @endif
        </div>
    </div>
@else
    <div class="media-grid">
        @foreach($files as $file)
        <div class="media-card" id="file-{{ $file->encoded_path }}">
            {{-- Checkbox for bulk select --}}
            <label class="premium-checkbox-wrapper" style="position: absolute; top: 12px; left: 12px; z-index: 10;">
                <input type="checkbox" class="file-checkbox" value="{{ $file->encoded_path }}">
                <span class="premium-checkmark"></span>
            </label>

            {{-- Thumb --}}
            @if($file->type === 'image' && ($file->disk_exists ?? true))
                <img src="{{ $file->url }}" alt="{{ $file->original_name }}" class="media-thumb"
                     onerror="this.parentElement.querySelector('.media-thumb-placeholder').style.display='flex'; this.style.display='none';">
                <div class="media-thumb-placeholder" style="display:none;">🖼️</div>
            @else
                <div class="media-thumb-placeholder">
                    @switch($file->type)
                        @case('video')    🎬 @break
                        @case('audio')    🎵 @break
                        @case('document') 📄 @break
                        @default          📁
                    @endswitch
                </div>
            @endif

            {{-- Actions overlay --}}
            <div class="media-actions">
                @if($file->trashed())
                    <button class="media-action-btn" title="Restore"
                            onclick="restoreFile('{{ $file->encoded_path }}')"
                            style="background:rgba(16,185,129,0.9); color:#fff;">
                        <i class="fas fa-undo"></i>
                    </button>
                    <button class="media-action-btn" title="Delete Forever"
                            onclick="deleteFile('{{ $file->encoded_path }}', true)"
                            style="background:rgba(239,68,68,0.9); color:#fff;">
                        <i class="fas fa-fire"></i>
                    </button>
                @else
                    <a href="{{ $file->url }}" target="_blank"
                       class="media-action-btn" title="Open"
                       style="background:rgba(99,102,241,0.9); color:#fff; text-decoration:none;">
                        <i class="fas fa-external-link-alt"></i>
                    </a>
                    <button class="media-action-btn" title="Delete"
                            onclick="deleteFile('{{ $file->encoded_path }}')"
                            style="background:rgba(239,68,68,0.9); color:#fff;">
                        <i class="fas fa-trash"></i>
                    </button>
                @endif
            </div>

            {{-- No-file warning --}}
            @if(!($file->disk_exists ?? true))
                <div class="media-no-file">
                    <span class="badge" style="background:rgba(239,68,68,0.8);color:#fff;font-size:10px;">
                        <i class="fas fa-exclamation-triangle"></i> Missing
                    </span>
                </div>
            @endif

            {{-- Info --}}
            <div class="media-info">
                <div class="media-name" title="{{ $file->original_name }}">{{ $file->original_name }}</div>
                <div class="media-meta">
                    <span class="badge badge-{{ $file->type }}">{{ $file->type }}</span>
                    <span>{{ $file->human_size }}</span>
                </div>
                
                @if(isset($file->metadata['is_thumbnail_guess']) && $file->metadata['is_thumbnail_guess'])
                    <div style="margin-top:4px;">
                        <span class="badge" style="background:var(--info-bg);color:var(--info);font-size:10px;display:inline-block;" title="Presumed parent: {{ $file->metadata['guessed_parent'] }}">
                            <i class="fas fa-compress-arrows-alt"></i> Thumbnail ({{ $file->metadata['guessed_size'] }})
                        </span>
                    </div>
                @endif
                <div style="margin-top:6px; display:flex; gap: 4px; flex-wrap:wrap;">
                    @if($file->trashed())
                        <span class="badge" style="background:var(--danger-bg);color:var(--danger);font-size:10px;">
                            <i class="fas fa-trash"></i> Deleted
                        </span>
                    @elseif($file->is_used)
                        @if($file->model_id && !$file->owner_exists)
                            <span class="badge" style="background:var(--warning-bg);color:var(--warning);font-size:10px;" title="Linked model no longer exists or is trashed">
                                <i class="fas fa-ghost"></i> Owner Deleted
                            </span>
                        @else
                            <span class="badge badge-used" style="font-size:10px;"><i class="fas fa-link"></i> Used</span>
                        @endif
                    @else
                        <span class="badge badge-unused" style="font-size:10px;"><i class="fas fa-unlink"></i> Unused</span>
                    @endif

                    @if(!empty($file->metadata['thumbnails']))
                        <span class="badge" style="background:var(--info-bg);color:var(--info);font-size:10px;" title="Has generated thumbnails">
                            <i class="fas fa-compress"></i> {{ count($file->metadata['thumbnails']) }} Thumbs
                        </span>
                    @endif
                </div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- Pagination --}}
    @if($files->hasPages())
    <div class="pagination">
        @if($files->onFirstPage())
            <span class="page-disabled">← Prev</span>
        @else
            <a href="{{ $files->previousPageUrl() }}" class="page-link">← Prev</a>
        @endif

        @foreach($files->getUrlRange(max(1,$files->currentPage()-2), min($files->lastPage(),$files->currentPage()+2)) as $page => $url)
            @if($page == $files->currentPage())
                <span class="page-active">{{ $page }}</span>
            @else
                <a href="{{ $url }}" class="page-link">{{ $page }}</a>
            @endif
        @endforeach

        @if($files->hasMorePages())
            <a href="{{ $files->nextPageUrl() }}" class="page-link">Next →</a>
        @else
            <span class="page-disabled">Next →</span>
        @endif
    </div>
    @endif
@endif
@endsection

@push('scripts')
<script>
    function initMediaEvents() {
        const checkboxes = document.querySelectorAll('.file-checkbox');
        const selectAll = document.getElementById('select-all');
        const bulkBar = document.getElementById('bulk-action-bar');
        const selectedCount = document.getElementById('selected-count');

        function updateBulkBar() {
            const checked = document.querySelectorAll('.file-checkbox:checked').length;
            if(selectedCount) selectedCount.innerText = checked;
            if(bulkBar) bulkBar.style.display = checked > 0 ? 'flex' : 'none';
            if(selectAll) selectAll.checked = checked === checkboxes.length && checked > 0;
        }

        checkboxes.forEach(cb => {
            // Remove old listener to avoid duplicates on PJAX load
            cb.removeEventListener('change', updateBulkBar);
            cb.addEventListener('change', updateBulkBar);
        });

        if(selectAll) {
            const newSelectAll = selectAll.cloneNode(true);
            selectAll.parentNode.replaceChild(newSelectAll, selectAll);
            newSelectAll.addEventListener('change', function() {
                document.querySelectorAll('.file-checkbox').forEach(cb => cb.checked = newSelectAll.checked);
                updateBulkBar();
            });
        }
    }

    function initAutoSubmit() {
        const searchInput = document.getElementById('search-input');
        if (!searchInput) return;
        
        let timeout = null;
        
        // Remove old listener if exists
        const newSearchInput = searchInput.cloneNode(true);
        searchInput.parentNode.replaceChild(newSearchInput, searchInput);

        newSearchInput.addEventListener('input', function() {
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                document.getElementById('media-filter-form').dispatchEvent(new Event('submit', {cancelable: true, bubbles: true}));
            }, 500); // 500ms debounce
        });
    }

    // Call on first load
    initMediaEvents();
    initAutoSubmit();

    window.bulkDelete = function(force) {
        const ids = Array.from(document.querySelectorAll('.file-checkbox:checked')).map(cb => cb.value);
        if (ids.length === 0) return;

        // Fetch preview
        fetch('{{ route("media-vault.media.bulk-destroy-preview") }}', {
            method: 'POST',
            headers: { 
                'X-CSRF-TOKEN': CSRF, 
                'Content-Type': 'application/json',
                'Accept': 'application/json' 
            },
            body: JSON.stringify({ ids: ids })
        })
        .then(r => r.json())
        .then(data => {
            if (!data.status || !data.preview) {
                return toast('Preview failed', 'error');
            }
            
            const p = data.preview;
            if (p.count === 0) return toast('No valid files selected', 'warning');

            const extraWarning = p.requires_extra_warning ? `<div style="color:var(--danger); font-weight:bold; margin-top:10px;">⚠️ WARNING: You are processing an unusually large number of files!</div>` : '';
            
            let sampleHtml = '';
            if (p.sample && p.sample.length > 0) {
                sampleHtml = `<div style="margin-top:10px; font-size:12px; text-align:left; background:var(--bg-card); padding:10px; border-radius:4px; opacity:0.8; word-break: break-all;">
                    <strong>Sample files:</strong><br>
                    ${p.sample.map(s => `• ${s}`).join('<br>')}
                    ${p.count > p.sample.length ? '<br>...and more' : ''}
                </div>`;
            }

            Swal.fire({
                title: force ? 'Permanently Delete?' : 'Move to Trash?',
                html: `You are about to process <strong>${p.count}</strong> files.<br>${extraWarning}${sampleHtml}`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: 'var(--danger)',
                cancelButtonColor: 'var(--text-muted)',
                confirmButtonText: 'Yes, proceed!'
            }).then((result) => {
                if (result.isConfirmed) {
                    const url = force ? '{{ route("media-vault.media.bulk-force-destroy") }}' : '{{ route("media-vault.media.bulk-destroy") }}';
                    fetch(url, {
                        method: 'POST',
                        headers: { 
                            'X-CSRF-TOKEN': CSRF, 
                            'Content-Type': 'application/json',
                            'Accept': 'application/json' 
                        },
                        body: JSON.stringify({ token: p.token })
                    })
                    .then(r => r.json())
                    .then(d => {
                        toast(d.message);
                        setTimeout(() => location.reload(), 1000);
                    })
                    .catch(() => toast('Bulk delete failed', 'error'));
                }
            });
        })
        .catch(() => toast('Could not fetch preview', 'error'));
    }
</script>
@endpush
