@extends('admin.layouts.app')

@section('title', 'Dashboard - IronLock')
@section('page-title', 'Dashboard')

@section('styles')
<style>
    /* Dashboard-specific styles using IronLock color palette */
    .alert-row {
        background: var(--surface-dark);
        border: 1.5px solid var(--border-dark);
        border-radius: 4px;
        padding: 12px;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 12px;
        cursor: pointer;
        transition: border-color 0.2s ease;
    }

    .alert-row:hover {
        border-color: var(--premium-gold);
    }

    .alert-row.critical {
        border-left: 3px solid var(--critical-red);
        animation: pulse-border 2s infinite;
    }

    .alert-row.warning {
        border-left: 3px solid var(--warning-amber);
    }

    @keyframes pulse-border {
        0%, 100% { border-left-color: var(--critical-red); }
        50% { border-left-color: #ff6b6b; }
    }

    .section-header {
        font-size: 10px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--text-muted);
        margin-bottom: 12px;
        margin-top: 24px;
    }

    .section-header:first-child {
        margin-top: 0;
    }

    .guards-table-container {
        background: var(--surface-dark);
        border: 1.5px solid var(--border-dark);
        border-radius: 4px;
        overflow: hidden;
    }

    .completion-banner {
        background: rgba(34, 197, 94, 0.1);
        border: 1px solid var(--success-green);
        border-radius: 4px;
        padding: 16px;
        margin-bottom: 24px;
        color: var(--success-green);
    }

    .completion-banner h3 {
        margin: 0 0 12px 0;
        font-size: 14px;
    }

    .completion-banner p {
        margin: 0;
        font-size: 12px;
        line-height: 1.4;
    }
</style>
@endsection

@section('topbar-actions')
<form method="POST" action="{{ route('admin.logout') }}" style="display: inline;">
    @csrf
    <button type="submit" class="btn-sm btn-secondary-sm">Logout</button>
</form>
@endsection

@section('content')


<!-- KPI Cards -->
<div class="kpi-row">
    <div class="kpi-card" onclick="location.href='{{ route('admin.guards.index') }}'">
        <div class="kpi-value" style="color: var(--premium-gold);">{{ $stats['active_guards'] }}</div>
        <div class="kpi-label">Active Guards</div>
    </div>
    <div class="kpi-card" onclick="location.href='#'">
        <div class="kpi-value" style="color: var(--critical-red);">{{ $stats['critical_alerts'] }}</div>
        <div class="kpi-label">Critical Alerts</div>
    </div>
    <div class="kpi-card" onclick="location.href='#'">
        <div class="kpi-value" style="color: var(--warning-amber);">{{ $stats['comms_interrupted'] }}</div>
        <div class="kpi-label">Comms Interrupted</div>
    </div>
    <div class="kpi-card" onclick="location.href='#'">
        <div class="kpi-value" style="color: var(--text-primary);">{{ $stats['pending_acks'] }}</div>
        <div class="kpi-label">Pending Acks</div>
    </div>
</div>

<!-- Critical/Warning Alerts -->
<div class="section-header">Critical / Warning Alerts — Unacknowledged</div>

@if(isset($alerts) && count($alerts) > 0)
    @foreach($alerts as $alert)
        <div class="alert-row {{ strtolower($alert['severity']) }}" onclick="viewAlert('{{ $alert['id'] }}')">
            <div class="severity-badge {{ strtolower($alert['severity']) }}">
                @if($alert['severity'] === 'CRITICAL')
                    ● {{ $alert['type'] }}
                @else
                    ▲ {{ $alert['type'] }}
                @endif
            </div>
            <div class="alert-content">
                <div class="alert-title">{{ $alert['title'] }}</div>
                <div class="alert-meta">{{ $alert['guard_name'] }} · {{ $alert['site_name'] }} · {{ $alert['age'] }}</div>
            </div>
            <div class="alert-actions">
                <button class="btn-sm btn-primary-sm" onclick="event.stopPropagation(); viewAlert('{{ $alert['id'] }}')">View</button>
                <button class="btn-sm btn-secondary-sm" onclick="event.stopPropagation(); ackAlert('{{ $alert['id'] }}')">Ack</button>
            </div>
        </div>
    @endforeach
@else
    <div style="text-align: center; padding: 24px; color: var(--text-muted); font-size: 12px;">
        🎉 No active alerts — All systems normal
    </div>
@endif

<!-- Active Guards Table -->
<div class="section-header">Active Guards — Live via WebSocket</div>

<div class="guards-table-container">
    <table class="table">
        <thead>
            <tr>
                <th>Guard</th>
                <th>Site</th>
                <th>Zone Status</th>
                <th>Last GPS</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            @if(isset($active_guards) && count($active_guards) > 0)
                @foreach($active_guards as $guard)
                    <tr>
                        <td>{{ $guard['name'] }}</td>
                        <td>{{ $guard['site_name'] ?? 'No Site' }}</td>
                        <td>
                            <div class="status-chip {{ $guard['zone_status_class'] }}">
                                {{ $guard['zone_status_text'] }}
                            </div>
                        </td>
                        <td style="font-size: 11px; color: var(--text-muted);">{{ $guard['last_gps'] }}</td>
                        <td>
                            <button class="btn-sm btn-secondary-sm" onclick="viewShift('{{ $guard['id'] }}')">View Shift</button>
                        </td>
                    </tr>
                @endforeach
            @else
                <tr>
                    <td colspan="5" style="text-align: center; padding: 24px; color: var(--text-muted);">
                        No guards currently on shift
                    </td>
                </tr>
            @endif
        </tbody>
    </table>
</div>
@endsection

@section('scripts')
<script>
    function viewAlert(alertId) {
        // TODO: Implement alert detail view
        console.log('View alert:', alertId);
    }

    function ackAlert(alertId) {
        // TODO: Implement alert acknowledgment
        console.log('Acknowledge alert:', alertId);
    }

    function viewShift(guardId) {
        // TODO: Navigate to shift detail view
        console.log('View shift for guard:', guardId);
    }

    // Auto-refresh functionality (placeholder for WebSocket)
    setInterval(() => {
        // TODO: Implement WebSocket live updates
        console.log('Auto-refresh dashboard data');
    }, 30000);
</script>
@endsection
