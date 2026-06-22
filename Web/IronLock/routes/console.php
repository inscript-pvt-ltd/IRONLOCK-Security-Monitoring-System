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
