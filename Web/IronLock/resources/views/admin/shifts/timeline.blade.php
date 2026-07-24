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
        'EARLY_END_REQUESTED'         => ['label' => 'Early Finish Requested',        'tone' => 'warn'],
        'EARLY_END_REQUEST_APPROVED'  => ['label' => 'Early Finish Request Approved', 'tone' => 'ok'],
        'EARLY_END_REQUEST_REJECTED'  => ['label' => 'Early Finish Request Rejected', 'tone' => 'alert'],
        'SHIFT_AUTO_CLOSED'           => ['label' => 'Auto-Closed (overdue)',         'tone' => 'alert'],
        // Photo verification (Phase 4)
        'PHOTO_REQUEST'                 => ['label' => 'Photo Requested',               'tone' => 'ok'],
        'PHOTO_SUBMITTED'               => ['label' => 'Photo Submitted',              'tone' => 'ok'],
        'PHOTO_REVIEWED'                => ['label' => 'Photo Reviewed',               'tone' => 'ok'],
        'PHOTO_TIMEOUT'                 => ['label' => 'Photo Timed Out',              'tone' => 'alert'],
        'TIMELINE_ANOMALY'              => ['label' => 'Photo Timeline Anomaly',       'tone' => 'alert'],
        'CLOCK_MANIPULATION_SUSPECTED'  => ['label' => 'Clock Manipulation Suspected', 'tone' => 'alert'],
        'NTP_UNAVAILABLE'               => ['label' => 'NTP Unavailable',              'tone' => 'warn'],
        'DELAYED_UPLOAD'                => ['label' => 'Delayed Photo Upload',         'tone' => 'warn'],
        // Wakefulness verification (Phase 5)
        'WAKEFULNESS_CHALLENGE'         => ['label' => 'Wakefulness Challenge',        'tone' => 'ok'],
        'WAKEFULNESS_CONFIRMED'         => ['label' => 'Wakefulness Confirmed',        'tone' => 'ok'],
        'WAKEFULNESS_FAILED'            => ['label' => 'Wakefulness Failed',           'tone' => 'alert'],
        // Geofence (Phase 3.3)
        'ZONE_EXIT'                     => ['label' => 'Zone Exit Alert',             'tone' => 'alert'],
        // Offline / backfill (Phase 7). Informational, never alert-red — a closed,
        // backfilled comms gap is not a live incident (roadmap §7.3, "no
        // retroactive alerts"). Rendered as an "offline / backfilled" band below,
        // distinguished by shape + icon + text, not colour alone.
        'COMMS_GAP_START'               => ['label' => 'Connectivity Lost',           'tone' => 'offline'],
        'COMMS_GAP_END'                 => ['label' => 'Reconnected',                 'tone' => 'offline'],
        'SYNC_FLUSH'                    => ['label' => 'Synced on Reconnect',         'tone' => 'offline'],
    ];

    // The three offline/backfill audit types render as a neutral band, not a
    // live event row.
    $offlineEventTypes = ['COMMS_GAP_START', 'COMMS_GAP_END', 'SYNC_FLUSH'];

    $summary = is_array($shift->compliance_summary) ? $shift->compliance_summary : null;

    // A guard's early-finish request lives in the timeline as an
    // EARLY_END_REQUESTED audit event. While it is still pending a supervisor
    // decision, attach Accept / Reject controls to that event's card (the latest
    // such event, since a rejected request can be re-submitted).
    $pendingEarlyEndEventId = $shift->hasPendingEarlyEnd()
        ? optional($events->where('event_type', 'EARLY_END_REQUESTED')->first())->id
        : null;
@endphp

