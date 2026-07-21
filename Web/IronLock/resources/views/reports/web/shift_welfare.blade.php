@extends('reports.web.layout')

@php
    // Section data (frozen snapshot). Small view helpers.
    $info = $data['shift_info'];
    $att = $data['attendance'];
    $gps = $data['gps'];
    $wtr = $data['wtr'];
    $t = fn ($iso, $cls = 'rpt-ts') => $iso
        ? '<time class="' . $cls . '" data-ts="' . e($iso) . '">' . e($iso) . '</time>'
        : '<span class="wf-empty">—</span>';
    $dash = fn ($v) => ($v === null || $v === '') ? '—' : $v;
@endphp

@section('report-body')

    {{-- 1. Shift Information --}}
    <details class="wf-sec" open>
        <summary class="wf-sechead"><span class="wf-secnum">1</span><span class="wf-sectitle">Shift Information</span><span class="wf-secdesc">Assignment &amp; attendance times</span><span class="wf-chev"></span></summary>
        <div class="wf-secbody">
            <div class="wf-info">
                <div class="wf-row"><span class="k">Guard Name</span><span class="v">{{ $info['guard_name'] }}</span></div>
                <div class="wf-row"><span class="k">Employee ID</span><span class="v wf-mono">{{ $info['employee_code'] }}</span></div>
                <div class="wf-row"><span class="k">Site Name</span><span class="v">{{ $info['site_name'] }}</span></div>
                <div class="wf-row"><span class="k">Shift Reference</span><span class="v wf-mono">{{ $info['shift_reference'] }}</span></div>
                <div class="wf-row"><span class="k">Shift Date</span><span class="v">{!! $t($info['shift_date'], 'rpt-ts-date') !!}</span></div>
                <div class="wf-row"><span class="k">Supervisor</span><span class="v">{{ $info['supervisor'] }}</span></div>
                <div class="wf-row"><span class="k">Scheduled Start</span><span class="v">{!! $t($info['scheduled_start']) !!}</span></div>
                <div class="wf-row"><span class="k">Scheduled End</span><span class="v">{!! $t($info['scheduled_end']) !!}</span></div>
                <div class="wf-row"><span class="k">Actual Check-In</span><span class="v">{!! $t($info['actual_start']) !!}</span></div>
                <div class="wf-row"><span class="k">Actual Check-Out</span><span class="v">{!! $t($info['actual_end']) !!}</span></div>
                <div class="wf-row"><span class="k">Shift Duration</span><span class="v">{{ $dash($info['duration_label']) }}</span></div>
                <div class="wf-row"><span class="k">Shift Status</span><span class="v"><span class="wf-badge"><span class="dot">●</span>{{ $info['status_label'] }}</span></span></div>
            </div>
        </div>
    </details>

    {{-- 2. Attendance Summary --}}
    <details class="wf-sec" open>
        <summary class="wf-sechead"><span class="wf-secnum">2</span><span class="wf-sectitle">Attendance Summary</span><span class="wf-secdesc">Compliance metrics</span><span class="wf-chev"></span></summary>
        <div class="wf-secbody">
            <div class="wf-cards">
                <div class="wf-card"><div class="ck">Check-in Status</div><div class="cv">{{ $dash($att['checkin_status']) }}</div></div>
                <div class="wf-card"><div class="ck">Check-out Status</div><div class="cv">{{ $dash($att['checkout_status']) }}</div></div>
                <div class="wf-card"><div class="ck">Late Arrival</div><div class="cv">{{ $dash($att['late_arrival']) }}</div></div>
                <div class="wf-card"><div class="ck">Early Leave</div><div class="cv">{{ $dash($att['early_leave']) }}</div></div>
                <div class="wf-card"><div class="ck">Total Working Hours</div><div class="cv">{{ $dash($att['total_hours']) }}</div></div>
                <div class="wf-card"><div class="ck">WTR Compliance</div><div class="cv">{{ $dash($att['wtr_compliance']) }}</div></div>
                <div class="wf-card"><div class="ck">Override Used</div><div class="cv">{{ $dash($att['override_used']) }}</div></div>
                <div class="wf-card"><div class="ck">Break Compliance</div><div class="cv">{{ $att['break_compliance'] === null ? 'Not tracked' : $att['break_compliance'] }}</div></div>
            </div>
        </div>
    </details>

    {{-- 3. Timeline --}}
    <details class="wf-sec" open>
        <summary class="wf-sechead"><span class="wf-secnum">3</span><span class="wf-sectitle">Timeline</span><span class="wf-secdesc">{{ count($data['timeline']) }} events</span><span class="wf-chev"></span></summary>
        <div class="wf-secbody">
            @if (count($data['timeline']))
                <div class="wf-tl">
                    @foreach ($data['timeline'] as $ev)
                        <div class="wf-tl-item {{ in_array($ev['badge']['style'], ['warn','bad','dash']) ? 'dash' : '' }}">
                            <div class="wf-tl-time wf-mono">{!! $t($ev['time'], 'rpt-ts-time') !!}</div>
                            <div class="wf-tl-type">{{ $ev['type'] }} <span class="wf-badge {{ $ev['badge']['style'] }}">{{ $ev['badge']['label'] }}</span></div>
                            @if (!empty($ev['desc']))<div class="wf-tl-desc">{{ $ev['desc'] }}</div>@endif
                        </div>
                    @endforeach
                </div>
            @else
                <div class="wf-empty">No events recorded for this shift.</div>
            @endif
        </div>
    </details>

    {{-- 4. GPS & Geofence Summary --}}
    <details class="wf-sec" open>
        <summary class="wf-sechead"><span class="wf-secnum">4</span><span class="wf-sectitle">GPS &amp; Geofence Summary</span><span class="wf-secdesc">Location coverage</span><span class="wf-chev"></span></summary>
        <div class="wf-secbody">
            <div class="wf-info" style="grid-template-columns:1fr">
                @php
                    // Backward-compat with reports frozen BEFORE GPS coverage became
                    // exit-aware (2026-07-20). Old snapshots stored offline time under
                    // `time_outside_label` and have no `time_offline_label`/`exit_count`.
                    // Detect the split by the presence of `time_offline_label`, and map
                    // an old snapshot honestly: its figure was offline time (→ Time
                    // Offline), with no separate outside-zone measure (→ "—", 0 exits).
                    $gpsHasSplit = array_key_exists('time_offline_label', $gps);
                    $gpsInside  = $gps['time_inside_label'] ?? null;
                    $gpsOutside = $gpsHasSplit ? ($gps['time_outside_label'] ?? null) : null;
                    $gpsOffline = $gpsHasSplit ? ($gps['time_offline_label'] ?? null) : ($gps['time_outside_label'] ?? null);
                    $gpsExits   = $gps['exit_count'] ?? 0;
                @endphp
                <div class="wf-row"><span class="k">Site Name</span><span class="v">{{ $gps['site_name'] }}</span></div>
                <div class="wf-row"><span class="k">GPS Coverage</span><span class="v">{{ $gps['coverage_percent'] === null ? '—' : number_format((float) $gps['coverage_percent'], 1) . '%' }}</span></div>
                <div class="wf-row"><span class="k">Time Inside Geofence</span><span class="v">{{ $dash($gpsInside) }}</span></div>
                <div class="wf-row"><span class="k">Time Outside Zone</span><span class="v">{{ $dash($gpsOutside) }}</span></div>
                <div class="wf-row"><span class="k">Time Offline</span><span class="v">{{ $dash($gpsOffline) }}</span></div>
                <div class="wf-row"><span class="k">Zone Exits</span><span class="v">{{ $gpsExits > 0 ? '▲ ' . $gpsExits : 'None' }}</span></div>
                <div class="wf-row"><span class="k">Communication Gaps</span><span class="v">{{ ($gps['gap_count'] ?? 0) > 0 ? '▲ ' . $gps['gap_count'] : 'None' }}</span></div>
                <div class="wf-row"><span class="k">Final Shift Location</span><span class="v wf-mono">{{ $dash($gps['final_location'] ?? null) }}</span></div>
            </div>
            <div class="wf-note">GPS Coverage is the share of the active shift the guard was confirmed on-post — inside the geofence with live GPS — deducting both time spent outside the zone (from geofence-transition events) and offline/comms-gap time. IronLock retains no per-ping location history, so an absolute ping count is not reported.</div>
        </div>
    </details>

    {{-- 5. Wakefulness Verification --}}
    <details class="wf-sec" open>
        <summary class="wf-sechead"><span class="wf-secnum">5</span><span class="wf-sectitle">Wakefulness Verification</span><span class="wf-secdesc">{{ count($data['wakefulness']) }} challenges</span><span class="wf-chev"></span></summary>
        <div class="wf-secbody">
            @if (count($data['wakefulness']))
                <table class="wf-table">
                    <thead><tr><th>Challenge Time</th><th>Online / Offline</th><th>Window</th><th>Response Time</th><th>Result</th><th>Failure Reason</th></tr></thead>
                    <tbody>
                        @foreach ($data['wakefulness'] as $c)
                            <tr>
                                <td class="wf-mono">{!! $t($c['time'], 'rpt-ts-time') !!}</td>
                                <td>{{ ucfirst(strtolower($c['mode'] ?? '—')) }}</td>
                                <td>{{ $c['window'] }}</td>
                                <td>{{ $c['response_time'] }}</td>
                                <td>
                                    @php $rs = $c['result'] === 'CONFIRMED' ? 'ok' : ($c['result'] === 'FAILED' ? 'bad' : 'muted'); @endphp
                                    <span class="wf-badge {{ $rs }}">{{ $c['result'] }}</span>
                                </td>
                                <td style="color:{{ $c['failure_reason'] ? '#8a2a2a' : '#bbb' }}">{{ $c['failure_reason'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="wf-empty">No wakefulness checks were issued for this shift.</div>
            @endif
        </div>
    </details>

    {{-- 6. Photo Verification --}}
    <details class="wf-sec" open>
        <summary class="wf-sechead"><span class="wf-secnum">6</span><span class="wf-sectitle">Photo Verification</span><span class="wf-secdesc">{{ count($data['photos']) }} requests</span><span class="wf-chev"></span></summary>
        <div class="wf-secbody">
            @forelse ($data['photos'] as $p)
                <div class="wf-vr">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
                        <div style="font-size:13px;font-weight:700;color:#2c2c2c">Request #{{ $p['request_no'] }} — <span class="wf-mono" style="font-weight:400;color:#888">{!! $t($p['requested_at'], 'rpt-ts-time') !!}</span></div>
                        <span class="wf-badge {{ $p['status'] === 'fulfilled' ? 'ok' : 'muted' }}">{{ ucfirst($p['status']) }}</span>
                    </div>
                    <div class="wf-vr-meta">
                        <div><div class="wf-mini-k">Request Time</div><div class="wf-mini-v wf-mono">{!! $t($p['requested_at'], 'rpt-ts-time') !!}</div></div>
                        <div><div class="wf-mini-k">Response Time</div><div class="wf-mini-v wf-mono">{!! $t($p['submitted_at'], 'rpt-ts-time') !!}</div></div>
                        <div><div class="wf-mini-k">Online / Offline</div><div class="wf-mini-v">{{ $p['mode'] }}</div></div>
                        <div><div class="wf-mini-k">GPS Accuracy</div><div class="wf-mini-v">{{ $dash($p['gps_accuracy']) }}</div></div>
                        <div><div class="wf-mini-k">NTP Status</div><div class="wf-mini-v">{{ $p['ntp_status'] }}</div></div>
                        <div><div class="wf-mini-k">HMAC Validation</div><div class="wf-mini-v">{{ $p['hmac'] }} ✓</div></div>
                        <div><div class="wf-mini-k">EXIF Validation</div><div class="wf-mini-v">{{ $p['exif'] }}</div></div>
                        @if (!empty($p['liveness']))
                            <div><div class="wf-mini-k">Liveness Proof</div><div class="wf-mini-v" style="color:{{ $p['liveness'] === 'Verified' ? '#2e7d32' : '#8a2a2a' }}">{{ $p['liveness'] }}{{ $p['liveness'] === 'Verified' ? ' ✓' : '' }}</div></div>
                        @endif
                        <div><div class="wf-mini-k">Images</div><div class="wf-mini-v">{{ count($p['images']) }}</div></div>
                    </div>
                    @if (count($p['images']))
                        <div class="wf-gallery">
                            @foreach ($p['images'] as $img)
                                @php
                                    $integrity = $img['integrity'] ?? null;
                                    $integrityColor = $integrity === 'Verified' ? '#2e7d32' : '#999';
                                @endphp
                                <div class="wf-shot">
                                    <div class="wf-ph" style="height:104px">IMG</div>
                                    <div class="wf-shot-cap wf-mono">{{ $img['name'] }}<br>{!! $t($img['captured_at'], 'rpt-ts-time') !!}
                                        @if ($integrity)
                                            <br><span style="color:{{ $integrityColor }}">Image Integrity: <span style="white-space:nowrap">{{ $integrity }}{{ $integrity === 'Verified' ? ' ✓' : '' }}</span></span>
                                        @elseif (!empty($img['sha256']))
                                            <br><span style="color:#bbb">{{ substr($img['sha256'], 0, 16) }}…</span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <div class="wf-empty">No photos were submitted for this shift.</div>
            @endforelse
        </div>
    </details>

    {{-- 7. Security Validation --}}
    <details class="wf-sec" open>
        <summary class="wf-sechead"><span class="wf-secnum">7</span><span class="wf-sectitle">Security Validation</span><span class="wf-secdesc">{{ count($data['security_validation']) }} checks</span><span class="wf-chev"></span></summary>
        <div class="wf-secbody">
            <table class="wf-table">
                <thead><tr><th style="width:28%">Validation Name</th><th style="width:16%">Result</th><th>Notes</th></tr></thead>
                <tbody>
                    @foreach ($data['security_validation'] as $v)
                        <tr>
                            <td>{{ $v['name'] }}</td>
                            <td><span class="wf-badge {{ $v['result']['style'] }}">{{ $v['result']['label'] }}</span></td>
                            <td>{{ $v['notes'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>

    {{-- 8. WTR Compliance --}}
    <details class="wf-sec" open>
        <summary class="wf-sechead"><span class="wf-secnum">8</span><span class="wf-sectitle">WTR Compliance</span><span class="wf-secdesc">Working Time Regulations</span><span class="wf-chev"></span></summary>
        <div class="wf-secbody">
            <div class="wf-info" style="margin-bottom:18px">
                <div class="wf-row"><span class="k">Total Hours Worked</span><span class="v">{{ $dash($wtr['total_hours']) }}</span></div>
                <div class="wf-row"><span class="k">Scheduled Hours</span><span class="v">{{ $dash($wtr['scheduled_hours']) }}</span></div>
                <div class="wf-row"><span class="k">Violations</span><span class="v">{{ count($wtr['violations']) ?: 'None' }}</span></div>
                <div class="wf-row"><span class="k">Compliance Status</span><span class="v"><span class="wf-badge {{ empty($wtr['violations']) ? 'ok' : 'warn' }}">{{ $wtr['status'] }}</span></span></div>
            </div>
            @if ($wtr['override'])
                <div class="wf-panel warn">
                    <div class="wf-panel-head">WTR OVERRIDE APPLIED</div>
                    <div class="wf-info" style="grid-template-columns:1fr 1fr">
                        <div class="wf-row"><span class="k">Override Reason</span><span class="v">{{ $wtr['override']['reason'] }}</span></div>
                        <div class="wf-row"><span class="k">Administrator</span><span class="v">{{ $wtr['override']['admin'] }}</span></div>
                        <div class="wf-row"><span class="k">Approval Time</span><span class="v wf-mono">{!! $t($wtr['override']['approval_time']) !!}</span></div>
                        <div class="wf-row"><span class="k">Type</span><span class="v">{{ $wtr['override']['notes'] }}</span></div>
                    </div>
                </div>
            @endif
        </div>
    </details>

    {{-- 9. Alerts & Incidents --}}
    <details class="wf-sec" open>
        <summary class="wf-sechead"><span class="wf-secnum">9</span><span class="wf-sectitle">Alerts &amp; Incidents</span><span class="wf-secdesc">{{ count($data['alerts']) }} alerts</span><span class="wf-chev"></span></summary>
        <div class="wf-secbody">
            @if (count($data['alerts']))
                <table class="wf-table">
                    <thead><tr><th>Alert</th><th>Severity</th><th>Trigger Time</th><th>Resolution Time</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach ($data['alerts'] as $a)
                            <tr>
                                <td>{{ $a['name'] }}</td>
                                <td><span class="wf-badge {{ $a['severity'] === 'CRITICAL' ? 'strong' : 'warn' }}">{{ ucfirst(strtolower($a['severity'])) }}</span></td>
                                <td class="wf-mono">{!! $t($a['trigger_time'], 'rpt-ts-time') !!}</td>
                                <td class="wf-mono">{!! $a['resolution_time'] ? $t($a['resolution_time'], 'rpt-ts-time') : '<span style="color:#bbb">—</span>' !!}</td>
                                <td><span class="wf-badge {{ $a['status'] === 'ACKNOWLEDGED' ? 'ok' : 'dash' }}">{{ ucfirst(strtolower($a['status'])) }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="wf-empty">No alerts were raised during this shift.</div>
            @endif
        </div>
    </details>

    {{-- 10. Offline Sync Audit --}}
    <details class="wf-sec" open>
        <summary class="wf-sechead"><span class="wf-secnum">10</span><span class="wf-sectitle">Offline Sync Audit</span><span class="wf-secdesc">{{ count($data['offline_sync']) }} communication gap(s)</span><span class="wf-chev"></span></summary>
        <div class="wf-secbody">
            @if (count($data['offline_sync']))
                <table class="wf-table">
                    <thead><tr><th>Gap Start</th><th>Gap End</th><th>Offline Duration</th><th>GPS Backfilled</th><th>Sync Flush</th></tr></thead>
                    <tbody>
                        @foreach ($data['offline_sync'] as $g)
                            <tr>
                                <td class="wf-mono">{!! $t($g['gap_start'], 'rpt-ts-time') !!}</td>
                                <td class="wf-mono">{!! $t($g['gap_end'], 'rpt-ts-time') !!}</td>
                                <td>{{ $g['offline_duration'] }}</td>
                                <td>{{ $g['gps_backfilled'] }} points <span class="wf-badge ok"><span class="dot">✓</span>Synced</span></td>
                                <td class="wf-mono">{!! $t($g['sync_flush'], 'rpt-ts-time') !!}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="wf-empty">No communication gaps — the device stayed online for the whole shift.</div>
            @endif
        </div>
    </details>

    {{-- 11. Administrative Actions --}}
    <details class="wf-sec" open>
        <summary class="wf-sechead"><span class="wf-secnum">11</span><span class="wf-sectitle">Administrative Actions</span><span class="wf-secdesc">{{ count($data['admin_actions']) }} actions</span><span class="wf-chev"></span></summary>
        <div class="wf-secbody">
            @if (count($data['admin_actions']))
                <table class="wf-table">
                    <thead><tr><th style="width:24%">Administrator</th><th>Action</th><th style="width:26%">Timestamp</th></tr></thead>
                    <tbody>
                        @foreach ($data['admin_actions'] as $a)
                            <tr>
                                <td>{{ $a['admin'] }}</td>
                                <td>{{ $a['action'] }}</td>
                                <td class="wf-mono">{!! $t($a['timestamp']) !!}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="wf-empty">No administrative actions were logged for this shift.</div>
            @endif
        </div>
    </details>

@endsection
