<?php

namespace App\Console\Commands;

use App\Domains\Geofences\Models\Geofence;
use App\Domains\Guards\Models\Guard;
use App\Domains\Shifts\Models\Shift;
use App\Domains\Sites\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * TEMPORARY — Play Store review bypass setup.
 *
 * Creates (or refreshes) the ONE guard account + ONE shift used to let the
 * Google Play reviewer sign in and run a shift whenever they happen to open
 * the app, without touching the login window or 16-hour WTR cap that every
 * other guard is still held to. Full mechanism + safe-removal checklist:
 * Details/Important/PLAY_STORE_REVIEW_BYPASS.md
 *
 * Safe to re-run: re-running finds the same guard (by email) and the same
 * marked shift (by its override_reason tag) and extends them instead of
 * creating duplicates — use this if the review runs longer than expected.
 */
class SetupReviewBypassGuard extends Command
{
    /**
     * The tag written to shifts.override_reason so a re-run of this command
     * can find (and extend) its own shift instead of creating a duplicate.
     * Chosen deliberately distinctive so it can never collide with a real
     * admin-entered override reason.
     */
    private const SHIFT_MARKER = 'PLAY_STORE_REVIEW_BYPASS';

    protected $signature = 'ironlock:review-bypass
        {--email= : Guard email (defaults to config ironlock.review_bypass.guard_email)}
        {--site=Test Site : Non-archived site to attach the shift to (omit on a re-run to keep the current one)}
        {--hours= : Shift length in hours, counted from NOW (defaults to config ironlock.review_bypass.max_duration_hours)}
        {--days=7 : How many days from now the login-window override should stay open}
        {--password= : Guard password to set (a strong random one is generated if omitted)}';

    protected $description = 'TEMPORARY: create/refresh the single Play Store review guard + test shift (see Details/Important/PLAY_STORE_REVIEW_BYPASS.md)';

    public function handle(): int
    {
        $email = $this->option('email') ?: config('ironlock.review_bypass.guard_email');

        if (empty($email)) {
            $this->error('No email given. Pass --email=... or set IRONLOCK_REVIEW_BYPASS_GUARD_EMAIL in .env first.');

            return self::FAILURE;
        }

        if (! config('ironlock.review_bypass.enabled')) {
            $this->warn('Note: IRONLOCK_REVIEW_BYPASS_ENABLED is false — the guard/shift below will be');
            $this->warn('created, but the 16-hour WTR exception will NOT take effect until you set');
            $this->warn('IRONLOCK_REVIEW_BYPASS_ENABLED=true in .env and run `php artisan config:clear`.');
            $this->newLine();
        }

        $siteName = $this->resolveSiteName($email);

        $site = Site::where('name', $siteName)->whereNull('archived_at')->first();

        if (! $site) {
            $available = Site::whereNull('archived_at')->pluck('name')->implode(', ');
            $this->error("Site \"{$siteName}\" not found (or archived). Active sites: {$available}");

            return self::FAILURE;
        }

        $geofence = Geofence::where('site_id', $site->id)->where('is_active', true)->first();

        if (! $geofence) {
            $this->error("Site \"{$site->name}\" has no active geofence. Set one up first, then re-run.");

            return self::FAILURE;
        }

        [$guard, $plainPassword] = $this->findOrCreateGuard($email);

        $hours = (int) ($this->option('hours') ?: config('ironlock.review_bypass.max_duration_hours', 72));
        $days = (int) $this->option('days');

        $shift = $this->findOrCreateShift($guard, $site, $geofence, $hours, $days);

        $this->newLine();
        $this->info('Review bypass guard + shift ready.');
        $this->table(['Field', 'Value'], [
            ['Guard email', $guard->email],
            ['Guard username', $guard->username],
            ['Guard employee_code', $guard->employee_code],
            ['Password', $plainPassword ?? '(unchanged — already set on an existing account)'],
            ['Shift reference', $shift->reference],
            ['Site', $site->name],
            ['scheduled_start', $shift->scheduled_start->toDayDateTimeString()],
            ['scheduled_end', $shift->scheduled_end->toDayDateTimeString()],
            ['Login window open until', $shift->checkin_override_until->toDayDateTimeString()],
        ]);

        if ($plainPassword) {
            $this->newLine();
            $this->warn("Password shown once — record it now (e.g. Play Console's tester-credentials field):");
            $this->line("  {$plainPassword}");
        }

        $this->newLine();
        $this->comment('Reminder: set IRONLOCK_REVIEW_BYPASS_ENABLED=true and IRONLOCK_REVIEW_BYPASS_GUARD_EMAIL='
            . $guard->email . ' in .env (then `php artisan config:clear`) if not already done.');

        return self::SUCCESS;
    }