@section('styles')
<style>
    .detail-grid { display: flex; gap: 16px; align-items: flex-start; }
    .detail-main { flex: 1; min-width: 0; }
    .detail-side {
        width: 260px; flex-shrink: 0;
        /* The scrolling region in this layout is .content (the body is
           overflow:hidden, height 100vh - topbar), so sticky is measured
           against that — not the window. Pin to the top of the scroll area;
           cap the height to what's actually visible there (viewport minus the
           topbar and the content's 16px top/bottom padding) and let the panel
           scroll internally if a long compliance summary overflows it. */
        position: sticky; top: 0;
        align-self: flex-start;
        max-height: calc(100vh - var(--header-h) - 32px);
        overflow-y: auto;
    }

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

    /* Small red marker next to a shift's Actual end when it was auto-closed
       (force-closed overdue) rather than ended by the guard. */
    .auto-close-dot {
        display: inline-block; width: 7px; height: 7px; border-radius: 50%;
        background: var(--error-red); margin-left: 6px; vertical-align: middle;
        cursor: help;
    }

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

    /* Jump links in the timeline header (top-right) */
    .tl-jump { display: flex; gap: 14px; flex-shrink: 0; }
    .tl-jump a {
        font-size: 11px; color: var(--text-secondary);
        text-decoration: underline; text-underline-offset: 2px; cursor: pointer;
    }
    .tl-jump a:hover { color: var(--premium-gold); }

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
    /* Plain-English lead line for a ZONE_EXIT card (SHIFT_START vs MID_SHIFT),
       so the timeline reads like the alert instead of dumping raw metadata. */
    .tl-zone-desc { color: var(--text-primary); font-weight: 600; margin-bottom: 6px; }
    .tl-empty { font-size: 12px; color: var(--text-muted); padding: 10px 0; }

    /* ── Offline / backfilled (Phase 7) ─────────────────
       A closed comms gap is informational, not an incident. The dot is a
       neutral SQUARE (distinct shape, not just colour, per the accessibility
       rule) and the body carries a "backfilled" badge + a slate-bordered band. */
    .tl-dot.offline {
        background: var(--text-muted);
        border-radius: 2px;            /* square — distinguishable from round live dots */
    }
    .tl-offline-badge {
        display: inline-block; margin-left: 8px; padding: 1px 7px; border-radius: 9px;
        font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.04em;
        color: var(--text-muted); border: 1px solid var(--border-dark); vertical-align: middle;
    }
    .tl-offline {
        margin-top: 6px; padding: 8px 10px; border-radius: 4px; font-size: 11px;
        background: rgba(148, 163, 184, 0.06);
        border: 1px solid var(--border-dark); border-left: 3px solid var(--text-muted);
        color: var(--text-secondary); line-height: 1.5; word-break: break-word;
    }
    .tl-offline-note { color: var(--text-muted); font-size: 10px; margin-top: 4px; }

    /* ── Pending early-finish request action card (in timeline) ──────── */
    .tl-action {
        margin-top: 8px; padding: 10px 12px; border-radius: 4px;
        background: rgba(245, 158, 11, 0.08);
        border: 1px solid var(--warning-amber);
    }
    .tl-action-label {
        font-size: 10px; font-weight: bold; text-transform: uppercase;
        letter-spacing: 0.05em; color: var(--warning-amber); margin-bottom: 8px;
    }
    .tl-action-btns { display: flex; gap: 8px; }
    .tl-btn {
        font-size: 11px; font-weight: bold; padding: 6px 16px;
        border-radius: 4px; cursor: pointer; border: 1px solid; background: transparent;
        transition: all 0.2s ease;
    }
    .tl-btn:disabled { opacity: 0.5; cursor: default; }
    .tl-btn-approve { color: var(--success-green); border-color: var(--success-green); }
    .tl-btn-approve:hover:not(:disabled) { background: var(--success-green); color: #04230f; }
    .tl-btn-reject { color: var(--error-red); border-color: var(--error-red); }
    .tl-btn-reject:hover:not(:disabled) { background: var(--error-red); color: #2a0606; }

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

    /* ── Photo verification ─────────────────────────── */
    .panel-head { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
    .panel-head .panel-title { margin-bottom: 0; flex: 1; }
    .btn-request-photo {
        font-size: 11px; font-weight: bold; padding: 6px 14px; border-radius: 4px;
        cursor: pointer; background: transparent; border: 1px solid var(--premium-gold);
        color: var(--premium-gold); transition: all 0.2s ease;
    }
    .btn-request-photo:hover:not(:disabled) { background: var(--premium-gold); color: #1a1407; }
    .btn-request-photo:disabled { opacity: 0.5; cursor: default; }

    /* Generate Report (Phase 8 · Component D) — full-width action under the
       Wakefulness Verification tally in the Compliance Summary side panel. */
    .btn-generate-report {
        width: 100%; margin-top: 16px; padding: 9px 14px; border-radius: 5px;
        font-size: 12px; font-weight: bold; cursor: pointer;
        background: var(--premium-gold); color: #1a1407; border: 1px solid var(--premium-gold);
        transition: all 0.2s ease;
    }
    .btn-generate-report:hover:not(:disabled) { filter: brightness(1.08); }
    .btn-generate-report:disabled { opacity: 0.55; cursor: default; }
    .generate-report-hint { margin-top: 5px; font-size: 10px; color: var(--text-muted, #7A828E); text-align: center; }

    .photo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }
    .photo-card {
        position: relative;
        background: var(--bg-dark); border: 1px solid var(--border-dark);
        border-radius: 4px; overflow: hidden; display: flex; flex-direction: column;
    }
    .photo-thumb {
        width: 100%; aspect-ratio: 3/4; object-fit: cover; display: block;
        background: var(--surface-dark); cursor: pointer;
    }
    .photo-thumb-empty {
        width: 100%; aspect-ratio: 3/4; display: flex; align-items: center; justify-content: center;
        background: var(--surface-dark); color: var(--text-muted); font-size: 10px; text-align: center; padding: 8px;
    }
    .photo-body { padding: 8px 9px; font-size: 10px; color: var(--text-secondary); line-height: 1.5; }
    .photo-badge {
        display: inline-block; padding: 2px 7px; border-radius: 9px; font-size: 9px;
        font-weight: bold; text-transform: uppercase; letter-spacing: 0.04em; border: 1px solid;
    }
    .photo-badge.fulfilled { color: var(--success-green); border-color: var(--success-green); }
    .photo-badge.validated { color: var(--success-green); border-color: var(--success-green); }
    .photo-badge.flagged   { color: var(--warning-amber);  border-color: var(--warning-amber); }
    .photo-badge.offline   { color: var(--warning-amber);  border-color: var(--warning-amber); }
    .photo-badge.pending   { color: var(--text-muted);     border-color: var(--border-dark); }
    .photo-badge.rejected,
    .photo-badge.anomaly,
    .photo-badge.timeout   { color: var(--error-red);      border-color: var(--error-red); }
    .photo-badge.missed    { color: var(--warning-amber);  border-color: var(--warning-amber); }
    /* Unverified scheduled checks (offline marks nothing ever fulfilled). A
       neutral amber — not a failure (guard never answered wrong), but a gap. */
    .missed-block { margin-top: 14px; padding: 10px 12px; border: 1px solid var(--warning-amber);
                    border-radius: 8px; background: rgba(245, 158, 11, 0.06); }
    .missed-block-title { font-size: 12px; font-weight: 600; color: var(--warning-amber); }
    .missed-block-sub { font-size: 11px; color: var(--text-muted); margin: 4px 0 8px; line-height: 1.4; }
    .missed-row { display: flex; align-items: center; gap: 8px; font-size: 12px; padding: 3px 0; }
    .photo-flag {
        display: block; color: var(--error-red); font-size: 9px; margin-top: 3px; word-break: break-word;
    }
    .photo-meta { color: var(--text-muted); margin-top: 4px; }
    .photo-seq {
        display: inline-block; margin-left: 6px; padding: 1px 6px; border-radius: 9px;
        font-size: 9px; font-weight: bold; color: var(--text-muted);
        border: 1px solid var(--border-dark);
    }

    /* ── Photo attempt list (one row per request) ───── */
    .attempt-list { display: flex; flex-direction: column; gap: 10px; }

    .attempt-row {
        display: flex; align-items: center; gap: 12px;
        padding: 11px 14px; border-radius: 6px;
        background: var(--bg-dark); border: 1px solid var(--border-dark);
        width: 100%; text-align: left; transition: border-color 0.15s;
    }
    .attempt-row:not(.attempt-empty) { cursor: pointer; }
    .attempt-row:not(.attempt-empty):hover { border-color: var(--premium-gold); }

    .attempt-ordinal {
        flex-shrink: 0; width: 38px; height: 38px; border-radius: 50%;
        background: var(--deep-security-blue); color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-size: 10px; font-weight: bold; letter-spacing: 0.02em;
    }
    .attempt-ordinal-empty { background: var(--border-dark) !important; color: var(--text-muted) !important; }

    .attempt-info { flex: 1; min-width: 0; }
    .attempt-info-top { font-size: 11px; font-weight: bold; color: var(--text-primary); }
    .attempt-info-sub { font-size: 10px; color: var(--text-muted); margin-top: 3px; }
    .attempt-slot-note { font-size: 9px; color: var(--text-muted); opacity: .75; margin-left: 4px; font-style: italic; }

    .attempt-thumbs { display: flex; gap: 4px; flex-shrink: 0; }
    .attempt-thumb {
        width: 38px; height: 38px; border-radius: 3px; object-fit: cover;
        border: 1px solid var(--border-dark); background: var(--surface-dark);
    }
    .attempt-thumb-more {
        width: 38px; height: 38px; border-radius: 3px;
        background: var(--border-dark); color: var(--text-muted);
        display: flex; align-items: center; justify-content: center;
        font-size: 10px; font-weight: bold;
    }
    .attempt-arrow { flex-shrink: 0; color: var(--text-muted); font-size: 20px; line-height: 1; }
    .attempt-empty-label { font-size: 10px; color: var(--text-muted); flex-shrink: 0; }

    /* Per-attempt review status chip (shown in the collection bar). */
    .attempt-review {
        flex-shrink: 0; display: inline-block; padding: 3px 9px; border-radius: 9px;
        font-size: 9px; font-weight: bold; text-transform: uppercase;
        letter-spacing: 0.04em; border: 1px solid; white-space: nowrap;
    }
    .attempt-review.ok    { color: var(--success-green); border-color: var(--success-green); }
    .attempt-review.bad   { color: var(--error-red);     border-color: var(--error-red); }
    .attempt-review.warn  { color: var(--warning-amber); border-color: var(--warning-amber); }
    .attempt-review.muted { color: var(--text-muted);    border-color: var(--border-dark); }

    /* ── Photo collection modal ─────────────────────── */
    .collection-overlay {
        display: none; position: fixed; inset: 0; z-index: 450;
        background: rgba(0,0,0,0.72); align-items: center; justify-content: center; padding: 16px;
    }
    .collection-overlay.open { display: flex; }
    .collection-modal {
        background: var(--surface-dark); border: 1.5px solid var(--border-dark);
        border-radius: 8px; padding: 20px;
        width: min(700px, calc(100vw - 32px));
        max-height: calc(100vh - 64px); overflow-y: auto;
        box-shadow: 0 24px 80px rgba(0,0,0,0.7);
        scrollbar-width: thin; scrollbar-color: var(--deep-security-blue) var(--bg-dark);
    }
    .collection-modal::-webkit-scrollbar { width: 8px; }
    .collection-modal::-webkit-scrollbar-track { background: var(--bg-dark); }
    .collection-modal::-webkit-scrollbar-thumb { background: var(--deep-security-blue); border-radius: 4px; }
    .collection-modal-header {
        display: flex; align-items: flex-start; justify-content: space-between;
        margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--border-dark);
    }
    .collection-modal-title { font-size: 13px; font-weight: bold; color: var(--text-primary); }
    .collection-modal-sub { font-size: 10px; color: var(--text-muted); margin-top: 3px; }
    .collection-modal-close {
        flex-shrink: 0; background: none; border: none; color: var(--text-muted);
        font-size: 20px; cursor: pointer; line-height: 1; padding: 0 2px; margin-left: 12px;
    }
    .collection-modal-close:hover { color: var(--text-primary); }

    /* ── Bulk review controls (collection modal header) ── */
    .collection-head-right { display: flex; align-items: flex-start; gap: 12px; flex-shrink: 0; }
    .collection-bulk {
        display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
        justify-content: flex-end; max-width: 340px;
    }
    .collection-selall {
        display: inline-flex; align-items: center; gap: 5px; white-space: nowrap;
        font-size: 10px; color: var(--text-secondary); cursor: pointer; user-select: none;
    }
    .collection-selall input { cursor: pointer; }
    .btn-bulk {
        font-size: 10px; font-weight: bold; padding: 5px 10px; border-radius: 4px;
        cursor: pointer; background: transparent; border: 1px solid;
        transition: all 0.2s ease; white-space: nowrap;
    }
    .btn-bulk-approve { border-color: var(--success-green); color: var(--success-green); }
    .btn-bulk-approve:hover:not(:disabled) { background: var(--success-green); color: #08230f; }
    .btn-bulk-reject { border-color: var(--error-red); color: var(--error-red); }
    .btn-bulk-reject:hover:not(:disabled) { background: var(--error-red); color: #fff; }
    .btn-bulk:disabled { opacity: 0.4; cursor: default; }

    /* Per-photo selection checkbox, overlaid on a reviewable thumbnail */
    .photo-check {
        position: absolute; top: 8px; left: 8px; z-index: 2;
        width: 18px; height: 18px; cursor: pointer; accent-color: var(--premium-gold);
        box-shadow: 0 0 0 2px rgba(0,0,0,0.5); border-radius: 3px;
    }
    .photo-card.selected { outline: 2px solid var(--premium-gold); outline-offset: -2px; }

    /* ── Photo review (admin decision) ──────────────── */
    .photo-review-row { margin-top: 6px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .review-badge {
        display: inline-block; padding: 2px 7px; border-radius: 9px; font-size: 9px;
        font-weight: bold; text-transform: uppercase; letter-spacing: 0.04em; border: 1px solid;
    }
    .review-badge.approved { color: var(--success-green); border-color: var(--success-green); }
    .review-badge.rejected { color: var(--error-red);     border-color: var(--error-red); }
    .review-badge.unreviewed { color: var(--text-muted);  border-color: var(--border-dark); }
    .btn-review {
        font-size: 9px; font-weight: bold; padding: 3px 9px; border-radius: 4px; cursor: pointer;
        background: transparent; border: 1px solid var(--premium-gold); color: var(--premium-gold);
        transition: all 0.2s ease;
    }
    .btn-review:hover:not(:disabled) { background: var(--premium-gold); color: #1a1407; }
    .btn-review:disabled { opacity: 0.5; cursor: default; }
    .review-note-line { color: var(--text-muted); font-size: 9px; margin-top: 3px; word-break: break-word; }

    /* Review modal */
    .review-modal-overlay {
        display: none; position: fixed; inset: 0; z-index: 500;
        background: rgba(0,0,0,0.6); align-items: center; justify-content: center;
    }
    .review-modal-overlay.open { display: flex; }
    .review-modal {
        width: 380px; max-width: calc(100vw - 32px);
        background: var(--surface-dark); border: 1.5px solid var(--border-dark);
        border-radius: 8px; padding: 18px; box-shadow: 0 20px 60px rgba(0,0,0,0.6);
    }
    .review-modal h3 { font-size: 14px; color: var(--text-primary); margin-bottom: 4px; }
    .review-modal-sub { font-size: 11px; color: var(--text-muted); margin-bottom: 14px; }
    .review-modal label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); }
    .review-modal textarea {
        width: 100%; margin-top: 6px; background: var(--bg-dark); color: var(--text-primary);
        border: 1px solid var(--border-dark); border-radius: 4px; padding: 8px; font-size: 12px;
        font-family: inherit; resize: vertical; min-height: 70px;
    }
    .review-modal-actions { display: flex; gap: 8px; margin-top: 16px; }
    .review-modal-actions .tl-btn { flex: 1; }
    .review-modal-cancel {
        font-size: 11px; color: var(--text-secondary); background: transparent;
        border: 1px solid var(--border-dark); border-radius: 4px; padding: 6px 16px; cursor: pointer;
    }
    .review-modal-cancel:hover { border-color: var(--premium-gold); color: var(--premium-gold); }

    /* ── Wakefulness verification ───────────────────── */
    .wake-warning {
        display: flex; align-items: center; gap: 10px;
        font-size: 11px; font-weight: bold; color: var(--error-red);
        border: 1px solid var(--error-red); border-radius: 4px;
        padding: 8px 10px; margin-bottom: 12px;
        background: rgba(239, 68, 68, 0.08);
    }
    .wake-warning > span { flex: 1; }
    .wake-warning-close {
        flex-shrink: 0; background: none; border: none; cursor: pointer;
        color: var(--error-red); font-size: 16px; line-height: 1;
        padding: 0 2px; opacity: 0.7; transition: opacity 0.15s;
    }
    .wake-warning-close:hover { opacity: 1; }
    .wake-table { width: 100%; border-collapse: collapse; font-size: 11px; }
    .wake-table th {
        text-align: left; color: var(--text-muted); font-weight: bold;
        text-transform: uppercase; letter-spacing: 0.04em; font-size: 9px;
        padding: 6px 8px; border-bottom: 1px solid var(--border-dark);
    }
    .wake-table td {
        padding: 7px 8px; border-bottom: 1px solid var(--border-dark);
        color: var(--text-secondary);
    }
    .wake-table tr:last-child td { border-bottom: none; }
    .wake-badge {
        display: inline-block; padding: 2px 7px; border-radius: 9px; font-size: 9px;
        font-weight: bold; text-transform: uppercase; letter-spacing: 0.04em; border: 1px solid;
    }
    .wake-badge.confirmed { color: var(--success-green); border-color: var(--success-green); }
    .wake-badge.failed    { color: var(--error-red);     border-color: var(--error-red); }
    .wake-badge.pending   { color: var(--text-muted);    border-color: var(--border-dark); }
    .wake-badge.missed    { color: var(--warning-amber); border-color: var(--warning-amber); }

    @media (max-width: 900px) {
        .detail-grid { flex-direction: column; }
        .detail-side { width: 100%; position: static; max-height: none; overflow: visible; }
    }
</style>
@endsection

@section('content')
@php
    $guard = $shift->assignedGuard;
    $guardName = $guard ? trim($guard->first_name . ' ' . $guard->last_name) : 'Unassigned';
    $tz = config('app.timezone') ?: 'UTC';
    $fmt  = fn ($dt) => $dt ? \Illuminate\Support\Carbon::parse($dt)->setTimezone($tz)->format('d M Y · g:i A') : '—';
    $time = fn ($dt) => $dt ? \Illuminate\Support\Carbon::parse($dt)->setTimezone($tz)->format('g:i:s A') : '—';
    // Emit a clean UTC ISO-8601 string for client-side local-timezone rendering.
    $iso  = fn ($dt) => $dt ? \Illuminate\Support\Carbon::parse($dt)->utc()->format('Y-m-d\TH:i:s\Z') : null;
    // Event metadata payloads echo raw ISO-8601 UTC strings (e.g. actual_end,
    // requested_at). Detect those so the card can render them in admin-local
    // time too, instead of dumping a raw "…Z" value next to local timestamps.
    $isTs = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', $v);
    // Render a decimal-hours duration as a readable "Xh Ym".
    $hm = function ($hours) {
        if ($hours === null) return '—';
        $mins = (int) round($hours * 60);
        return intdiv($mins, 60) . 'h ' . ($mins % 60) . 'm';
    };
    // Humanise a raw seconds gap (from a COMMS_GAP / SYNC_FLUSH payload) into a
    // compact "Xs" / "Xm Ys" / "Xh Ym" duration for the offline band.
    $gapHuman = function ($seconds) {
        $seconds = (int) $seconds;
        if ($seconds < 60) return $seconds . 's';
        $m = intdiv($seconds, 60); $s = $seconds % 60;
        if ($m < 60) return $m . 'm' . ($s ? ' ' . $s . 's' : '');
        $h = intdiv($m, 60); $m %= 60;
        return $h . 'h' . ($m ? ' ' . $m . 'm' : '');
    };
    // End-of-shift outcome for the compliance panel. An early end only reaches
    // end_type='early' after a supervisor approves it, so that label is the
    // "approved early finish" indicator.
    $endTypeLabel = match ($shift->end_type) {
        'early' => 'Early finish — supervisor approved',
        'auto'  => 'Auto-closed (overdue)',
        'guard' => 'Ended by guard',
        default => null,
    };
    $endTypeColor = match ($shift->end_type) {
        'early' => 'var(--warning-amber)',
        'auto'  => 'var(--error-red)',
        default => 'var(--text-primary)',
    };
    // Auto-closed shift: autoClose() credits actual_end back to scheduled_end for
    // compliance, but the guard never actually ended the shift — the system
    // force-closed it. So the Shift Details "Actual end" shows the REAL force-close
    // moment (SHIFT_AUTO_CLOSED metadata closed_at) with a red dot, rather than the
    // scheduled time, so a supervisor sees when the shift was really shut down.
    $isAutoClosed = $shift->end_type === \App\Domains\Shifts\Models\Shift::END_TYPE_AUTO;
    $autoClosedAt = null;
    if ($isAutoClosed) {
        $autoEvent = $events->firstWhere('event_type', 'SHIFT_AUTO_CLOSED');
        $autoMeta = is_array($autoEvent?->metadata) ? $autoEvent->metadata : [];
        $autoClosedAt = $autoMeta['closed_at'] ?? null;
    }
    // The time to display for "Actual end": the real force-close moment when
    // auto-closed (falling back to actual_end if the event metadata is missing),
    // otherwise the recorded actual_end.
    $actualEndDisplay = ($isAutoClosed && $autoClosedAt) ? $autoClosedAt : $shift->actual_end;
@endphp

<div class="detail-head">
    <span class="detail-ref">#{{ $shift->reference ?? '—' }}</span>
    <span class="detail-head-meta">
        <strong>{{ $guardName }}</strong>
        · {{ $shift->site->name ?? 'No site' }}
        · <time class="tl-ts-date" data-ts="{{ $iso($shift->scheduled_start) }}">{{ optional($shift->scheduled_start)->setTimezone($tz)->format('d M Y') }}</time>
    </span>
    <span class="detail-head-meta">
        <time class="tl-ts-hhmm" data-ts="{{ $iso($shift->scheduled_start) }}">{{ optional($shift->scheduled_start)->setTimezone($tz)->format('g:i A') }}</time>–<time class="tl-ts-hhmm" data-ts="{{ $iso($shift->scheduled_end) }}">{{ optional($shift->scheduled_end)->setTimezone($tz)->format('g:i A') }}</time>
    </span>
    <span class="detail-head-spacer"></span>
    <span class="detail-status-chip {{ $shift->status }}">{{ $shift->formatted_status }}</span>
    <a id="detail-back-btn" href="{{ route('admin.shifts.index') }}" class="detail-btn-back">← Back to Shifts</a>
</div>

<div class="detail-grid">
    <div class="detail-main">
        <div class="panel">
            <div class="panel-head">
                <div class="panel-title">Timeline — Append-only audit log</div>
                <nav class="tl-jump">
                    <a href="#panel-photo" data-jump="panel-photo">Photo verification</a>
                    <a href="#panel-wakefulness" data-jump="panel-wakefulness">Wakefulness verification</a>
                </nav>
            </div>
            <div class="tl">
                {{-- Fallback: the request is pending but its audit event is missing
                     (best-effort logging can fail) — still let the supervisor act.
                     Shown at the top since the timeline reads newest-first. --}}
                @if ($shift->hasPendingEarlyEnd() && !$pendingEarlyEndEventId)
                    @php $req = $shift->early_end_request; @endphp
                    <div class="tl-row">
                        <div class="tl-node">
                            <div class="tl-dot warn"></div>
                            <div class="tl-line"></div>
                        </div>
                        <div class="tl-body">
                            <div class="tl-heading">Early Finish Requested</div>
                            <div class="tl-meta"><time class="tl-ts-full" data-ts="{{ $iso($shift->early_end_requested_at) }}">{{ $fmt($shift->early_end_requested_at) }}</time> · pending</div>
                            @if (!empty($req['reason']) || !empty($req['note']))
                                <div class="tl-card">
                                    @if (!empty($req['reason']))<div>Reason: {{ $req['reason'] }}</div>@endif
                                    @if (!empty($req['note']))<div>Note: {{ $req['note'] }}</div>@endif
                                </div>
                            @endif
                            <div class="tl-action" data-shift-id="{{ $shift->id }}">
                                <div class="tl-action-label">Awaiting your decision</div>
                                <div class="tl-action-btns">
                                    <button type="button" class="tl-btn tl-btn-approve" data-action="approve">Accept</button>
                                    <button type="button" class="tl-btn tl-btn-reject" data-action="reject">Reject</button>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                @forelse ($events as $event)
                    @php
                        $meta = $eventMeta[$event->event_type] ?? ['label' => \Illuminate\Support\Str::headline(strtolower($event->event_type)), 'tone' => 'ok'];
                        $payload = is_array($event->metadata) ? $event->metadata : [];
                        $isOfflineEvent = in_array($event->event_type, $offlineEventTypes, true);
                        // Offline events are dated to when they actually happened
                        // (recorded_at), not when the backlog reached the server.
                        $offlineActualAt = $event->recorded_at ?? $event->server_received_at ?? $event->created_at;
                        // Build the offline-band sentence in PHP (not inline @if in the
                        // markup): a Blade directive glued to a word/echo — e.g.
                        // `backfilled@if(...)` or `}}@endif` — only half-compiles and
                        // breaks the template, so keep all conditionals server-side.
                        $offlineMsg = null;
                        if ($isOfflineEvent) {
                            $gapSec = $payload['gap_seconds'] ?? null;
                            if ($event->event_type === 'COMMS_GAP_START') {
                                $offlineMsg = "📡 Guard's device went offline here. Pings were buffered on-device and backfilled on reconnect.";
                            } elseif ($event->event_type === 'COMMS_GAP_END') {
                                $offlineMsg = '📡 Device reconnected'
                                    . ($gapSec !== null ? ' after ' . $gapHuman($gapSec) . ' offline' : '')
                                    . '. Buffered data has been synced.';
                            } else {
                                $pings = (int) ($payload['gps_pings_synced'] ?? 0);
                                $offlineMsg = '⟲ ' . $pings . ' GPS ' . ($pings === 1 ? 'ping' : 'pings') . ' backfilled'
                                    . ($gapSec !== null ? ' · offline ~' . $gapHuman($gapSec) : '')
                                    . '.';
                            }
                        }

                        // ZONE_EXIT: render a plain-English description plus only the
                        // fields that make sense for each flavour, instead of dumping
                        // raw metadata. Wording mirrors the supervisor alert
                        // (AlertService::createZoneExitAlert). SHIFT_START = never
                        // reached post (judged vs scheduled_start, so no exit time and
                        // grace is irrelevant); MID_SHIFT = stepped out mid-shift past
                        // the site grace (so scheduled_start is irrelevant). Display
                        // only — the immutable metadata still holds every field, so
                        // this also cleans up ZONE_EXIT rows already on file.
                        $zoneExitDesc = null;
                        $zoneExitFields = [];
                        if ($event->event_type === 'ZONE_EXIT') {
                            $zeReason = $payload['reason'] ?? 'MID_SHIFT';
                            $zeCoords = $payload['coordinates'] ?? null;
                            $zeLastKnown = is_array($zeCoords)
                                ? (($zeCoords['latitude'] ?? '?') . ', ' . ($zeCoords['longitude'] ?? '?'))
                                : null;

                            if ($zeReason === 'SHIFT_START') {
                                $zoneExitDesc = 'Guard was still outside the site boundary at their scheduled start time — never reached their post.';
                                if (!empty($payload['scheduled_start'])) {
                                    $zoneExitFields['Scheduled start'] = $payload['scheduled_start'];
                                }
                            } else {
                                $zeGrace = $payload['grace_period_minutes'] ?? null;
                                $zoneExitDesc = 'Guard left the site boundary during the shift and stayed outside past the'
                                    . ($zeGrace !== null ? ' ' . $zeGrace . '-minute' : '') . ' grace period.';
                                if (!empty($payload['exited_at'])) {
                                    $zoneExitFields['Exited zone at'] = $payload['exited_at'];
                                }
                                if ($zeGrace !== null) {
                                    $zoneExitFields['Grace period'] = $zeGrace . ' min';
                                }
                            }
                            if ($zeLastKnown !== null) {
                                $zoneExitFields['Last known location'] = $zeLastKnown;
                            }
                        }

                        // WAKEFULNESS_FAILED: deadline_at / detected_late_by_seconds are
                        // scheduler-gap diagnostics. On a healthy miss the sweep always
                        // catches it within ~one minute, so they're just noise. Show them
                        // only when they signal something — a suppressed STALE_CATCHUP, or
                        // a detection later than the staleness threshold (a real cron gap) —
                        // and round the raw float to 1dp. Display-only: the stored metadata
                        // keeps every field (same curation approach as ZONE_EXIT above).
                        if ($event->event_type === 'WAKEFULNESS_FAILED') {
                            $wfLate = $payload['detected_late_by_seconds'] ?? null;
                            $wfStale = (int) config('ironlock.catchup_staleness_seconds', 180);
                            $wfShowGap = (($payload['reason'] ?? null) === 'STALE_CATCHUP')
                                || (is_numeric($wfLate) && $wfLate > $wfStale);
                            if (!$wfShowGap) {
                                unset($payload['deadline_at'], $payload['detected_late_by_seconds']);
                            } elseif (is_numeric($wfLate)) {
                                $payload['detected_late_by_seconds'] = round((float) $wfLate, 1);
                            }
                        }

                        // SHIFT_ENDED: reason/note only carry meaning for a
                        // supervisor-approved early end. A normal end (guard ended
                        // after scheduled_end) always logs them as null, so the
                        // generic renderer would print blank "Reason:" / "Note:"
                        // lines. Drop empties so the card shows only real content
                        // (an early end keeps its reason; an omitted note stays hidden).
                        if ($event->event_type === 'SHIFT_ENDED') {
                            foreach (['reason', 'note'] as $seKey) {
                                if (empty($payload[$seKey])) {
                                    unset($payload[$seKey]);
                                }
                            }
                        }

                        // OFFLINE wakefulness (TOTP): the challenge fires on-device and
                        // the result MATERIALISES on reconnect, so the audit row's server
                        // time is the flush instant — minutes/hours after the code was
                        // actually shown. Flag the row "Offline" and render a curated card
                        // with the true on-device times (challenged / responded / duration)
                        // carried in the metadata, so it never reads as a live challenge.
                        $wfOffline = in_array($event->event_type, ['WAKEFULNESS_CHALLENGE', 'WAKEFULNESS_CONFIRMED', 'WAKEFULNESS_FAILED'], true)
                            && (($payload['mode'] ?? null) === 'OFFLINE');
                        $wfFields = [];
                        if ($wfOffline) {
                            // Curate the card per event type so the paired CHALLENGE
                            // and CONFIRMED/FAILED rows never show identical values:
                            //   • CHALLENGE  → only when the code was shown ("Challenged").
                            //   • CONFIRMED/FAILED → the response ("Responded" + duration).
                            // The response fields belong to the answer, not the ask, so
                            // the challenge row would otherwise echo the same three lines.
                            $wfIsResponse = in_array($event->event_type, ['WAKEFULNESS_CONFIRMED', 'WAKEFULNESS_FAILED'], true);
                            if (!empty($payload['scheduled_at'])) { $wfFields['Challenged'] = $payload['scheduled_at']; }
                            if ($wfIsResponse) {
                                if (!empty($payload['responded_at'])) { $wfFields['Responded'] = $payload['responded_at']; }
                                if (isset($payload['response_time_seconds']) && $payload['response_time_seconds'] !== null && $payload['response_time_seconds'] !== '') {
                                    $wfFields['Response duration'] = $payload['response_time_seconds'] . 's';
                                }
                            }
                            if (!empty($payload['reason'])) { $wfFields['Reason'] = $payload['reason']; }
                            if (!empty($payload['check_id'])) { $wfFields['Check Id'] = $payload['check_id']; }
                        }
                    @endphp
                    <div class="tl-row">
                        <div class="tl-node">
                            <div class="tl-dot {{ $meta['tone'] }}"></div>
                            <div class="tl-line"></div>
                        </div>
                        <div class="tl-body">
                            <div class="tl-heading">
                                {{ $meta['label'] }}
                                @if ($isOfflineEvent)<span class="tl-offline-badge">⟲ Backfilled</span>@endif
                                @if ($wfOffline)<span class="tl-offline-badge">Offline</span>@endif
                            </div>
                            @if ($isOfflineEvent)
                                {{-- Offline / backfilled band. Dated to when it actually
                                     happened (recorded_at), with the reconnect/sync time
                                     shown separately so a gap reads as "offline, later
                                     synced" — never a live incident (Phase 7 §7.3). --}}
                                <div class="tl-meta">
                                    <time class="tl-ts-full" data-ts="{{ $iso($offlineActualAt) }}">{{ $fmt($offlineActualAt) }}</time>
                                    · actual time · synced <time class="tl-ts" data-ts="{{ $iso($event->server_received_at ?? $event->created_at) }}">{{ $time($event->server_received_at ?? $event->created_at) }}</time>
                                </div>
                                <div class="tl-offline">
                                    {{ $offlineMsg }}
                                    @if (!empty($payload['note']))
                                        <div class="tl-offline-note">{{ $payload['note'] }}</div>
                                    @endif
                                </div>
                            @else
                                <div class="tl-meta">
                                    <time class="tl-ts" data-ts="{{ $iso($event->server_received_at ?? $event->created_at) }}">{{ $time($event->server_received_at ?? $event->created_at) }}</time> · server time · {{ $event->event_type }}
                                </div>
                                @if ($event->event_type === 'ZONE_EXIT')
                                    <div class="tl-card">
                                        <div class="tl-zone-desc">{{ $zoneExitDesc }}</div>
                                        @foreach ($zoneExitFields as $k => $v)
                                            <div>{{ $k }}:
                                                @if ($isTs($v))
                                                    <time class="tl-ts-full" data-ts="{{ $iso($v) }}">{{ $fmt($v) }}</time>
                                                @else
                                                    {{ $v }}
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @elseif ($wfOffline && !empty($wfFields))
                                    {{-- Curated offline-wakefulness card: the true on-device
                                         challenge/response times (metadata), not the flush
                                         instant in the server-time line above. --}}
                                    <div class="tl-card">
                                        @foreach ($wfFields as $k => $v)
                                            <div>{{ $k }}:
                                                @if ($isTs($v))
                                                    <time class="tl-ts-full" data-ts="{{ $iso($v) }}">{{ $fmt($v) }}</time>
                                                @else
                                                    {{ $v }}
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @elseif (!empty($payload))
                                    <div class="tl-card">
                                        @foreach ($payload as $k => $v)
                                            @php $v = is_bool($v) ? ($v ? 'yes' : 'no') : (is_array($v) ? json_encode($v) : $v); @endphp
                                            <div>{{ \Illuminate\Support\Str::headline($k) }}:
                                                @if ($isTs($v))
                                                    <time class="tl-ts-full" data-ts="{{ $iso($v) }}">{{ $fmt($v) }}</time>
                                                @else
                                                    {{ $v }}
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            @endif
                            @if ($event->id === $pendingEarlyEndEventId)
                                <div class="tl-action" data-shift-id="{{ $shift->id }}">
                                    <div class="tl-action-label">Awaiting your decision</div>
                                    <div class="tl-action-btns">
                                        <button type="button" class="tl-btn tl-btn-approve" data-action="approve">Accept</button>
                                        <button type="button" class="tl-btn tl-btn-reject" data-action="reject">Reject</button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="tl-empty">No events recorded for this shift yet.</div>
                @endforelse
            </div>
        </div>

        <div class="panel" id="panel-photo">
            <div class="panel-head">
                <div class="panel-title">Photo Verification</div>
                @if ($shift->status === 'active')
                    <button type="button" id="btn-request-photo" class="btn-request-photo" data-shift-id="{{ $shift->id }}">
                        📷 Request Photo
                    </button>
                @endif
            </div>

            {{-- Each row = one attempt (photo request). Click a fulfilled attempt to
                 open the collection modal and see all its images with review controls.
                 Unfulfilled requests (PENDING / TIMEOUT / ANOMALY) show inline. --}}
            @if ($photoRequests->isEmpty())
                <div class="placeholder-note">
                    No photo checks have been requested for this shift yet.
                    @if ($shift->status === 'active')Use "Request Photo" above to ask the guard for a live photo now.@endif
                </div>
            @else
                @php $ordinals = ['1st','2nd','3rd','4th','5th']; @endphp
                <div class="attempt-list">
                    @foreach ($photoRequests as $prIdx => $pr)
                        @php
                            $ordLabel   = $ordinals[$prIdx] ?? (($prIdx + 1) . 'th');
                            $hasImages  = !empty($pr['evidences']);
                            $reqBadge   = strtolower($pr['status'] ?? 'pending');
                            // Offline tag parity with the wakefulness Mode column: an
                            // OFFLINE_POOL capture flushed on reconnect reads "Offline".
                            $capturedOffline = (bool) ($pr['captured_offline'] ?? false);
                            // Offline captures re-anchor requested_at to the scheduled
                            // slot; the real moment the guard took the photo is
                            // submitted_at. Headline that exact capture time (slot kept
                            // as a small secondary note) so it isn't buried behind the
                            // slot. Online rows keep requested_at — capture is seconds
                            // later, so the two are effectively the same time.
                            $slotTs       = $pr['requested_at'] ?? null;
                            $captureTs    = $pr['submitted_at'] ?? null;
                            $offlineSplit = $capturedOffline && $captureTs && $slotTs && $captureTs !== $slotTs;
                            $primaryTs    = $offlineSplit ? $captureTs : $slotTs;
                            $previewEvs = array_slice($pr['evidences'] ?? [], 0, 3);
                            $imgCount   = $pr['image_count'] ?? 0;
                            $moreCount  = max(0, $imgCount - 3);

                            // Per-attempt review tally → status chip in the collection bar.
                            $evs       = collect($pr['evidences'] ?? []);
                            $revCount  = $evs->whereNotNull('review_decision')->count();
                            $apprCount = $evs->where('review_decision', 'APPROVED')->count();
                            $rejCount  = $evs->where('review_decision', 'REJECTED')->count();
                            if ($revCount === 0) {
                                $reviewClass = 'muted'; $reviewText = 'Unreviewed';
                            } elseif ($revCount < $imgCount) {
                                $reviewClass = 'warn'; $reviewText = $revCount . '/' . $imgCount . ' reviewed';
                            } elseif ($rejCount > 0) {
                                $reviewClass = 'bad'; $reviewText = '✗ ' . $rejCount . ' rejected';
                            } else {
                                $reviewClass = 'ok'; $reviewText = '✓ Reviewed';
                            }
                        @endphp
                        <div class="attempt-row {{ !$hasImages ? 'attempt-empty' : '' }}"
                            @if ($hasImages)
                                role="button" tabindex="0"
                                onclick="openCollection({{ $prIdx }})"
                                onkeydown="if(event.key==='Enter'||event.key===' ')openCollection({{ $prIdx }})"
                            @endif>
                            <div class="attempt-ordinal {{ !$hasImages ? 'attempt-ordinal-empty' : '' }}">{{ $ordLabel }}</div>
                            <div class="attempt-info">
                                <div class="attempt-info-top">
                                    {{ $ordLabel }} Attempt
                                    @if ($hasImages)
                                        &nbsp;·&nbsp;{{ $pr['image_count'] }} {{ $pr['image_count'] === 1 ? 'image' : 'images' }}
                                    @endif
                                </div>
                                <div class="attempt-info-sub">
                                    {{ ucfirst($pr['request_type'] ?? 'manual') }}
                                    @if ($capturedOffline)<span class="photo-badge offline" style="font-size:8px;padding:1px 5px;margin-left:4px;">Offline</span>@endif
                                    &nbsp;·&nbsp;<time class="tl-ts-full" data-ts="{{ $iso($primaryTs) }}">{{ $fmt($primaryTs) }}</time>
                                    @if ($offlineSplit)<span class="attempt-slot-note">captured · scheduled <time class="tl-ts-hhmm" data-ts="{{ $iso($slotTs) }}">{{ \Illuminate\Support\Carbon::parse($slotTs)->setTimezone($tz)->format('g:i A') }}</time></span>@endif
                                    &nbsp;·&nbsp;<span class="photo-badge {{ $reqBadge }}" style="font-size:8px;padding:1px 5px;">{{ str_replace('_', ' ', $reqBadge) }}</span>
                                </div>
                            </div>
                            @if ($hasImages)
                                <span class="attempt-review {{ $reviewClass }}">{{ $reviewText }}</span>
                                <div class="attempt-thumbs">
                                    @foreach ($previewEvs as $ev)
                                        <img class="attempt-thumb" src="{{ $ev['view_url'] }}" alt="" loading="lazy">
                                    @endforeach
                                    @if ($moreCount > 0)
                                        <div class="attempt-thumb-more">+{{ $moreCount }}</div>
                                    @endif
                                </div>
                                <span class="attempt-arrow">›</span>
                            @else
                                <span class="attempt-empty-label">
                                    {{ $pr['status'] === 'PENDING' ? 'Awaiting response…' : 'No image received' }}
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- Serialised for the collection modal JS. Contains only pre-computed
                     server-generated values (route URLs, formatted data — no raw model
                     fields are exposed beyond what the page already renders). --}}
                <script>window.photoAttempts = @json($photoRequests->values());</script>
            @endif

            {{-- Missed scheduled photo checks: provisioned marks that came due
                 while the guard was offline and were never fulfilled by an online
                 request OR an offline capture. Reconciled live (a late flush that
                 fills a mark drops it from this list automatically). --}}
            @php $missedPhotos = $missedChecks['photos'] ?? []; @endphp
            @if (!empty($missedPhotos))
                <div class="missed-block">
                    <div class="missed-block-title">⚠ {{ count($missedPhotos) }} scheduled photo {{ count($missedPhotos) === 1 ? 'check' : 'checks' }} not fulfilled</div>
                    <div class="missed-block-sub">These marks came due while the guard's device was offline and no photo was captured or flushed on reconnect.</div>
                    @foreach ($missedPhotos as $mk)
                        <div class="missed-row">
                            <span class="photo-badge missed" style="font-size:9px; padding:1px 6px;">Missed</span>
                            <time class="tl-ts-full" data-ts="{{ $iso($mk) }}">{{ $fmt($mk) }}</time>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="panel" id="panel-wakefulness">
            <div class="panel-head">
                <div class="panel-title">Wakefulness Verification</div>
                @if ($shift->status === 'active')
                    <button type="button" id="btn-request-wakefulness" class="btn-request-photo" data-shift-id="{{ $shift->id }}">
                        🛌 Request Wakefulness Check
                    </button>
                @endif
            </div>

            {{-- Each row is one code-challenge. A null result is still pending
                 (awaiting the guard or the timeout sweep); CONFIRMED/FAILED are
                 the recorded outcomes. A FAILED check also raises a CRITICAL
                 GUARD_UNRESPONSIVE alert and surfaces a welfare-check warning. --}}
            @if ($wakefulnessChecks->isEmpty())
                <div class="placeholder-note">
                    No wakefulness checks have been issued for this shift yet.
                    Challenges fire automatically on a randomised schedule while the guard is on duty.
                    @if ($shift->status === 'active')Use "Request Wakefulness Check" above to challenge the guard now.@endif
                </div>
            @else
                {{-- The CRITICAL welfare banner fires only for a genuine
                     unresponsive-guard failure (one that actually paged a
                     supervisor). A miss suppressed as a connectivity/delivery gap
                     (failure_alerted === false) is recorded in the table with its
                     reason but is NOT a welfare incident, so it doesn't raise this. --}}
                @if ($wakefulnessChecks->contains(fn ($c) => $c['result'] === 'FAILED' && ($c['failure_alerted'] ?? true) !== false))
                    <div class="wake-warning">
                        <span>⚠ Unresponsive — Welfare Check Required (a wakefulness check failed)</span>
                        <button type="button" class="wake-warning-close" aria-label="Dismiss" title="Dismiss" onclick="this.closest('.wake-warning').remove()">&times;</button>
                    </div>
                @endif
                <table class="wake-table">
                    <thead>
                        <tr><th>Result</th><th>Source</th><th>Mode</th><th>Challenged</th><th>Responded</th><th>Time</th><th>Detail</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($wakefulnessChecks as $wc)
                            @php $badge = strtolower($wc['result'] ?? 'pending'); @endphp
                            <tr>
                                <td><span class="wake-badge {{ $badge }}">{{ $wc['result'] ?? 'PENDING' }}</span></td>
                                <td>{{ ucfirst($wc['request_type'] ?? 'scheduled') }}</td>
                                <td style="color: {{ !empty($wc['is_offline']) ? 'var(--warning-amber)' : 'inherit' }};">{{ ucfirst(strtolower($wc['mode'] ?? '—')) }}</td>
                                <td><time class="tl-ts-full" data-ts="{{ $iso($wc['scheduled_at']) }}">{{ $fmt($wc['scheduled_at']) }}</time></td>
                                <td><time class="tl-ts-full" data-ts="{{ $iso($wc['responded_at']) }}">{{ $fmt($wc['responded_at']) }}</time></td>
                                <td>{{ $wc['response_time_seconds'] !== null ? $wc['response_time_seconds'] . 's' : '—' }}</td>
                                {{-- Failure cause. A suppressed (not-paged) miss is amber
                                     "info"; a paged failure is red — never a raw code. --}}
                                <td style="color: {{ empty($wc['failure_reason']) ? 'var(--text-muted)' : (($wc['failure_alerted'] ?? true) === false ? 'var(--warning-amber)' : 'var(--error-red)') }};">
                                    {{ $wc['failure_reason'] ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            {{-- Missed scheduled wakefulness checks: provisioned challenge marks
                 that came due while the guard was offline and were never answered
                 by an online challenge OR flushed as an offline TOTP response.
                 Neutral "Missed" (the check never ran) — not a FAILED code. --}}
            @php $missedWake = $missedChecks['wakefulness'] ?? []; @endphp
            @if (!empty($missedWake))
                <div class="missed-block">
                    <div class="missed-block-title">⚠ {{ count($missedWake) }} scheduled wakefulness {{ count($missedWake) === 1 ? 'check' : 'checks' }} not fulfilled</div>
                    <div class="missed-block-sub">These challenges came due while the guard's device was offline and no response was recorded or flushed on reconnect.</div>
                    @foreach ($missedWake as $mk)
                        <div class="missed-row">
                            <span class="wake-badge missed">Missed</span>
                            <time class="tl-ts-full" data-ts="{{ $iso($mk) }}">{{ $fmt($mk) }}</time>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="detail-side">
        <div class="panel">
            <div class="panel-title">Shift Details</div>
            <div class="sum-row"><span class="sum-label">Reference</span><span class="sum-val">#{{ $shift->reference ?? '—' }}</span></div>
            <div class="sum-row"><span class="sum-label">Guard</span><span class="sum-val">{{ $guardName }}</span></div>
            <div class="sum-row"><span class="sum-label">Site</span><span class="sum-val">{{ $shift->site->name ?? '—' }}</span></div>
            <div class="sum-row"><span class="sum-label">Scheduled</span><span class="sum-val"><time class="tl-ts-full" data-ts="{{ $iso($shift->scheduled_start) }}">{{ $fmt($shift->scheduled_start) }}</time></span></div>
            <div class="sum-row"><span class="sum-label">Sched. end</span><span class="sum-val"><time class="tl-ts-full" data-ts="{{ $iso($shift->scheduled_end) }}">{{ $fmt($shift->scheduled_end) }}</time></span></div>
            <div class="sum-row"><span class="sum-label">Actual start</span><span class="sum-val"><time class="tl-ts-full" data-ts="{{ $iso($shift->actual_start) }}">{{ $fmt($shift->actual_start) }}</time></span></div>
            <div class="sum-row"><span class="sum-label">Actual end</span><span class="sum-val"><time class="tl-ts-full" data-ts="{{ $iso($actualEndDisplay) }}">{{ $fmt($actualEndDisplay) }}</time>@if ($isAutoClosed)<span class="auto-close-dot" title="Auto-closed (overdue) — the guard didn't end the shift; the system force-closed it"></span>@endif</span></div>
            @php
                $gpsCov = $gpsSummary['coverage_percent'] ?? null;
                $gpsHint = $gpsCov === null
                    ? 'Available once the shift has started.'
                    : 'Share of the active shift the guard was confirmed on-post — inside the geofence with live GPS. Deducts time outside the zone and offline/comms gaps.';
            @endphp
            <div class="sum-row" title="{{ $gpsHint }}">
                <span class="sum-label">GPS coverage</span>
                <span class="sum-val">{{ $gpsCov === null ? '—' : number_format((float) $gpsCov, 1) . '%' }}</span>
            </div>
            @php
                // Total time the guard's device was offline during the shift —
                // the sum of COMMS_GAP durations already computed for GPS coverage
                // (gpsCoverage deducts exactly this). Surfaced here so the offline
                // gap is visible at a glance, not only implied by the coverage %.
                $gpsGapSec = $gpsSummary['gap_seconds'] ?? null;
                $gpsGapCount = (int) ($gpsSummary['gap_count'] ?? 0);
            @endphp
            <div class="sum-row" title="Total time the guard's device spent offline (buffered comms gaps), summed across the shift. This time is excluded from GPS coverage.">
                <span class="sum-label">Time offline</span>
                <span class="sum-val">
                    @if ($gpsCov === null || $gpsGapSec === null)
                        —
                    @elseif ($gpsGapSec <= 0)
                        None
                    @else
                        {{ $gapHuman($gpsGapSec) }}@if ($gpsGapCount > 0) <span style="color: var(--text-muted);">· {{ $gpsGapCount }} {{ $gpsGapCount === 1 ? 'gap' : 'gaps' }}</span>@endif
                    @endif
                </span>
            </div>
        </div>

        <div class="panel">
            <div class="panel-title">Compliance Summary</div>
            @if ($summary)
                <div class="sum-row">
                    <span class="sum-label">WTR duration</span>
                    <span class="sum-val">
                        {{ isset($summary['shift_duration']['actual_hours']) ? $hm($summary['shift_duration']['actual_hours']) : '—' }}
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
                @if ($endTypeLabel)
                    <div class="sum-row">
                        <span class="sum-label">End type</span>
                        <span class="sum-val" style="color: {{ $endTypeColor }};">{{ $endTypeLabel }}</span>
                    </div>
                @endif
            @else
                <div class="placeholder-note">
                    The compliance summary is generated when the shift is ended. It will show
                    WTR duration, attendance and (in later phases) GPS coverage, wakefulness and
                    photo results.
                </div>
            @endif

            {{-- Photo verification tally — always shown (computed live, so it
                 stays current even when reviews happen after the shift ends). --}}
            <div class="panel-title" style="margin-top:16px;">Photo Verification</div>
            @if (($photoSummary['total'] ?? 0) > 0)
                @php $unfulfilled = max(0, ($photoSummary['total'] ?? 0) - ($photoSummary['fulfilled'] ?? 0)); @endphp
                <div class="sum-row">
                    <span class="sum-label">Requests</span>
                    <span class="sum-val">
                        {{ $photoSummary['total'] }}@if ($unfulfilled > 0)<span style="color: var(--warning-amber);"> ({{ $unfulfilled }} unfulfilled)</span>@endif
                    </span>
                </div>
                <div class="sum-row"><span class="sum-label">Images</span><span class="sum-val">{{ $photoSummary['images'] ?? 0 }}</span></div>
                <div class="sum-row"><span class="sum-label">Reviewed</span><span class="sum-val">{{ $photoSummary['reviewed'] }} / {{ $photoSummary['images'] ?? 0 }}</span></div>
                <div class="sum-row">
                    <span class="sum-label">Decision</span>
                    <span class="sum-val">
                        <span style="color: var(--success-green);">{{ $photoSummary['approved'] }} ✓</span>
                        / <span style="color: var(--error-red);">{{ $photoSummary['rejected'] }} ✗</span>
                    </span>
                </div>
            @else
                <div class="placeholder-note">No photos have been submitted for this shift.</div>
            @endif

            {{-- Wakefulness verification tally — always shown (computed live, so a
                 pending check that resolves via the timeout sweep stays current).
                 No review step: the result is the server's own CONFIRMED/FAILED. --}}
            <div class="panel-title" style="margin-top:16px;">Wakefulness Verification</div>
            @if (($wakefulnessSummary['total'] ?? 0) > 0)
                <div class="sum-row"><span class="sum-label">Challenges</span><span class="sum-val">{{ $wakefulnessSummary['total'] }}</span></div>
                <div class="sum-row"><span class="sum-label">Confirmed</span><span class="sum-val" style="color: var(--success-green);">{{ $wakefulnessSummary['confirmed'] }}</span></div>
                <div class="sum-row"><span class="sum-label">Failed</span><span class="sum-val" style="color: var(--error-red);">{{ $wakefulnessSummary['failed'] }}</span></div>
                <div class="sum-row"><span class="sum-label">Pending</span><span class="sum-val">{{ $wakefulnessSummary['pending'] }}</span></div>
            @else
                <div class="placeholder-note">No wakefulness checks have been issued for this shift.</div>
            @endif

            {{-- Generate Report (Phase 8 · Component D) — one-click Shift Welfare
                 Report (REP-002) for this shift. Builds + persists a PDF and
                 downloads it; also lands in Reports → Previous Exports. --}}
            <button type="button" id="btn-generate-report" class="btn-generate-report"
                    data-shift-id="{{ $shift->id }}">
                ⤓ Generate Report
            </button>
            <div class="generate-report-hint">Shift Welfare Report <br> (download PDF or CSV there)</div>
        </div>
    </div>
</div>

{{-- Collection modal — all images for one attempt, opened by clicking an attempt row --}}
<div class="collection-overlay" id="collection-overlay">
    <div class="collection-modal">
        <div class="collection-modal-header">
            <div>
                <div class="collection-modal-title" id="collection-title"></div>
                <div class="collection-modal-sub" id="collection-sub"></div>
            </div>
            <div class="collection-head-right">
                {{-- Bulk review — acts on the checked photos via the same per-photo
                     endpoint. Hidden when no photo in this attempt is reviewable. --}}
                <div class="collection-bulk" id="collection-bulk" style="display:none;">
                    <label class="collection-selall">
                        <input type="checkbox" id="collection-selectall"> Select all
                    </label>
                    <button type="button" class="btn-bulk btn-bulk-approve" id="bulk-approve" disabled>Approve selected</button>
                    <button type="button" class="btn-bulk btn-bulk-reject" id="bulk-reject" disabled>Reject selected</button>
                </div>
                <button class="collection-modal-close" onclick="closeCollection()" title="Close">&times;</button>
            </div>
        </div>
        <div class="photo-grid" id="collection-grid"></div>
    </div>
</div>

{{-- Review modal — approve/reject a single photo with an optional note --}}
<div class="review-modal-overlay" id="review-modal-overlay">
    <div class="review-modal">
        <h3>Review Photo</h3>
        <div class="review-modal-sub">Approve or reject this verification photo. The guard is notified of the outcome.</div>
        <label for="review-note">Note (optional)</label>
        <textarea id="review-note" maxlength="500" placeholder="Add context for this decision…"></textarea>
        <div class="review-modal-actions">
            <button type="button" class="tl-btn tl-btn-approve" data-decision="approve">Approve</button>
            <button type="button" class="tl-btn tl-btn-reject" data-decision="reject">Reject</button>
            <button type="button" class="review-modal-cancel" id="review-modal-cancel">Cancel</button>
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

// ── Approve / reject a pending early-finish request ──────────────────
// The server is the authority on early ends: only an approved request lets
// the guard's app finish the shift early. On success we reload so the
// timeline shows the recorded decision and the buttons disappear.
(function () {
    var token = document.querySelector('meta[name="csrf-token"]');
    token = token ? token.content : '';

    document.querySelectorAll('.tl-action .tl-btn').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            var wrap = btn.closest('.tl-action');
            if (!wrap) return;
            var shiftId = wrap.dataset.shiftId;
            var action = btn.dataset.action; // 'approve' | 'reject'

            if (action === 'reject' && !confirm('Reject this early-finish request? The guard will keep working to the scheduled end.')) {
                return;
            }
            if (action === 'approve' && !confirm('Approve this early finish? The guard will be able to end the shift now.')) {
                return;
            }

            var btns = wrap.querySelectorAll('.tl-btn');
            btns.forEach(function (b) { b.disabled = true; });

            try {
                var res = await fetch('/admin/shifts/' + shiftId + '/early-end/' + action, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': token
                    }
                });
                var data = await res.json();

                if (!data.success) {
                    alert(data.error || 'Unable to update the request.');
                    btns.forEach(function (b) { b.disabled = false; });
                    return;
                }

                window.location.reload();
            } catch (e) {
                console.error('Early-end decision error:', e);
                alert('Something went wrong. Please try again.');
                btns.forEach(function (b) { b.disabled = false; });
            }
        });
    });
})();

// ── Request a live verification photo ───────────────────────────────
// Issues a fresh nonce + request server-side and (best-effort) pushes it
// to the guard. The guard has 90s to respond before it times out. We
// reload after a short delay so any immediate PHOTO_REQUEST event shows.
(function () {
    var btn = document.getElementById('btn-request-photo');
    if (!btn) return;

    var token = document.querySelector('meta[name="csrf-token"]');
    token = token ? token.content : '';

    btn.addEventListener('click', async function () {
        var shiftId = btn.dataset.shiftId;
        var original = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Requesting…';

        try {
            var res = await fetch('/admin/shifts/' + shiftId + '/request-photo', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token
                }
            });
            var data = await res.json();

            if (!data.success) {
                alert(data.error || 'Unable to request a photo.');
                btn.disabled = false;
                btn.textContent = original;
                return;
            }

            btn.textContent = 'Requested ✓';
            setTimeout(function () { window.location.reload(); }, 1200);
        } catch (e) {
            console.error('Photo request error:', e);
            alert('Something went wrong. Please try again.');
            btn.disabled = false;
            btn.textContent = original;
        }
    });
})();

// ── Request a wakefulness code-challenge ─────────────────────────────
// Dispatches an ONLINE check server-side and (best-effort) pushes it to the
// guard, who must transcribe the code within the response window before it
// times out and raises a CRITICAL alert. Reload after a short delay so the
// new WAKEFULNESS_CHALLENGE event + pending row show.
(function () {
    var btn = document.getElementById('btn-request-wakefulness');
    if (!btn) return;

    var token = document.querySelector('meta[name="csrf-token"]');
    token = token ? token.content : '';

    btn.addEventListener('click', async function () {
        var shiftId = btn.dataset.shiftId;
        var original = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Requesting…';

        try {
            var res = await fetch('/admin/shifts/' + shiftId + '/request-wakefulness', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token
                }
            });
            var data = await res.json();

            if (!data.success) {
                alert(data.error || 'Unable to request a wakefulness check.');
                btn.disabled = false;
                btn.textContent = original;
                return;
            }

            btn.textContent = 'Requested ✓';
            setTimeout(function () { window.location.reload(); }, 1200);
        } catch (e) {
            console.error('Wakefulness request error:', e);
            alert('Something went wrong. Please try again.');
            btn.disabled = false;
            btn.textContent = original;
        }
    });
})();

// ── Generate Shift Welfare Report (Phase 8 · Component D) ─────────────
// POSTs to build + persist the shift's Welfare Report, shows a 0→100% progress
// count, then opens its on-screen report page (download PDF/CSV/evidence there).
// The report also appears in Reports → Previous Exports.
(function () {
    var btn = document.getElementById('btn-generate-report');
    if (!btn) return;

    var token = document.querySelector('meta[name="csrf-token"]');
    token = token ? token.content : '';

    btn.addEventListener('click', async function () {
        var shiftId = btn.dataset.shiftId;
        var original = btn.textContent;
        btn.disabled = true;

        // Count a progress percentage up toward 90% while the report builds.
        var pct = 0;
        btn.textContent = 'Generating… 0%';
        var timer = setInterval(function () {
            pct += Math.max(1, (88 - pct) * 0.12);
            if (pct >= 90) pct = 90;
            btn.textContent = 'Generating… ' + Math.round(pct) + '%';
        }, 120);

        try {
            var res = await fetch('/admin/shifts/' + shiftId + '/report', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token
                }
            });
            var data = await res.json();

            if (!data.success || !data.report) {
                clearInterval(timer);
                alert(data.error || 'Unable to generate the report.');
                btn.disabled = false;
                btn.textContent = original;
                return;
            }

            // Complete the bar, then open the on-screen report page.
            clearInterval(timer);
            btn.textContent = 'Generated ✓ 100%';
            setTimeout(function () { window.location = data.report.view_url; }, 350);
        } catch (e) {
            clearInterval(timer);
            console.error('Report generation error:', e);
            alert('Something went wrong. Please try again.');
            btn.disabled = false;
            btn.textContent = original;
        }
    });
})();

