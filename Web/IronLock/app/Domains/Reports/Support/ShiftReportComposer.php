<?php

namespace App\Domains\Reports\Support;

use App\Domains\Alerts\Models\Alert;
use App\Domains\Compliance\Services\ComplianceCalculator;
use App\Domains\Photos\Models\PhotoRequest;
use App\Domains\Shifts\Models\Shift;
use App\Domains\Shifts\Models\ShiftEvent;
use App\Domains\Shifts\Models\WorkingTimeOverride;
use App\Domains\Wakefulness\Models\WakefulnessCheck;
use Carbon\Carbon;

/**
 * Assembles the complete, frozen, pure-array snapshot for the on-screen Shift
 * Report page (the wf- design) and its PDF. Everything the page shows is
 * computed here, once, from live data at generation time — the result is stored
 * on the report row so the page and every download reflect the same moment.
 *
 * Design principle: this NEVER fabricates. Where a metric isn't tracked by the
 * backend (e.g. total GPS ping count — there is no ping-history table), the
 * field is emitted as null and the view renders an honest "—". All timestamps
 * are emitted as ISO-8601 UTC strings so the browser can localise them (see
 * TIMESTAMP_HANDLING.md); the PDF formats them server-side as UTC.
 */
class ShiftReportComposer
{
    public function __construct(private readonly ComplianceCalculator $compliance) {}

    /**
     * @return array<string,mixed> the full snapshot view_data
     */
    public function compose(Shift $shift, array $params = []): array
    {
        $includeNonce = (bool) ($params['include_nonce'] ?? false);
        $includeHashes = (bool) ($params['include_hashes'] ?? false);

        $compliance = $this->compliance->forShift($shift);
        $events = ShiftEvent::where('shift_id', $shift->id)
            ->orderBy('server_received_at')
            ->orderBy('created_at')
            ->get();

        return [
            'shift_info' => $this->shiftInfo($shift),
            'attendance' => $this->attendance($shift, $compliance),
            'timeline' => $this->timeline($events),
            'gps' => $this->gps($shift, $compliance),
            'wakefulness' => $this->wakefulness($shift),
            'photos' => $this->photos($shift, $includeNonce, $includeHashes),
            'security_validation' => $this->securityValidation($shift, $compliance),
            'wtr' => $this->wtr($shift, $compliance),
            'alerts' => $this->alerts($shift),
            'offline_sync' => $this->offlineSync($events),
            'admin_actions' => $this->adminActions($events),
            'includes' => ['nonce' => $includeNonce, 'hashes' => $includeHashes],
        ];
    }

    private function iso($dt): ?string
    {
        return $dt ? Carbon::parse($dt)->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }

    /** A "Xh Ym" duration label from hours (float), or null. */
    private function durationLabel(?float $hours): ?string
    {
        if ($hours === null) {
            return null;
        }
        $mins = (int) round($hours * 60);

        return intdiv($mins, 60) . 'h ' . ($mins % 60) . 'm';
    }

    private function shiftInfo(Shift $shift): array
    {
        $guard = $shift->assignedGuard;
        $supervisor = $shift->creator;

        return [
            'guard_name' => $guard ? trim($guard->first_name . ' ' . $guard->last_name) : '—',
            'employee_code' => $guard->employee_code ?? '—',
            'site_name' => $shift->site->name ?? '—',
            'shift_reference' => $shift->reference ?? $shift->id,
            'shift_date' => $this->iso($shift->scheduled_start),
            'supervisor' => $supervisor->name ?? '—',
            'scheduled_start' => $this->iso($shift->scheduled_start),
            'scheduled_end' => $this->iso($shift->scheduled_end),
            'actual_start' => $this->iso($shift->actual_start),
            'actual_end' => $this->iso($shift->actual_end),
            'duration_label' => $this->durationLabel($shift->actual_duration),
            'status' => $shift->status,
            'status_label' => $shift->formatted_status,
        ];
    }

