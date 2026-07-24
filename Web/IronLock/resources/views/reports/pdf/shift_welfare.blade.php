@extends('reports.pdf.layout')

@php
    use Illuminate\Support\Carbon;
    // The PDF is a static render, so timestamps are converted to the viewer's
    // timezone here (passed as $report_tz from the download request); the frozen
    // data is UTC. $f formats an ISO-UTC string; $ftime a time only.
    $tz = $report_tz ?? 'UTC';
    $tzLabel = $report_tz_label ?? 'UTC';
    $f = fn ($iso) => $iso ? Carbon::parse($iso)->setTimezone($tz)->format('d M Y H:i') : '—';
    $ftime = fn ($iso) => $iso ? Carbon::parse($iso)->setTimezone($tz)->format('H:i:s') : '—';
    $dash = fn ($v) => ($v === null || $v === '') ? '—' : $v;
    $info = $shift_info;
    $att = $attendance;
    $gpsData = $gps ?? [];
    $wtrData = $wtr ?? [];
@endphp

@section('report-body')
    <p class="muted" style="margin:0 0 8px;">All times shown in {{ $tzLabel }}.</p>

    <h2 class="section">Shift Information</h2>
    <table class="kv">
        <tr><td class="k">Guard</td><td class="v">{{ $info['guard_name'] }}</td>
            <td class="k">Employee ID</td><td class="v">{{ $info['employee_code'] }}</td></tr>
        <tr><td class="k">Site</td><td class="v">{{ $info['site_name'] }}</td>
            <td class="k">Reference</td><td class="v">{{ $info['shift_reference'] }}</td></tr>
        <tr><td class="k">Scheduled</td><td class="v">{{ $f($info['scheduled_start']) }} – {{ $f($info['scheduled_end']) }}</td>
            <td class="k">Actual</td><td class="v">{{ $f($info['actual_start']) }} – {{ $f($info['actual_end']) }}</td></tr>
        <tr><td class="k">Duration</td><td class="v">{{ $dash($info['duration_label']) }}</td>
            <td class="k">Status</td><td class="v">{{ $info['status_label'] }}</td></tr>
        @if (!empty($info['end_type_label']))
        <tr><td class="k">End Type</td><td class="v" colspan="3" style="{{ !empty($info['auto_closed']) ? 'color:#8a2a2a;font-weight:700' : '' }}">{{ $info['end_type_label'] }}</td></tr>
        @endif
    </table>

    <h2 class="section">Attendance</h2>
    <table class="kv">
        <tr><td class="k">Check-in</td><td class="v">{{ $dash($att['checkin_status']) }}</td>
            <td class="k">Check-out</td><td class="v">{{ $dash($att['checkout_status']) }}</td></tr>
        <tr><td class="k">Late arrival</td><td class="v">{{ $dash($att['late_arrival']) }}</td>
            <td class="k">Early leave</td><td class="v">{{ $dash($att['early_leave']) }}</td></tr>
        <tr><td class="k">Total hours</td><td class="v">{{ $dash($att['total_hours']) }}</td>
            <td class="k">WTR</td><td class="v">{{ $dash($att['wtr_compliance']) }}</td></tr>
        <tr><td class="k">Override used</td><td class="v">{{ $dash($att['override_used']) }}</td>
            <td class="k">GPS coverage</td><td class="v">{{ $gpsData['coverage_percent'] === null ? '—' : number_format((float) $gpsData['coverage_percent'], 1) . '%' }}</td></tr>
    </table>

    <h2 class="section">Wakefulness Verification</h2>
    @if (count($wakefulness))
        <table class="grid">
            <thead><tr><th>Time</th><th>Mode</th><th>Response</th><th>Result</th><th>Reason</th></tr></thead>
            <tbody>
                @foreach ($wakefulness as $c)
                    <tr>
                        <td>{{ $ftime($c['time']) }}</td>
                        <td>{{ $c['mode'] }}</td>
                        <td>{{ $c['response_time'] }}</td>
                        <td>{{ $c['result'] }}</td>
                        <td>{{ $c['failure_reason'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="empty">No wakefulness checks were issued.</div>
    @endif
    @php $wfMissed = $missed_checks['wakefulness'] ?? []; @endphp
    @if (count($wfMissed))
        <div class="missed-note">Missed while offline — scheduled challenge{{ count($wfMissed) === 1 ? '' : 's' }} that came due while the guard's device was offline and never materialised on reconnect (neutral; not a failed response).</div>
        <table class="grid">
            <thead><tr><th>Scheduled Time</th><th>Result</th></tr></thead>
            <tbody>
                @foreach ($wfMissed as $m)
                    <tr><td>{{ $ftime($m['time']) }}</td><td>Missed</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2 class="section">Photo Verification</h2>
    @if (count($photos))
        <table class="grid">
            <thead><tr><th>#</th><th>Requested</th><th>Submitted</th><th>Mode</th><th>Status</th><th>Images</th></tr></thead>
            <tbody>
                @foreach ($photos as $p)
                    <tr>
                        <td>{{ $p['request_no'] }}</td>
                        <td>{{ $f($p['requested_at']) }}</td>
                        <td>{{ $f($p['submitted_at']) }}</td>
                        <td>{{ $p['mode'] }}</td>
                        <td>{{ ucfirst($p['status']) }}</td>
                        <td>{{ count($p['images']) }}</td>
                    </tr>
                    @if (!empty($p['liveness']))
                        <tr><td colspan="6" class="muted" style="font-size:8px;">Liveness proof: {{ $p['liveness'] }}</td></tr>
                    @endif
                    @foreach ($p['images'] as $img)
                        @if (!empty($img['integrity']))
                            <tr><td colspan="6" class="muted" style="font-size:8px;">{{ $img['name'] }} — Image Integrity: {{ $img['integrity'] }}</td></tr>
                        @elseif (!empty($img['sha256']))
                            <tr><td colspan="6" class="muted" style="font-size:8px;">SHA-256: {{ $img['sha256'] }}</td></tr>
                        @endif
                    @endforeach
                @endforeach
            </tbody>
        </table>
    @else
        <div class="empty">No photos were submitted.</div>
    @endif
    @php $phMissed = $missed_checks['photos'] ?? []; @endphp
    @if (count($phMissed))
        <div class="missed-note">Missed while offline — scheduled photo request{{ count($phMissed) === 1 ? '' : 's' }} that came due while the guard's device was offline and never materialised on reconnect (neutral; not a rejected image).</div>
        <table class="grid">
            <thead><tr><th>Scheduled Time</th><th>Result</th></tr></thead>
            <tbody>
                @foreach ($phMissed as $m)
                    <tr><td>{{ $ftime($m['time']) }}</td><td>Missed</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2 class="section">Security Validation</h2>
    <table class="grid">
        <thead><tr><th>Check</th><th>Result</th><th>Notes</th></tr></thead>
        <tbody>
            @foreach ($security_validation as $v)
                <tr><td>{{ $v['name'] }}</td><td>{{ $v['result']['label'] }}</td><td>{{ $v['notes'] }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <h2 class="section">WTR Compliance</h2>
    <table class="kv">
        <tr><td class="k">Total hours</td><td class="v">{{ $dash($wtrData['total_hours']) }}</td>
            <td class="k">Status</td><td class="v">{{ $wtrData['status'] ?? '—' }}</td></tr>
    </table>
    @if (!empty($wtrData['override']))
        <table class="kv">
            <tr><td class="k">Override reason</td><td class="v">{{ $wtrData['override']['reason'] }}</td></tr>
            <tr><td class="k">Approved by</td><td class="v">{{ $wtrData['override']['admin'] }}</td>
                <td class="k">Approval time</td><td class="v">{{ $f($wtrData['override']['approval_time']) }}</td></tr>
        </table>
    @endif

    @if (count($alerts))
        <h2 class="section">Alerts &amp; Incidents</h2>
        <table class="grid">
            <thead><tr><th>Alert</th><th>Severity</th><th>Triggered</th><th>Resolved</th><th>Status</th></tr></thead>
            <tbody>
                @foreach ($alerts as $a)
                    <tr>
                        <td>{{ $a['name'] }}</td>
                        <td>{{ $a['severity'] }}</td>
                        <td>{{ $ftime($a['trigger_time']) }}</td>
                        <td>{{ $a['resolution_time'] ? $ftime($a['resolution_time']) : '—' }}</td>
                        <td>{{ ucfirst(strtolower($a['status'])) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if (count($offline_sync))
        <h2 class="section">Offline Sync Audit</h2>
        <table class="grid">
            <thead><tr><th>Gap Start</th><th>Gap End</th><th>Duration</th><th>GPS Backfilled</th></tr></thead>
            <tbody>
                @foreach ($offline_sync as $g)
                    <tr><td>{{ $ftime($g['gap_start']) }}</td><td>{{ $ftime($g['gap_end']) }}</td><td>{{ $g['offline_duration'] }}</td><td>{{ $g['gps_backfilled'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