// ── Photo collection modal ───────────────────────────────────────────
// Populated on-demand from window.photoAttempts (set by the blade).
// Review buttons inside the modal delegate to window.openReview, which is
// exposed by the review-modal IIFE below.
(function () {
    var overlay = document.getElementById('collection-overlay');
    if (!overlay) return;

    var ORDINALS = ['1st','2nd','3rd','4th','5th'];
    var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    function pad(n) { return String(n).padStart(2, '0'); }
    function hr12(d) { var h = d.getHours() % 12; return h === 0 ? 12 : h; }
    function ampm(d) { return d.getHours() >= 12 ? 'PM' : 'AM'; }
    function fmtFull(ts) {
        if (!ts) return '—';
        var d = new Date(ts);
        if (isNaN(d)) return String(ts);
        return pad(d.getDate()) + ' ' + MONTHS[d.getMonth()] + ' ' + d.getFullYear()
             + ' · ' + hr12(d) + ':' + pad(d.getMinutes()) + ' ' + ampm(d);
    }
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    window.openCollection = function (idx) {
        var attempts = window.photoAttempts || [];
        var pr = attempts[idx];
        if (!pr || !pr.evidences || !pr.evidences.length) return;

        var ordinal = ORDINALS[idx] || ((idx + 1) + 'th');
        var count   = pr.image_count || pr.evidences.length;

        document.getElementById('collection-title').textContent =
            ordinal + ' Attempt — ' + count + (count === 1 ? ' Image' : ' Images');
        // requested_at is the slot the check was due (the time shown in the
        // attempt list); submitted_at is the true on-device capture. For offline
        // captures these can differ by minutes — the offline nonce has no live
        // response window, so a photo taken well after its slot is still valid.
        // Label both when they differ so the card never contradicts the list.
        var reqType = pr.request_type
            ? pr.request_type.charAt(0).toUpperCase() + pr.request_type.slice(1)
            : 'Manual';
        var capturedAt = pr.submitted_at || pr.requested_at;
        var subLine = reqType;
        if (pr.submitted_at && pr.requested_at && pr.submitted_at !== pr.requested_at) {
            subLine += ' · requested ' + fmtFull(pr.requested_at) + ' · captured ' + fmtFull(pr.submitted_at);
        } else {
            subLine += ' · captured ' + fmtFull(capturedAt);
        }
        document.getElementById('collection-sub').textContent = subLine;

        var grid = document.getElementById('collection-grid');
        grid.innerHTML = '';

        pr.evidences.forEach(function (ev, i) {
            var badge   = String(ev.evidence_status || 'validated').toLowerCase();
            var seqChip = count > 1
                ? '<span class="photo-seq">' + (i + 1) + ' / ' + count + '</span>'
                : '';
            var capLine = '<div class="photo-meta">'
                + fmtFull(ev.captured_at || pr.submitted_at || pr.requested_at) + '</div>';
            var gps = ev.gps_latitude && ev.gps_longitude
                ? '<div class="photo-meta">📍 '
                    + parseFloat(ev.gps_latitude).toFixed(5) + ', '
                    + parseFloat(ev.gps_longitude).toFixed(5) + '</div>'
                : '';
            var flags = (ev.flags || []).map(function (f) {
                return '<span class="photo-flag">⚠ ' + esc(String(f).replace(/_/g, ' ')) + '</span>';
            }).join('');

            var reviewHtml;
            if (ev.review_decision) {
                reviewHtml = '<span class="review-badge ' + esc(ev.review_decision.toLowerCase()) + '">'
                    + esc(ev.review_decision) + '</span>'
                    + '<span class="photo-meta"> reviewed ' + fmtFull(ev.reviewed_at) + '</span>';
                if (ev.review_note) {
                    reviewHtml += '<div class="review-note-line">Note: ' + esc(ev.review_note) + '</div>';
                }
            } else {
                reviewHtml = '<span class="review-badge unreviewed">Unreviewed</span>'
                    + (ev.can_review
                        ? '<button type="button" class="btn-review" data-review-url="'
                            + esc(ev.review_url) + '">Review</button>'
                        : '');
            }

            // A photo is selectable for bulk review only while it is still
            // unreviewed AND reviewable — mirrors the per-photo Review button and
            // the server's idempotent "reviewed once" rule.
            var isReviewable = !ev.review_decision && ev.can_review && ev.review_url;
            var checkHtml = isReviewable
                ? '<input type="checkbox" class="photo-check" data-review-url="'
                    + esc(ev.review_url) + '" title="Select for bulk review">'
                : '';

            var card = document.createElement('div');
            card.className = 'photo-card';
            card.innerHTML =
                checkHtml
                + '<img class="photo-thumb" src="' + esc(ev.view_url) + '" alt="Photo ' + (i + 1) + '"'
                + ' onclick="window.open(this.src,\'_blank\')" loading="lazy">'
                + '<div class="photo-body">'
                + '<span class="photo-badge ' + esc(badge) + '">' + esc(badge.replace(/_/g, ' ')) + '</span>'
                + seqChip + capLine + gps + flags
                + '<div class="photo-review-row">' + reviewHtml + '</div>'
                + '</div>';
            grid.appendChild(card);
        });

        // Bulk controls: visible only when this attempt still has reviewable
        // photos. Reset selection state each time the modal opens.
        var bulkBarEl = document.getElementById('collection-bulk');
        if (bulkBarEl) bulkBarEl.style.display = grid.querySelector('.photo-check') ? 'flex' : 'none';
        var selEl = document.getElementById('collection-selectall');
        if (selEl) selEl.checked = false;
        syncBulkButtons();

        overlay.classList.add('open');
    };

    window.closeCollection = function () {
        overlay.classList.remove('open');
    };

    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) window.closeCollection();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('open')) window.closeCollection();
    });

    // Delegate Review button clicks inside the collection grid to the review
    // modal (opened on top — review modal z-index 500 > collection z-index 450).
    overlay.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-review[data-review-url]');
        if (btn && window.openReview) window.openReview(btn.dataset.reviewUrl);
    });

    // ── Bulk review (checkbox selection inside the collection modal) ──────
    // "Approve/Reject selected" fans out to the SAME per-photo endpoint as the
    // single-review flow — one POST per checked photo. No new backend: each
    // photo still gets its own immutable PhotoReview row, audit event and guard
    // push, and the server's "reviewed once" idempotency is preserved.
    var bulkApprove = document.getElementById('bulk-approve');
    var bulkReject  = document.getElementById('bulk-reject');
    var selAllEl    = document.getElementById('collection-selectall');
    var bulkToken   = document.querySelector('meta[name="csrf-token"]');
    bulkToken = bulkToken ? bulkToken.content : '';

    function checks()        { return Array.prototype.slice.call(document.querySelectorAll('#collection-grid .photo-check')); }
    function checkedChecks() { return checks().filter(function (c) { return c.checked; }); }

    // Function declaration → hoisted, so openCollection() (above) can call it.
    function syncBulkButtons() {
        var all = checks();
        var n = checkedChecks().length;
        if (bulkApprove) bulkApprove.disabled = n === 0;
        if (bulkReject)  bulkReject.disabled  = n === 0;
        if (selAllEl)    selAllEl.checked = all.length > 0 && n === all.length;
        all.forEach(function (c) {
            var card = c.closest('.photo-card');
            if (card) card.classList.toggle('selected', c.checked);
        });
    }

    if (selAllEl) {
        selAllEl.addEventListener('change', function () {
            checks().forEach(function (c) { c.checked = selAllEl.checked; });
            syncBulkButtons();
        });
    }

    // Checkbox toggles are dynamic, so listen via delegation on the overlay.
    overlay.addEventListener('change', function (e) {
        if (e.target && e.target.classList && e.target.classList.contains('photo-check')) {
            syncBulkButtons();
        }
    });

    async function bulkReview(decision) {
        var targets = checkedChecks();
        if (!targets.length) return;

        var gerund = decision === 'approve' ? 'Approving' : 'Rejecting';
        var past   = decision === 'approve' ? 'approved'  : 'rejected';
        var verb   = decision === 'approve' ? 'Approve'   : 'Reject';
        var noun   = targets.length === 1 ? 'photo' : 'photos';

        if (!confirm(verb + ' ' + targets.length + ' ' + noun + '? The guard is notified of each outcome.')) {
            return;
        }

        var btn = decision === 'approve' ? bulkApprove : bulkReject;
        bulkApprove.disabled = true;
        bulkReject.disabled  = true;

        var ok = 0, fail = 0;
        for (var i = 0; i < targets.length; i++) {
            btn.textContent = gerund + ' ' + (i + 1) + '/' + targets.length + '…';
            try {
                var res = await fetch(targets[i].dataset.reviewUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': bulkToken
                    },
                    body: JSON.stringify({ decision: decision, note: null })
                });
                var data = await res.json();
                if (data && data.success) { ok++; } else { fail++; }
            } catch (e) {
                console.error('Bulk review error:', e);
                fail++;
            }
        }

        if (fail > 0) {
            alert(ok + ' ' + noun + ' ' + past + ', ' + fail + ' could not be saved. '
                + 'Reloading to show the current state.');
        }
        // Reload so badges, the per-attempt chip and the compliance tally all
        // reflect the new reviews (same as the single-review path).
        window.location.reload();
    }

    if (bulkApprove) bulkApprove.addEventListener('click', function () { bulkReview('approve'); });
    if (bulkReject)  bulkReject.addEventListener('click', function () { bulkReview('reject'); });
})();