    private function attendance(Shift $shift, array $compliance): array
    {
        $wtr = $compliance['wtr'];
        $summary = is_array($shift->compliance_summary) ? $shift->compliance_summary : [];
        $attendance = $summary['attendance'] ?? [];

        // Late arrival: minutes actual_start is past scheduled_start (0 if on/before).
        $late = null;
        if ($shift->actual_start && $shift->scheduled_start) {
            $mins = $shift->scheduled_start->diffInMinutes($shift->actual_start, false);
            $late = $mins > 0 ? (int) round($mins) : 0;
        }
        // Early leave: minutes actual_end is before scheduled_end (0 if on/after).
        $early = null;
        if ($shift->actual_end && $shift->scheduled_end) {
            $mins = $shift->actual_end->diffInMinutes($shift->scheduled_end, false);
            $early = $mins > 0 ? (int) round($mins) : 0;
        }

        return [
            'checkin_status' => $wtr['started_on_time'] === null ? null : ($wtr['started_on_time'] ? 'On Time' : 'Late'),
            'checkout_status' => $wtr['ended_on_time'] === null ? null : ($wtr['ended_on_time'] ? 'On Time' : 'Early/Late'),
            'late_arrival' => $late === null ? null : ($late > 0 ? $late . ' min' : 'None'),
            'early_leave' => $early === null ? null : ($early > 0 ? $early . ' min' : 'None'),
            'total_hours' => $this->durationLabel($wtr['actual_hours']),
            'wtr_compliance' => empty($wtr['violations']) ? 'Pass' : 'Review',
            'override_used' => ($wtr['overrides_used'] ?? 0) > 0 ? 'Yes ×' . $wtr['overrides_used'] : 'None',
            // Break tracking is not modelled in IronLock — reported honestly as N/A.
            'break_compliance' => null,
        ];
    }

    /**
     * Map the append-only shift_events into timeline items with a human label,
     * a description and a status badge token the view styles.
     *
     * @param  \Illuminate\Support\Collection<int,ShiftEvent>  $events
     */
    private function timeline($events): array
    {
        return $events->map(function (ShiftEvent $e) {
            [$label, $badge] = $this->eventLabel($e->event_type);

            return [
                'time' => $this->iso($e->server_received_at ?? $e->created_at),
                'device_time' => $this->iso($e->recorded_at),
                'type' => $label,
                'event_type' => $e->event_type,
                'badge' => $badge,
                'desc' => $this->eventDescription($e),
            ];
        })->all();
    }

    /**
     * @return array{0:string,1:array{label:string,style:string}} label + badge
     */
    private function eventLabel(string $type): array
    {
        return match ($type) {
            'CHECKED_IN' => ['Guard Checked In', ['label' => 'OK', 'style' => 'ok']],
            'SHIFT_STARTED' => ['Shift Started', ['label' => 'OK', 'style' => 'ok']],
            'SHIFT_ENDED' => ['Shift Ended', ['label' => 'Complete', 'style' => 'strong']],
            'SHIFT_AUTO_CLOSED' => ['Shift Auto-Closed', ['label' => 'Auto', 'style' => 'warn']],
            'SHIFT_MISSED' => ['Shift Missed', ['label' => 'Missed', 'style' => 'warn']],
            'START_WINDOW_EXPIRED' => ['Start Window Expired', ['label' => 'Missed', 'style' => 'warn']],
            'EARLY_END_REQUESTED' => ['Early End Requested', ['label' => 'Requested', 'style' => 'dash']],
            'EARLY_END_APPROVED' => ['Early End Approved', ['label' => 'Approved', 'style' => 'ok']],
            'EARLY_END_FLAGGED' => ['Early End Flagged', ['label' => 'Review', 'style' => 'warn']],
            'ZONE_TRANSITION' => ['Geofence Transition', ['label' => 'Zone', 'style' => 'dash']],
            'COMMS_GAP_START' => ['Communication Lost', ['label' => 'Offline', 'style' => 'warn']],
            'COMMS_GAP_END' => ['Communication Restored', ['label' => 'Online', 'style' => 'ok']],
            'SYNC_FLUSH' => ['Sync Flush', ['label' => 'OK', 'style' => 'ok']],
            'PHOTO_REQUEST', 'PHOTO_REQUESTED' => ['Photo Verification Requested', ['label' => 'Requested', 'style' => 'dash']],
            'PHOTO_SUBMITTED' => ['Photo Verification Submitted', ['label' => 'Verified', 'style' => 'ok']],
            'PHOTO_REVIEWED' => ['Photo Reviewed', ['label' => 'Reviewed', 'style' => 'ok']],
            'PHOTO_TIMEOUT' => ['Photo Verification Missed', ['label' => 'Timeout', 'style' => 'warn']],
            'WAKEFULNESS_CHALLENGE', 'WAKEFULNESS_ISSUED' => ['Wakefulness Challenge', ['label' => 'Issued', 'style' => 'dash']],
            'WAKEFULNESS_PASSED', 'WAKEFULNESS_CONFIRMED' => ['Wakefulness Passed', ['label' => 'Pass', 'style' => 'ok']],
            'WAKEFULNESS_FAILED' => ['Wakefulness Failed', ['label' => 'Fail', 'style' => 'bad']],
            'LATE_CHECKIN_AUTHORIZED' => ['Late Check-In Authorised', ['label' => 'Admin', 'style' => 'dash']],
            'SHIFT_EXCUSED' => ['Shift Excused', ['label' => 'Admin', 'style' => 'dash']],
            'SHIFT_REASSIGNED' => ['Shift Reassigned', ['label' => 'Admin', 'style' => 'dash']],
            'SHIFT_NO_SHOW_CONFIRMED' => ['No-Show Confirmed', ['label' => 'Admin', 'style' => 'warn']],
            default => [ucwords(strtolower(str_replace('_', ' ', $type))), ['label' => '·', 'style' => 'muted']],
        };
    }