    /**
     * Which site the shift should sit on.
     *
     * `--site` has a default ("Test Site"), so on a re-run an omitted `--site`
     * would otherwise silently migrate an already-working review shift to a
     * different site. An omitted `--site` therefore keeps whatever site the
     * existing review shift is already on; only an explicitly passed `--site`
     * moves it.
     *
     * Deliberately looks at the last tagged shift in ANY status, unlike the
     * reuse query in findOrCreateShift(): once the first review shift has been
     * auto-closed (Completed) a re-run creates a fresh shift, and that fresh
     * shift should still land on the site the review was set up against —
     * not silently fall back to the "Test Site" default.
     */
    private function resolveSiteName(string $email): string
    {
        $given = (string) $this->option('site');

        if ($this->input->hasParameterOption('--site')) {
            return $given;
        }

        $currentName = Shift::where('override_reason', self::SHIFT_MARKER)
            ->whereHas('assignedGuard', fn ($q) => $q->whereRaw('LOWER(email) = ?', [strtolower($email)]))
            ->orderByDesc('created_at')
            ->with('site')
            ->first()
            ?->site
            ?->name;

        if ($currentName && $currentName !== $given) {
            $this->info("Keeping the existing review shift on site \"{$currentName}\" "
                . '(pass --site explicitly to move it).');
        }

        return $currentName ?: $given;
    }

    /**
     * @return array{0: Guard, 1: string|null} the guard and the plaintext
     *         password IF one was just generated/set (null when an existing
     *         account's password was left untouched).
     */
    private function findOrCreateGuard(string $email): array
    {
        $guard = Guard::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();

        $explicitPassword = $this->option('password');

        if ($guard) {
            if ($explicitPassword) {
                $guard->update(['password' => Hash::make($explicitPassword)]);
                $this->info("Existing guard found ({$guard->employee_code}) — password updated.");

                return [$guard, $explicitPassword];
            }

            $this->info("Existing guard found ({$guard->employee_code}) — reusing, password left unchanged.");

            return [$guard, null];
        }

        $plainPassword = $explicitPassword ?: Str::password(20);
        $suffix = Str::upper(Str::random(4));

        $guard = Guard::create([
            'employee_code' => "REVIEW-{$suffix}",
            'first_name' => 'App Store',
            'last_name' => 'Reviewer',
            'username' => 'review-' . Str::lower($suffix),
            'email' => $email,
            'password' => Hash::make($plainPassword),
            'sia_licence_number' => "REVIEW-SIA-{$suffix}",
            'sia_licence_expiry' => now()->addYears(5)->toDateString(),
            'sia_licence_type' => 'Door Supervisor',
            'employment_status' => 'active',
            'status' => 'active',
        ]);

        $this->info("Created new guard: {$guard->employee_code} / {$guard->username} / {$guard->email}");

        return [$guard, $plainPassword];
    }

