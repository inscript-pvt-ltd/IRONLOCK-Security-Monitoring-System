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
    | alerted. The OFFLINE pool TTL (15 min) is unaffected.
    |
    */

    'photo_response_seconds' => (int) env('IRONLOCK_PHOTO_RESPONSE_SECONDS', 90),

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

    'totp_period_seconds' => (int) env('IRONLOCK_TOTP_PERIOD', 30),

    'totp_digits' => (int) env('IRONLOCK_TOTP_DIGITS', 4),

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

];