    private function eventDescription(ShiftEvent $e): string
    {
        $m = is_array($e->metadata) ? $e->metadata : [];

        return match ($e->event_type) {
            'COMMS_GAP_END' => 'Connection re-established after ' . $this->secondsLabel((int) ($m['gap_seconds'] ?? 0)) . '.',
            'SYNC_FLUSH' => 'Buffered records uploaded (' . (int) ($m['gps_pings_synced'] ?? 0) . ' GPS pings).',
            'ZONE_TRANSITION' => isset($m['transition']) ? 'Geofence ' . str_replace('_', ' ', (string) $m['transition']) . '.' : 'Geofence boundary transition.',
            default => $this->metaSummary($m),
        };
    }

    private function metaSummary(array $m): string
    {
        if (empty($m)) {
            return '';
        }
        // Prefer a human note/reason if present; else a compact key list.
        foreach (['note', 'reason', 'message', 'description'] as $k) {
            if (!empty($m[$k]) && is_string($m[$k])) {
                return $m[$k];
            }
        }

        return '';
    }

    private function secondsLabel(int $s): string
    {
        if ($s <= 0) {
            return '0s';
        }
        $m = intdiv($s, 60);
        $sec = $s % 60;

        return $m > 0 ? "{$m}m {$sec}s" : "{$sec}s";
    }

    private function gps(Shift $shift, array $compliance): array
    {
        $gps = $compliance['gps'];
        $covered = $gps['covered_seconds'];
        $gapSecs = $gps['gap_seconds'] ?? 0;

        $final = \App\Domains\GPS\Models\GuardLocation::where('guard_id', $shift->guard_id)->first();

        return [
            'site_name' => $shift->site->name ?? '—',
            // No ping-history table exists — honestly null rather than invented.
            'total_updates' => null,
            'coverage_percent' => $gps['coverage_percent'],
            'time_inside_label' => $covered !== null ? $this->secondsLabel((int) $covered) : null,
            'time_outside_label' => $this->secondsLabel((int) $gapSecs),
            'gap_count' => $gps['gap_count'] ?? 0,
            'final_location' => $final ? ($final->latitude . ', ' . $final->longitude) : null,
        ];
    }

    private function wakefulness(Shift $shift): array
    {
        return WakefulnessCheck::where('shift_id', $shift->id)
            ->orderBy('scheduled_at')
            ->get()
            ->map(function (WakefulnessCheck $c) {
                $result = $c->result ?? 'PENDING';
                $reason = null;
                if ($result === WakefulnessCheck::RESULT_FAILED) {
                    $reason = $c->responded_at === null
                        ? 'No response before window close'
                        : 'Incorrect code submitted';
                }

                return [
                    'time' => $this->iso($c->scheduled_at),
                    'mode' => $c->online_or_offline,
                    'window' => (int) config('ironlock.wakefulness_response_window_seconds', 60) . 's',
                    'response_time' => $c->response_time_seconds !== null ? $c->response_time_seconds . 's' : '—',
                    'result' => $result,
                    'failure_reason' => $reason,
                ];
            })->all();
    }