// ── Review a photo (approve / reject + optional note) ────────────────
// Opens a modal for the clicked photo, POSTs the decision, and reloads so
// the badge + compliance tally reflect it. The server pushes the outcome
// to the guard's app (the app also polls the reviews endpoint).
(function () {
    var overlay = document.getElementById('review-modal-overlay');
    if (!overlay) return;

    var noteEl = document.getElementById('review-note');
    var cancelBtn = document.getElementById('review-modal-cancel');
    var decisionBtns = overlay.querySelectorAll('.tl-btn[data-decision]');
    var token = document.querySelector('meta[name="csrf-token"]');
    token = token ? token.content : '';
    var activeUrl = null;

    function open(url) {
        activeUrl = url;
        noteEl.value = '';
        overlay.classList.add('open');
        noteEl.focus();
    }
    // Exposed so the collection modal can trigger the review flow for images
    // rendered dynamically inside it (z-index 500 sits above collection at 450).
    window.openReview = open;

    function close() {
        overlay.classList.remove('open');
        activeUrl = null;
        decisionBtns.forEach(function (b) { b.disabled = false; });
    }

    document.querySelectorAll('.btn-review[data-review-url]').forEach(function (btn) {
        btn.addEventListener('click', function () { open(btn.dataset.reviewUrl); });
    });

    cancelBtn.addEventListener('click', close);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });

    decisionBtns.forEach(function (btn) {
        btn.addEventListener('click', async function () {
            if (!activeUrl) return;
            var decision = btn.dataset.decision; // 'approve' | 'reject'
            decisionBtns.forEach(function (b) { b.disabled = true; });

            try {
                var res = await fetch(activeUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': token
                    },
                    body: JSON.stringify({ decision: decision, note: noteEl.value || null })
                });
                var data = await res.json();

                if (!data.success) {
                    alert(data.error || 'Unable to record the review.');
                    decisionBtns.forEach(function (b) { b.disabled = false; });
                    return;
                }
                window.location.reload();
            } catch (e) {
                console.error('Photo review error:', e);
                alert('Something went wrong. Please try again.');
                decisionBtns.forEach(function (b) { b.disabled = false; });
            }
        });
    });
})();

