@extends('admin.layouts.app')

@section('title', 'Shift Detail - ' . ($shift->reference ?? 'Shift'))
@section('page-title', 'Shift Detail')

@php
    // Friendly heading + dot style per event type. Only the shift-lifecycle
    // events exist today; GPS / wakefulness / photo / alert events come in later
    // roadmap phases and are shown as placeholder sections below the timeline.
    $eventMeta = [
        'CHECKED_IN'              => ['label' => 'Checked In',               'tone' => 'ok'],
        'SHIFT_STARTED'           => ['label' => 'Shift Started',            'tone' => 'ok'],
        'SHIFT_ENDED'             => ['label' => 'Shift Ended',              'tone' => 'ok'],
        'START_WINDOW_EXPIRED'    => ['label' => 'Start Window Expired',     'tone' => 'alert'],
        'SHIFT_MISSED'            => ['label' => 'Shift Missed',             'tone' => 'alert'],
        'LATE_CHECKIN_AUTHORIZED' => ['label' => 'Late Check-In Authorized', 'tone' => 'warn'],
        'SHIFT_EXCUSED'           => ['label' => 'Shift Excused',            'tone' => 'warn'],
        'SHIFT_REASSIGNED'        => ['label' => 'Shift Reassigned',         'tone' => 'warn'],
        'SHIFT_NO_SHOW_CONFIRMED' => ['label' => 'No-Show Confirmed',        'tone' => 'alert'],
        'EARLY_END_APPROVED'      => ['label' => 'Early Finish Approved',    'tone' => 'warn'],
        'EARLY_END_FLAGGED'       => ['label' => 'Early Finish Flagged',     'tone' => 'alert'],
        'SHIFT_CANCELLED'         => ['label' => 'Shift Cancelled',          'tone' => 'warn'],
    ];

    $summary = is_array($shift->compliance_summary) ? $shift->compliance_summary : null;
@endphp