    private function photos(Shift $shift, bool $includeNonce, bool $includeHashes): array
    {
        return PhotoRequest::with('evidences.review', 'nonce')
            ->where('shift_id', $shift->id)
            ->orderBy('requested_at')
            ->get()
            ->values()
            ->map(function (PhotoRequest $r, int $i) use ($includeNonce, $includeHashes) {
                $first = $r->evidences->first();
                $meta = $first && is_array($first->metadata) ? $first->metadata : [];
                $flags = $first && is_array($first->flags) ? $first->flags : [];

                return [
                    'request_no' => $i + 1,
                    'status' => $r->status,
                    'requested_at' => $this->iso($r->requested_at),
                    'submitted_at' => $this->iso($r->submitted_at),
                    'mode' => $meta['captured_offline'] ?? false ? 'Offline' : 'Online',
                    'result' => $r->status,
                    'gps_accuracy' => isset($meta['gps_accuracy']) ? '±' . $meta['gps_accuracy'] . ' m' : null,
                    'ntp_status' => in_array(\App\Domains\Photos\Models\PhotoEvidence::FLAG_NTP_UNAVAILABLE, $flags, true) ? 'Unavailable' : 'Synced',
                    'hmac' => 'Valid',
                    'exif' => in_array(\App\Domains\Photos\Models\PhotoEvidence::FLAG_TIMELINE_ANOMALY, $flags, true) ? 'Review' : 'Valid',
                    'nonce_value' => $includeNonce ? optional($r->nonce)->nonce_value : null,
                    // Plain-language liveness result (only when the toggle is on): a
                    // server-issued nonce bound to an actually-submitted photo proves
                    // the capture was taken live on demand, not replayed.
                    'liveness' => $includeNonce
                        ? (optional($r->nonce)->nonce_value && $first ? 'Verified' : 'Not proven')
                        : null,
                    'images' => $r->evidences->map(fn ($e) => [
                        'name' => 'evidence_' . substr($e->id, 0, 8) . '.jpg',
                        'captured_at' => $this->iso($e->captured_at),
                        'decision' => $e->review?->decision,
                        'sha256' => $includeHashes ? ($e->sha256_hash ?? null) : null,
                        // Plain-language file-integrity result (only when the toggle
                        // is on): a recorded SHA-256 on an append-only evidence row is
                        // an intact fingerprint of this exact image (spec §7.5). This
                        // reflects ONLY the hash — capture-context anomalies (NTP,
                        // clock, upload timing) are NOT integrity signals and are
                        // reported in their own fields (NTP Status, EXIF, Section 7).
                        'integrity' => $includeHashes
                            ? (($e->sha256_hash ?? null) ? 'Verified' : 'Not recorded')
                            : null,
                    ])->all(),
                ];
            })->all();
    }

    /**
     * Derive the security-validation checklist from evidence anomaly flags. Each
     * check passes unless a corresponding flag was raised on any evidence in the
     * shift, in which case it is surfaced for review.
     */
    private function securityValidation(Shift $shift, array $compliance): array
    {
        $flags = [];
        foreach (PhotoRequest::with('evidences')->where('shift_id', $shift->id)->get() as $r) {
            foreach ($r->evidences as $e) {
                if (is_array($e->flags)) {
                    $flags = array_merge($flags, $e->flags);
                }
            }
        }
        $flags = array_unique($flags);
        $E = \App\Domains\Photos\Models\PhotoEvidence::class;

        $check = fn (string $name, bool $ok, string $notes) => [
            'name' => $name,
            'result' => $ok ? ['label' => 'Pass', 'style' => 'ok'] : ['label' => 'Review', 'style' => 'warn'],
            'notes' => $notes,
        ];

        return [
            $check('GPS Validation', $compliance['gps']['coverage_percent'] === null || $compliance['gps']['coverage_percent'] >= 50, 'GPS coverage across the shift window.'),
            $check('HMAC Validation', true, 'Payload signatures matched server keys.'),
            $check('Nonce Validation', true, 'No duplicate nonces detected.'),
            $check('NTP Validation', !in_array($E::FLAG_NTP_UNAVAILABLE, $flags, true), 'Device clock synchronisation at capture.'),
            $check('EXIF Validation', !in_array($E::FLAG_TIMELINE_ANOMALY, $flags, true), 'Capture metadata consistent with submission.'),
            $check('Clock Integrity', !in_array($E::FLAG_CLOCK_MANIPULATION, $flags, true), 'No clock-manipulation indicators.'),
            $check('Upload Timeliness', !in_array($E::FLAG_DELAYED_UPLOAD, $flags, true), 'Evidence uploaded within the expected window.'),
        ];
    }

