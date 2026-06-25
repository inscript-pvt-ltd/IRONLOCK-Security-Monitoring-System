<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Flag shifts whose check-in/start window has expired as missed, so the
// "contact your supervisor" recovery path surfaces promptly on the app and
// the admin dashboard. Overlap-protected so a slow run never double-fires.
Schedule::command('shifts:mark-missed')->everyMinute()->withoutOverlapping();

// Guarantee every shift closes: force-close any Active shift left open past its
// scheduled_end + grace (a guard who forgot to end, or a dead/offline phone).
// Spec: Details/Important/BACKEND_SHIFT_END_SPEC.md §2. Overlap-protected.
Schedule::command('shifts:auto-close')->everyFiveMinutes()->withoutOverlapping();

// Time out photo requests the guard never answered within the 90s window and
// raise a CRITICAL alert (spec §16.4). Runs every minute. Overlap-protected.
Schedule::command('photos:timeout-sweep')->everyMinute()->withoutOverlapping();

// Fire randomised verification photo checks on active shifts (spec §8.2). The
// short cadence + per-run probability is what makes a check unpredictable; the
// command itself enforces a minimum gap per shift. Overlap-protected.
Schedule::command('photos:dispatch-scheduled')->everyTenMinutes()->withoutOverlapping();

// Fire online wakefulness challenges at their provisioned schedule marks
// (spec §9.4.1). Runs every minute so a due mark is dispatched promptly; the
// command skips shifts with an unanswered challenge and never double-fires a
// mark. Overlap-protected.
Schedule::command('wakefulness:dispatch')->everyMinute()->withoutOverlapping();

// Fail wakefulness challenges the guard never answered within the 60s response
// window and raise a CRITICAL alert (spec §9.5). Runs every minute.
// Overlap-protected.
Schedule::command('wakefulness:timeout-sweep')->everyMinute()->withoutOverlapping();