@section('styles')
<style>
    .detail-grid { display: flex; gap: 16px; align-items: flex-start; }
    .detail-main { flex: 1; min-width: 0; }
    .detail-side { width: 260px; flex-shrink: 0; }

    /* ── Header summary ─────────────────────────────── */
    .detail-head {
        background: var(--surface-dark);
        border: 1.5px solid var(--border-dark);
        border-radius: 4px;
        padding: 14px 16px;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 14px;
        flex-wrap: wrap;
    }
    .detail-ref {
        font-size: 14px;
        font-weight: bold;
        color: var(--premium-gold);
        letter-spacing: 0.02em;
    }
    .detail-head-meta { font-size: 12px; color: var(--text-secondary); }
    .detail-head-meta strong { color: var(--text-primary); }
    .detail-head-spacer { flex: 1; }
    .detail-status-chip {
        padding: 3px 10px; font-size: 10px; font-weight: bold; border-radius: 10px;
        text-transform: uppercase; letter-spacing: 0.04em; border: 1px solid;
    }
    .detail-status-chip.scheduled  { color: var(--text-secondary); border-color: var(--border-dark); }
    .detail-status-chip.checked_in { color: var(--warning-amber);  border-color: var(--warning-amber); }
    .detail-status-chip.active     { color: var(--success-green);  border-color: var(--success-green); }
    .detail-status-chip.completed  { color: #60a5fa;               border-color: #60a5fa; }
    .detail-status-chip.cancelled  { color: var(--text-muted);     border-color: var(--text-muted); }
    .detail-status-chip.missed     { color: var(--error-red);      border-color: var(--error-red); }

    .detail-btn-back {
        font-size: 11px; color: var(--text-secondary); text-decoration: none;
        border: 1px solid var(--border-dark); border-radius: 4px; padding: 5px 10px;
    }
    .detail-btn-back:hover { border-color: var(--premium-gold); color: var(--premium-gold); }

    .panel {
        background: var(--surface-dark);
        border: 1.5px solid var(--border-dark);
        border-radius: 4px;
        padding: 16px;
        margin-bottom: 16px;
    }
    .panel-title {
        font-size: 10px; font-weight: bold; text-transform: uppercase;
        letter-spacing: 0.06em; color: var(--text-muted); margin-bottom: 14px;
    }

    /* ── Timeline ───────────────────────────────────── */
    .tl { position: relative; }
    .tl-row { display: flex; gap: 12px; }
    .tl-node { position: relative; width: 14px; flex-shrink: 0; display: flex; justify-content: center; }
    .tl-dot {
        width: 11px; height: 11px; border-radius: 50%; margin-top: 3px; z-index: 2;
        background: var(--success-green); border: 2px solid var(--surface-dark);
    }
    .tl-dot.warn  { background: var(--warning-amber); }
    .tl-dot.alert { background: var(--error-red); }
    .tl-line {
        position: absolute; top: 14px; bottom: -6px; left: 50%;
        width: 2px; transform: translateX(-50%); background: var(--border-dark); z-index: 1;
    }
    .tl-row:last-child .tl-line { display: none; }
    .tl-body { padding-bottom: 18px; flex: 1; min-width: 0; }
    .tl-heading { font-size: 12px; font-weight: bold; color: var(--text-primary); }
    .tl-meta { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
    .tl-card {
        margin-top: 6px; padding: 8px 10px; border-radius: 4px; font-size: 11px;
        background: var(--bg-dark); border: 1px solid var(--border-dark);
        color: var(--text-secondary); line-height: 1.5; word-break: break-word;
    }
    .tl-empty { font-size: 12px; color: var(--text-muted); padding: 10px 0; }

    /* ── Compliance side panel ──────────────────────── */
    .sum-row {
        display: flex; justify-content: space-between; gap: 8px;
        font-size: 11px; padding: 5px 0; border-bottom: 1px solid var(--border-dark);
    }
    .sum-row:last-child { border-bottom: none; }
    .sum-row .sum-label { color: var(--text-muted); }
    .sum-row .sum-val { color: var(--text-primary); text-align: right; }

    .placeholder-note {
        font-size: 11px; color: var(--text-muted); line-height: 1.5;
        border: 1px dashed var(--border-dark); border-radius: 4px; padding: 10px;
    }
    .placeholder-note strong { color: var(--text-secondary); }

    @media (max-width: 900px) {
        .detail-grid { flex-direction: column; }
        .detail-side { width: 100%; }
    }
</style>
@endsection

@section('content')
@php
    $guard = $shift->assignedGuard;
    $guardName = $guard ? trim($guard->first_name . ' ' . $guard->last_name) : 'Unassigned';
    $tz = config('app.timezone') ?: 'UTC';
    $fmt = fn ($dt) => $dt ? \Illuminate\Support\Carbon::parse($dt)->setTimezone($tz)->format('d M Y · H:i') : '—';
    $time = fn ($dt) => $dt ? \Illuminate\Support\Carbon::parse($dt)->setTimezone($tz)->format('H:i:s') : '—';
@endphp

<div class="detail-head">
    <span class="detail-ref">#{{ $shift->reference ?? '—' }}</span>
    <span class="detail-head-meta">
        <strong>{{ $guardName }}</strong>
        · {{ $shift->site->name ?? 'No site' }}
        · {{ optional($shift->scheduled_start)->setTimezone($tz)->format('d M Y') }}
    </span>
    <span class="detail-head-meta">
        {{ optional($shift->scheduled_start)->setTimezone($tz)->format('H:i') }}–{{ optional($shift->scheduled_end)->setTimezone($tz)->format('H:i') }}
    </span>
    <span class="detail-head-spacer"></span>
    <span class="detail-status-chip {{ $shift->status }}">{{ $shift->formatted_status }}</span>
    <a id="detail-back-btn" href="{{ route('admin.shifts.index') }}" class="detail-btn-back">← Back to Shifts</a>
</div>

<div class="detail-grid">
    <div class="detail-main">
        <div class="panel">
            <div class="panel-title">Timeline — Append-only audit log</div>
            <div class="tl">
                @forelse ($events as $event)
                    @php
                        $meta = $eventMeta[$event->event_type] ?? ['label' => \Illuminate\Support\Str::headline(strtolower($event->event_type)), 'tone' => 'ok'];
                        $payload = is_array($event->metadata) ? $event->metadata : [];
                    @endphp
                    <div class="tl-row">
                        <div class="tl-node">
                            <div class="tl-dot {{ $meta['tone'] }}"></div>
                            <div class="tl-line"></div>
                        </div>
                        <div class="tl-body">
                            <div class="tl-heading">{{ $meta['label'] }}</div>
                            <div class="tl-meta">
                                {{ $time($event->server_received_at ?? $event->created_at) }} · server timestamp · {{ $event->event_type }}
                            </div>
                            @if (!empty($payload))
                                <div class="tl-card">
                                    @foreach ($payload as $k => $v)
                                        @php $v = is_bool($v) ? ($v ? 'yes' : 'no') : (is_array($v) ? json_encode($v) : $v); @endphp
                                        <div>{{ \Illuminate\Support\Str::headline($k) }}: {{ $v }}</div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="tl-empty">No events recorded for this shift yet.</div>
                @endforelse
            </div>
        </div>

        <div class="panel">
            <div class="panel-title">Monitoring Evidence</div>
            <div class="placeholder-note">
                <strong>Coming in later phases.</strong> GPS pings, wakefulness checks, photo
                verification and zone-exit alerts will appear in this timeline once those
                features are built (roadmap phases 3.3–6). Today the timeline shows the shift
                lifecycle and supervisor actions recorded in the audit log above.
            </div>
        </div>
    </div>

    <div class="detail-side">
        <div class="panel">
            <div class="panel-title">Shift Details</div>
            <div class="sum-row"><span class="sum-label">Reference</span><span class="sum-val">#{{ $shift->reference ?? '—' }}</span></div>
            <div class="sum-row"><span class="sum-label">Guard</span><span class="sum-val">{{ $guardName }}</span></div>
            <div class="sum-row"><span class="sum-label">Site</span><span class="sum-val">{{ $shift->site->name ?? '—' }}</span></div>
            <div class="sum-row"><span class="sum-label">Scheduled</span><span class="sum-val">{{ $fmt($shift->scheduled_start) }}</span></div>
            <div class="sum-row"><span class="sum-label">Sched. end</span><span class="sum-val">{{ $fmt($shift->scheduled_end) }}</span></div>
            <div class="sum-row"><span class="sum-label">Actual start</span><span class="sum-val">{{ $fmt($shift->actual_start) }}</span></div>
            <div class="sum-row"><span class="sum-label">Actual end</span><span class="sum-val">{{ $fmt($shift->actual_end) }}</span></div>
        </div>

        <div class="panel">
            <div class="panel-title">Compliance Summary</div>
            @if ($summary)
                <div class="sum-row">
                    <span class="sum-label">WTR duration</span>
                    <span class="sum-val">
                        {{ $summary['shift_duration']['actual_hours'] ? round($summary['shift_duration']['actual_hours'], 2) . 'h' : '—' }}
                        (limit 16h)
                    </span>
                </div>
                <div class="sum-row">
                    <span class="sum-label">WTR violations</span>
                    <span class="sum-val">{{ count($summary['wtr_compliance']['violations'] ?? []) ?: 'None' }}</span>
                </div>
                <div class="sum-row">
                    <span class="sum-label">Started on time</span>
                    <span class="sum-val">{{ ($summary['attendance']['started_on_time'] ?? false) ? '✓ Yes' : 'No' }}</span>
                </div>
                <div class="sum-row">
                    <span class="sum-label">Ended on time</span>
                    <span class="sum-val">{{ ($summary['attendance']['ended_on_time'] ?? false) ? '✓ Yes' : 'No' }}</span>
                </div>
            @else
                <div class="placeholder-note">
                    The compliance summary is generated when the shift is ended. It will show
                    WTR duration, attendance and (in later phases) GPS coverage, wakefulness and
                    photo results.
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
(function () {
    var ref = document.referrer;
    var btn = document.getElementById('detail-back-btn');
    if (!btn) return;

    // Came from the guards page → go back there.
    if (ref && ref.includes('/admin/guards')) {
        btn.href = '{{ route('admin.guards.index') }}';
        btn.textContent = '← Back to Guards';
        return;
    }

    // Came from the same page (e.g. browser F5 / open-in-tab) or another
    // admin page that isn't shifts → keep the shifts fallback.
    // Came from shifts → default href is already correct.
})();
</script>
@endsection
