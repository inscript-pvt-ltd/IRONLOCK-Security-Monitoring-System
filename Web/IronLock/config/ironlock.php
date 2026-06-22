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

];