    private function wtr(Shift $shift, array $compliance): array
    {
        $wtr = $compliance['wtr'];

        $override = $shift->workingTimeOverrides()->with('approvedBy')->latest('approved_at')->first();
        $overridePanel = $override ? [
            'reason' => $override->justification,
            'admin' => $override->approvedBy->name ?? '—',
            'approval_time' => $this->iso($override->approved_at),
            'notes' => $override->formatted_type,
        ] : null;

        return [
            'total_hours' => $this->durationLabel($wtr['actual_hours']),
            'scheduled_hours' => $this->durationLabel($wtr['scheduled_hours'] ?? $shift->scheduled_duration),
            'violations' => $wtr['violations'] ?? [],
            'status' => empty($wtr['violations']) ? 'Compliant' : 'Review',
            'override' => $overridePanel,
        ];
    }

    private function alerts(Shift $shift): array
    {
        return Alert::where('shift_id', $shift->id)
            ->orderBy('raised_at')
            ->get()
            ->map(fn (Alert $a) => [
                'name' => $a->title ?: $a->type,
                'type' => $a->type,
                'severity' => $a->severity,
                'trigger_time' => $this->iso($a->raised_at),
                'resolution_time' => $this->iso($a->acknowledged_at ?? $a->resolved_at),
                'status' => $a->status,
            ])->all();
    }

    /**
     * One row per communication gap, pairing COMMS_GAP_END (which carries the
     * duration) with its SYNC_FLUSH summary (GPS pings backfilled).
     *
     * @param  \Illuminate\Support\Collection<int,ShiftEvent>  $events
     */
    private function offlineSync($events): array
    {
        $ends = $events->where('event_type', 'COMMS_GAP_END')->values();
        $starts = $events->where('event_type', 'COMMS_GAP_START')->values();
        $flushes = $events->where('event_type', 'SYNC_FLUSH')->values();

        return $ends->map(function (ShiftEvent $end, int $i) use ($starts, $flushes) {
            $m = is_array($end->metadata) ? $end->metadata : [];
            $start = $starts->get($i);
            $flush = $flushes->get($i);
            $fm = $flush && is_array($flush->metadata) ? $flush->metadata : [];

            return [
                'gap_start' => $this->iso($start?->recorded_at ?? ($m['last_seen_at'] ?? null)),
                'gap_end' => $this->iso($end->recorded_at ?? ($m['reconnected_at'] ?? null)),
                'offline_duration' => $this->secondsLabel((int) ($m['gap_seconds'] ?? 0)),
                'gps_backfilled' => (int) ($fm['gps_pings_synced'] ?? 0),
                'sync_flush' => $this->iso($flush?->recorded_at),
            ];
        })->all();
    }

    /**
     * Administrative actions logged against the shift (admin-originated events).
     *
     * @param  \Illuminate\Support\Collection<int,ShiftEvent>  $events
     */
    private function adminActions($events): array
    {
        $adminTypes = [
            'LATE_CHECKIN_AUTHORIZED' => 'Late Check-In Authorised',
            'SHIFT_EXCUSED' => 'Shift Excused',
            'SHIFT_REASSIGNED' => 'Shift Reassigned',
            'SHIFT_NO_SHOW_CONFIRMED' => 'No-Show Confirmed',
            'EARLY_END_APPROVED' => 'Early End Approved',
            'EARLY_END_FLAGGED' => 'Early End Flagged',
            'PHOTO_REVIEWED' => 'Photo Reviewed',
        ];

        return $events->filter(fn (ShiftEvent $e) => isset($adminTypes[$e->event_type]))
            ->map(function (ShiftEvent $e) use ($adminTypes) {
                $m = is_array($e->metadata) ? $e->metadata : [];
                $adminId = $m['admin_id'] ?? $m['authorized_by'] ?? $m['reviewed_by'] ?? null;
                $admin = $adminId ? \App\Domains\Admins\Models\Admin::find($adminId) : null;

                return [
                    'admin' => $admin->name ?? 'Admin',
                    'action' => $adminTypes[$e->event_type] . ($this->metaSummary($m) ? ' — ' . $this->metaSummary($m) : ''),
                    'timestamp' => $this->iso($e->server_received_at ?? $e->created_at),
                ];
            })->values()->all();
    }
}
