@extends('media-vault::dashboard.layout')

@section('title', 'Upload Sessions')
@section('page-title', 'Upload Sessions')

@section('content')
<div class="filter-bar">
    <div class="filter-pills">
        @foreach(['all' => 'All', 'pending' => 'Pending', 'assembling' => 'Assembling', 'complete' => 'Complete', 'failed' => 'Failed'] as $key => $label)
            <a href="{{ route('media-vault.sessions', ['status' => $key]) }}"
               class="filter-pill {{ $status === $key ? 'active' : '' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>
</div>

<div class="card">
    @if($sessions->isEmpty())
        <div style="text-align:center; padding: 40px; color: var(--text-muted);">
            <div style="font-size:36px; margin-bottom:12px; opacity:.4;">📦</div>
            No sessions found.
        </div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Session ID</th>
                        <th>File</th>
                        <th>Progress</th>
                        <th>Size</th>
                        <th>Status</th>
                        <th>Expires</th>
                        <th>User</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($sessions as $session)
                    @php
                        $received = count(array_filter($session->received_chunks ?? [], fn($v) => $v === true));
                        $pct      = $session->total_chunks > 0 ? round($received / $session->total_chunks * 100) : 0;
                    @endphp
                    <tr>
                        <td style="font-family:monospace; font-size:11px; color:var(--text-muted);">
                            {{ substr($session->session_id, 0, 8) }}…
                        </td>
                        <td>
                            <div style="font-size:13px; font-weight:500;">{{ $session->original_name }}</div>
                            <div style="font-size:11px; color:var(--text-muted);">{{ $session->mime_type }}</div>
                        </td>
                        <td style="min-width:140px;">
                            <div style="display:flex;align-items:center;gap:8px;">
                                <div class="progress" style="flex:1;">
                                    <div class="progress-bar" style="width:{{ $pct }}%;
                                        background: {{ $session->status === 'failed' ? 'var(--danger)' : ($session->status === 'complete' ? 'var(--success)' : '') }};"></div>
                                </div>
                                <span style="font-size:11px; color:var(--text-muted); flex-shrink:0;">{{ $received }}/{{ $session->total_chunks }}</span>
                            </div>
                        </td>
                        <td style="font-size:12px; color:var(--text-muted);">
                            {{ round($session->total_size / 1048576, 1) }} MB
                        </td>
                        <td>
                            <span class="badge badge-{{ $session->status }}">
                                {{ ucfirst($session->status) }}
                            </span>
                        </td>
                        <td style="font-size:12px; color:var(--text-muted);">
                            @if($session->expires_at)
                                {{ $session->expires_at->diffForHumans() }}
                            @else
                                —
                            @endif
                        </td>
                        <td style="font-size:12px; color:var(--text-muted);">
                            {{ $session->user_id ?? '—' }}
                        </td>
                        <td style="font-size:12px; color:var(--text-muted);">
                            {{ $session->created_at->format('M d, H:i') }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        @if($sessions->hasPages())
        <div class="pagination" style="margin-top:16px;">
            @if(!$sessions->onFirstPage())
                <a href="{{ $sessions->previousPageUrl() }}" class="page-link">← Prev</a>
            @endif
            @foreach($sessions->getUrlRange(max(1,$sessions->currentPage()-2), min($sessions->lastPage(),$sessions->currentPage()+2)) as $page => $url)
                @if($page == $sessions->currentPage())
                    <span class="page-active">{{ $page }}</span>
                @else
                    <a href="{{ $url }}" class="page-link">{{ $page }}</a>
                @endif
            @endforeach
            @if($sessions->hasMorePages())
                <a href="{{ $sessions->nextPageUrl() }}" class="page-link">Next →</a>
            @endif
        </div>
        @endif
    @endif
</div>
@endsection