    private function findOrCreateShift(Guard $guard, Site $site, Geofence $geofence, int $hours, int $days): Shift
    {
        $existing = Shift::where('guard_id', $guard->id)
            ->where('override_reason', self::SHIFT_MARKER)
            ->whereIn('status', [
                Shift::STATUS_SCHEDULED,
                Shift::STATUS_CHECKED_IN,
                Shift::STATUS_ACTIVE,
                // A shift the mark-missed sweep already flagged is still reusable
                // — revive it below rather than leaving it behind and stacking up
                // a second review shift for the same guard.
                Shift::STATUS_MISSED,
            ])
            ->orderByDesc('created_at')
            ->first();

        $now = now();
        $overrideUntil = $now->copy()->addDays($days);

        if ($existing) {
            // Refresh site/geofence too, not just the dates — otherwise
            // re-running with a different --site (e.g. moving from a throwaway
            // test site to the real one) would silently leave the shift on the
            // old site.
            //
            // `hours` counts from NOW, never from the original scheduled_start:
            // the whole reason to re-run is "the reviewer still needs time", and
            // anchoring to the old start would just rewrite scheduled_end back
            // into the past once the first window has elapsed.
            $attributes = [
                'site_id' => $site->id,
                'geofence_id' => $geofence->id,
                'scheduled_end' => $now->copy()->addHours($hours),
                'checkin_override_until' => $overrideUntil,
            ];

            if ($existing->status === Shift::STATUS_ACTIVE) {
                // Reviewer is mid-shift: never move a running shift's start.
                // Its photo/wakefulness marks were provisioned once at start()
                // and all sit inside the OLD window, so top them up across the
                // newly added tail — otherwise the extension runs with no
                // verification checks at all and there is nothing to review.
                $existing->scheduled_end = $attributes['scheduled_end'];

                $attributes['wakefulness_schedule'] = $this->appendMarks(
                    $existing->wakefulness_schedule,
                    $existing->buildWakefulnessSchedule($now)
                );
                $attributes['photo_schedule'] = $this->appendMarks(
                    $existing->photo_schedule,
                    $existing->buildPhotoSchedule($now)
                );

                $this->info("Existing review shift is ACTIVE ({$existing->reference}) — extended to {$hours}h "
                    . 'from now, verification schedule topped up for the new tail.');
            } else {
                // Not started yet — roll the whole window forward so the
                // reviewer gets a clean, full-length shift instead of one whose
                // start is days in the past (and clear the missed/check-in state
                // that a lapsed first attempt may have left behind).
                $attributes['scheduled_start'] = $now;
                $attributes['status'] = Shift::STATUS_SCHEDULED;
                $attributes['checked_in_at'] = null;
                $attributes['resolved_at'] = null;

                $this->info("Existing review shift found ({$existing->reference}) — window rolled forward: "
                    . "{$hours}h starting now.");
            }

            $existing->update($attributes);

            return $existing;
        }

        $scheduledStart = $now;
        $scheduledEnd = $scheduledStart->copy()->addHours($hours);

        $shift = Shift::create([
            'guard_id' => $guard->id,
            'site_id' => $site->id,
            'geofence_id' => $geofence->id,
            'scheduled_start' => $scheduledStart,
            'scheduled_end' => $scheduledEnd,
            'checkin_override_until' => $overrideUntil,
            'status' => Shift::STATUS_SCHEDULED,
            'override_reason' => self::SHIFT_MARKER,
            'started_by' => null,
        ]);

        $this->info("Created new review shift: {$shift->reference}");

        return $shift;
    }

    /**
     * Merge freshly-drawn schedule marks onto the ones already on file, kept
     * unique and in chronological order (the dispatchers and the app's offline
     * trigger both read these arrays in sequence).
     *
     * @param  array<int, string>|null  $existing
     * @param  array<int, string>  $fresh
     * @return array<int, string>
     */
    private function appendMarks(?array $existing, array $fresh): array
    {
        $marks = array_values(array_unique(array_merge($existing ?? [], $fresh)));
        sort($marks);

        return $marks;
    }
}