// ── Timeline header jump links → smooth-scroll to a section ──────────
// The scrolling region is .content (not the window), but scrollIntoView
// walks to the nearest scrollable ancestor, so it scrolls that correctly.
(function () {
    document.querySelectorAll('.tl-jump a[data-jump]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            var target = document.getElementById(link.dataset.jump);
            if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
})();

// ── Local-timezone rendering for all <time data-ts="..."> elements ──────
// server_received_at is stored as UTC. PHP outputs it as a clean ISO-8601
// UTC string (data-ts). JS converts to the admin's browser timezone, so
// times match what the shifts calendar already shows.
(function () {
    var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    function pad(n) { return n.toString().padStart(2, '0'); }
    function hr12(d) { var h = d.getHours() % 12; return h === 0 ? 12 : h; }
    function ampm(d) { return d.getHours() >= 12 ? 'PM' : 'AM'; }

    function fmtTime(d)   { return hr12(d) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()) + ' ' + ampm(d); }
    function fmtHHMM(d)   { return hr12(d) + ':' + pad(d.getMinutes()) + ' ' + ampm(d); }
    function fmtDate(d)   { return pad(d.getDate()) + ' ' + MONTHS[d.getMonth()] + ' ' + d.getFullYear(); }
    function fmtFull(d)   { return fmtDate(d) + ' · ' + fmtHHMM(d); }

    function apply(selector, fn) {
        document.querySelectorAll(selector).forEach(function (el) {
            var d = new Date(el.dataset.ts);
            if (!el.dataset.ts || isNaN(d)) return;
            el.textContent = fn(d);
            el.title = 'UTC: ' + el.dataset.ts;
        });
    }

    apply('time.tl-ts[data-ts]',      fmtTime);   // audit log events — h:mm:ss AM/PM
    apply('time.tl-ts-hhmm[data-ts]', fmtHHMM);   // header time range — h:mm AM/PM
    apply('time.tl-ts-date[data-ts]', fmtDate);   // header date — DD MMM YYYY
    apply('time.tl-ts-full[data-ts]', fmtFull);   // side panel + fallback row — DD MMM YYYY · h:mm AM/PM
})();
</script>
@endsection
