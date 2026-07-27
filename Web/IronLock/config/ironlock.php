<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shift attendance
    |--------------------------------------------------------------------------
    |
    | Window rules for guard check-in (login) and shift start, in minutes
    | either side of the shift's scheduled_start. A single value drives both
    | the "too early" floor (start − window) and the "too late" ceiling
    | (start + window), so changing it here moves both edges together.
    |
    | - check_in_window_minutes: a guard may sign in / check in from
    |   scheduled_start − N until scheduled_start + N, and may press Start up
    |   to scheduled_start + N. Past that the shift is Missed.
    |
    | - late_authorization_minutes: when a supervisor authorizes a late
    |   check-in on a Missed shift, the per-shift override stays open for this
    |   many minutes from the moment it is granted.
    |
    */

    'check_in_window_minutes' => (int) env('IRONLOCK_CHECKIN_WINDOW', 15),

    'late_authorization_minutes' => (int) env('IRONLOCK_LATE_AUTH_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Shift auto-close
    |--------------------------------------------------------------------------
    |
    | Grace period after scheduled_end before the server force-closes a shift
    | the guard never ended (BACKEND_SHIFT_END_SPEC §2). Long enough to let a
    | slightly-late manual end happen first; short enough that open records do
    | not linger. The auto-closed shift's actual_end is set to scheduled_end.
    |
    */

    'auto_close_grace_minutes' => (int) env('IRONLOCK_AUTO_CLOSE_GRACE', 45),

    /*
    |--------------------------------------------------------------------------
    | Photo verification (Phase 4 — online response window)
    |--------------------------------------------------------------------------
    |
    | The single authoritative window, in seconds, for a guard to capture and
    | upload a live photo after an ONLINE request is raised. This one value
    | drives THREE things that must never disagree, or the guard's on-screen
    | countdown would mislead them into a rejected upload:
    |
    |   - the ONLINE nonce TTL (NonceService::onlineTtlSeconds) — a photo whose
    |     server-receipt is past this window is rejected NONCE_EXPIRED;
    |   - the photo-request timeout sweep (photos:timeout-sweep) — a PENDING
    |     request past this window becomes TIMEOUT + a CRITICAL alert;
    |   - the `response_seconds` surfaced to the app (push data + the pending
    |     poll), so the app anchors an exact server-side countdown.
    |
    | Owner-set to 90s. This supersedes the master spec's original 60s ONLINE
    | nonce expiry (§16.4 #12); raising it to match the 90s response window
    | removes the 60–90s dead zone where an honest upload was rejected yet still
    | alerted. The OFFLINE pool nonce is a separate mechanism: it spans the whole
    | shift (offline_nonce_grace_minutes below), NOT 90s or 15 min.
    |
    | photo_min_gap_minutes / photo_max_gap_minutes (Phase 7 Option A): the
    | random spacing between consecutive verification-photo marks on a shift.
    | The schedule is provisioned at start (Shift::buildPhotoSchedule) and is the
    | single source of truth for BOTH the online dispatcher (photos:dispatch-
    | scheduled) and the app's offline camera trigger, so a guard is checked on
    | the same cadence whether or not the device is connected.
    |
    | Defaults to a ~1-hour gap (client requirement), randomised 50–70 min around
    | the hour. The ±10 min jitter is deliberate: a fixed 60-min rhythm would let
    | a guard anticipate the next check, defeating the anti-gaming intent of
    | "randomised live photo verification" (master spec §7.1).
    |
    */

    'photo_response_seconds' => (int) env('IRONLOCK_PHOTO_RESPONSE_SECONDS', 90),

    'photo_min_gap_minutes' => (int) env('IRONLOCK_PHOTO_MIN_GAP', 50),

    'photo_max_gap_minutes' => (int) env('IRONLOCK_PHOTO_MAX_GAP', 70),

    /*
    | offline_nonce_grace_minutes (Phase 7 Option A fix, 2026-07-23): how far
    | past scheduled_end an OFFLINE_POOL nonce stays valid. Offline photo marks
    | fall 50–70 min apart across the whole shift, so a pool nonce prefetched at
    | start must remain valid for the ENTIRE shift — a fixed 15-min TTL expired
    | (~+15 min) long before the first mark (~+50 min) and could not be refilled
    | while the device was offline, so every offline capture failed NONCE_EXPIRED.
    | NonceService::offlineExpiryFor() now spans a pool nonce to scheduled_end +
    | this margin (covers overrun / the auto-close grace). Replay safety is
    | unaffected — single-use is enforced atomically at consume time, not by TTL.
    */
    'offline_nonce_grace_minutes' => (int) env('IRONLOCK_OFFLINE_NONCE_GRACE_MINUTES', 120),

    /*
    |--------------------------------------------------------------------------
    | Wakefulness verification (Phase 5 — Code-Challenge Protocol)
    |--------------------------------------------------------------------------
    |
    | At randomised intervals the server challenges the guard with a 4-digit
    | code they must transcribe within the response window, or a CRITICAL
    | GUARD_UNRESPONSIVE alert fires (spec §9). The schedule of challenge times
    | is provisioned at shift start so the app can fire matching offline local
    | notifications; min/max gap bound the spacing of those randomised marks.
    |
    | - wakefulness_min_gap_minutes / wakefulness_max_gap_minutes: the random
    |   spacing between consecutive challenges on a shift.
    |
    | - wakefulness_response_seconds: the on-screen countdown AND the server
    |   escalation window. Owner-set to 60s (the master spec's original 10s is
    |   superseded — see Details/Important/PHASE_5_WAKEFULNESS_PLAN.md §0.3).
    |
    | - wakefulness_deadline_grace_seconds: small network/FCM-latency cushion
    |   added to the server deadline only (not shown to the guard).
    |
    | - totp_period_seconds / totp_digits: the offline TOTP parameters. The
    |   window reference recorded on each check is floor(unix_time / period).
    |   BOTH ARE CLAMPED, not trusted: `(int) env(...)` yields 0 for a blank or
    |   non-numeric .env entry (the env() default only applies when the key is
    |   absent entirely), and totp_digits = 0 makes every str_pad() in the
    |   wakefulness path a no-op — the server would emit 1-4 character challenge
    |   codes instead of 4, and the app would derive `binary % 10**0` = "0" for
    |   every offline window. These values are also handed to the device in the
    |   shift-start payload, so a bad one desyncs both sides at once. Out-of-range
    |   input falls back to the documented default rather than propagating.
    |
    | Comms-interruption (a guard with no recent GPS ping) makes the dispatcher
    | skip online challenges and the sweep suppress the CRITICAL alert — a
    | connectivity gap is not a sleeping guard (master spec Scenario 4). That
    | threshold is owned by the GPS phase (GuardLocation::isCommsInterrupted /
    | COMMS_TIMEOUT_SECONDS), reused here so the two never diverge.
    |
    */

    'wakefulness_min_gap_minutes' => (int) env('IRONLOCK_WAKE_MIN_GAP', 30),

    'wakefulness_max_gap_minutes' => (int) env('IRONLOCK_WAKE_MAX_GAP', 45),

    'wakefulness_response_seconds' => (int) env('IRONLOCK_WAKE_RESPONSE_SECONDS', 60),

    'wakefulness_deadline_grace_seconds' => (int) env('IRONLOCK_WAKE_GRACE_SECONDS', 5),

    'totp_period_seconds' => (static function (): int {
        $period = (int) env('IRONLOCK_TOTP_PERIOD', 30);

        return ($period >= 10 && $period <= 300) ? $period : 30;
    })(),

    // 4 is the shipped contract (the app's UI, the push body and the
    // wakefulness_checks.challenge_code column all assume it). 8 is the widest
    // the column holds; anything outside 4-8 is a misconfiguration, not an
    // intent, so it resolves to 4.
    'totp_digits' => (static function (): int {
        $digits = (int) env('IRONLOCK_TOTP_DIGITS', 4);

        return ($digits >= 4 && $digits <= 8) ? $digits : 4;
    })(),

    /*
    |--------------------------------------------------------------------------
    | Alert Feed (Phase 6 · D-03)
    |--------------------------------------------------------------------------
    | Rows per page in the supervisor Alert Feed worklist.
    */

    'alerts_feed_per_page' => (int) env('IRONLOCK_ALERTS_FEED_PER_PAGE', 25),

    /*
    |--------------------------------------------------------------------------
    | Shift Access Link (one-time SSO sign-in)
    |--------------------------------------------------------------------------
    |
    | A supervisor can mint a single-use link that signs a guard in to a
    | specific shift without typing credentials. The link still passes every
    | normal login gate (employment status, account lock, and the shift
    | check-in window) — it only removes the password step.
    |
    | - shift_access_link_ttl_minutes: the link expires this many minutes after
    |   it is generated, regardless of use (default 60).
    |
    | - shift_access_link_base: the URL prefix the raw token is appended to when
    |   building the link the admin copies. The mobile app intercepts this deep
    |   link and posts the token to POST /mobile/v1/auth/shift-access. When unset
    |   it defaults to <app.url>/m/shift-access.
    |
    */

    'shift_access_link_ttl_minutes' => (int) env('IRONLOCK_SHIFT_ACCESS_TTL_MINUTES', 60),

    'shift_access_link_base' => env('IRONLOCK_SHIFT_ACCESS_LINK_BASE'),

    /*
    | The mobile app's deep-link scheme prefix. The web landing page at
    | GET /m/shift-access/{token} bounces the browser into this so the app
    | opens (a custom scheme isn't auto-tappable from an https link in
    | WhatsApp/SMS). The raw token is appended to this prefix. Once Universal/
    | App Links are configured the https link opens the app directly and this
    | page is just a fallback.
    */

    'shift_access_app_scheme' => env('IRONLOCK_SHIFT_ACCESS_APP_SCHEME', 'ironlock://shift-access/'),

    /*
    |--------------------------------------------------------------------------
    | Monthly evidence backups
    |--------------------------------------------------------------------------
    |
    | Each completed calendar month of photo evidence is downloadable as a ZIP
    | archive for this many days after the month ends, then the scheduled
    | cleanup removes the backup record + any cached ZIP. The cleanup NEVER
    | touches the original photo_evidences (they are immutable) — it only purges
    | the generated archives. Default 30 days.
    |
    */

    'backup_retention_days' => (int) env('IRONLOCK_BACKUP_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Offline sync / backfill (Phase 7)
    |--------------------------------------------------------------------------
    |
    | When a guard's app reconnects after a comms gap it flushes its buffered GPS
    | pings in one batch (MOBILE_API_INTEGRATION.md §6.1). A flush whose last
    | server-receipt is older than this many seconds is treated as a RECONNECT:
    | the server records a COMMS_GAP_START / COMMS_GAP_END pair and a SYNC_FLUSH
    | summary on the shift timeline, and only the guard's *current* position (the
    | net effect of the flush) can raise a live zone-exit alert — historical pings
    | are recorded for audit but never paged retroactively (§7.3, no retroactive
    | alerts). The boundary is server-determined; the client clock is never
    | trusted to decide it.
    |
    | 60s ≈ four missed 15s pings — long enough to ignore a single dropped ping,
    | short enough to flag a genuine connectivity gap. Independent of the 30s
    | COMMS_TIMEOUT that greys a guard out live (GuardLocation::COMMS_TIMEOUT_SECONDS).
    |
    */

    'gps_backfill_threshold_seconds' => (int) env('IRONLOCK_GPS_BACKFILL_THRESHOLD', 60),

    /*
    |--------------------------------------------------------------------------
    | Catch-up staleness (Phase 7 — reconnect / scheduler-gap guard)
    |--------------------------------------------------------------------------
    |
    | The minute-crons (wakefulness:dispatch, photos:dispatch-scheduled,
    | wakefulness:timeout-sweep) are meant to run every minute. When they DON'T —
    | a guard was offline for a stretch, or the scheduler itself stalled — a batch
    | of schedule marks / deadlines comes due while nothing is running, then all
    | catches up in one tick on resume. Two symptoms follow, both fixed by this
    | one threshold:
    |
    |   - Dispatch would retroactively fire an ONLINE challenge/photo for a mark
    |     that fell due minutes ago (which the app's OFFLINE path already covered),
    |     landing a surprise window seconds after reconnect. So a mark due more
    |     than this many seconds ago is treated as "the offline path owns it" and
    |     is NOT fired online; the next future mark fires normally.
    |
    |   - The timeout-sweep would fail a long-overdue check and raise a fresh
    |     CRITICAL "unresponsive" alert for a deadline that lapsed minutes ago —
    |     paging a supervisor after the fact isn't actionable. A miss detected more
    |     than this many seconds late is recorded for audit (STALE_CATCHUP) but not
    |     paged.
    |
    | 180s ≈ up to three missed one-minute ticks — long enough to ride out a
    | normal cron hiccup and still fire/alert on a genuinely-current mark, short
    | enough that any real outage (always minutes+) is treated as catch-up. It
    | bounds worst-case retroactive firing to ~3 min instead of the whole outage.
    |
    */

    'catchup_staleness_seconds' => (int) env('IRONLOCK_CATCHUP_STALENESS_SECONDS', 180),

    /*
    |--------------------------------------------------------------------------
    | Zone-transition confirmation (GPS-jitter debounce)
    |--------------------------------------------------------------------------
    |
    | A guard standing on the geofence boundary pings OUT/IN/OUT as GPS accuracy
    | drifts. Without debouncing, every flip logged a phantom ZONE_TRANSITION and
    | reset the zone-exit grace clock — so a genuinely-outside guard could keep
    | postponing their own alert. A raw reading must now PERSIST for this many
    | seconds before the confirmed zone_status flips (and a ZONE_TRANSITION is
    | logged / the grace job dispatched). A flicker that reverts inside the window
    | cancels the candidate — no transition, no anchor reset.
    |
    | The window is ABSORBED into the grace period, not added on top: the grace
    | deadline is measured from the guard's real step-out moment (zone_left_at),
    | so a 5-minute grace stays 5 minutes total (≈1 min to confirm + 4 remaining).
    | Keep this SHORTER than the smallest site grace_period_minutes; if it is not,
    | the deadline lands immediately on confirmation (still safe, just no grace).
    | It applies to the LIVE ping path only — offline-backfill replay logs raw
    | edges and lets finalizeFlush() decide the (net-state) alert.
    |
    | 60s ≈ four 15s pings — long enough to reject a single stray reading, short
    | enough to stay well within a typical grace period.
    |
    */

    'zone_exit_confirm_seconds' => (int) env('IRONLOCK_ZONE_CONFIRM_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Data retention (Phase 8 · SEC-004)
    |--------------------------------------------------------------------------
    |
    | Horizons, in days, after which retention:sweep prunes regenerable /
    | past-policy data. Each value is a policy hook: 0 means "keep indefinitely"
    | (the sweep skips that category), so nothing is ever purged unless a horizon
    | is explicitly configured. The sweep NEVER touches the append-only audit
    | trail (shift_events) — that history is legally required and immutable.
    |
    | - report_days: generated PDF/CSV reports (Reports → Previous Exports).
    |   Safe to prune because any report can be regenerated from source data.
    |   Default 365.
    |
    | - alert_days: acknowledged (closed) alerts older than the horizon. OPEN
    |   alerts are never pruned. Default 0 (disabled).
    |
    | - evidence_days: raw photo evidence. Default 0 (disabled) — evidence is
    |   BS 8484 material and its archival is already governed by the monthly
    |   backup lifecycle (backup_retention_days); only set this if a hard
    |   evidence-deletion policy is required.
    |
    */

    'retention' => [
        'report_days' => (int) env('IRONLOCK_RETENTION_REPORT_DAYS', 365),
        'alert_days' => (int) env('IRONLOCK_RETENTION_ALERT_DAYS', 0),
        'evidence_days' => (int) env('IRONLOCK_RETENTION_EVIDENCE_DAYS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | TEMPORARY — Play Store review bypass (REMOVE AFTER APP APPROVAL)
    |--------------------------------------------------------------------------
    |
    | Google's Play Store reviewer may open the app at any unpredictable
    | moment during review, so ONE designated guard account (matched by
    | email, never by a global setting) is allowed a longer shift than the
    | normal 16-hour WTR maximum and a login window wider than the normal
    | ±15 minutes. Every other guard's shifts and login window are governed
    | by the unmodified rules — this block only ever affects the single
    | account named by `guard_email` below.
    |
    | Full mechanism, setup command, and the safe-removal checklist:
    | Details/Important/PLAY_STORE_REVIEW_BYPASS.md
    |
    | - enabled: master switch. False (the default) makes the whole feature
    |   inert even if guard_email is set — flip to true only while the
    |   review is actually in progress, back to false the moment it's not.
    |
    | - guard_email: the one guard account the duration exception applies
    |   to (case-insensitive match). The wider login window itself is NOT
    |   driven by this config — it's a per-shift `checkin_override_until`
    |   set by the setup command, so it never touches the shared
    |   check_in_window_minutes value every other guard's login relies on.
    |
    | - max_duration_hours: the WTR ceiling for guard_email only, in place
    |   of the normal 16-hour hard block. Everyone else still gets exactly
    |   16 hours, unoverridable.
    |
    */

    'review_bypass' => [
        'enabled' => (bool) env('IRONLOCK_REVIEW_BYPASS_ENABLED', false),
        'guard_email' => env('IRONLOCK_REVIEW_BYPASS_GUARD_EMAIL'),
        'max_duration_hours' => (int) env('IRONLOCK_REVIEW_BYPASS_MAX_HOURS', 72),
    ],

];
