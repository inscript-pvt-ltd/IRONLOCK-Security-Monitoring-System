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
        {--site=Test Site : Name of an existing, non-archived site to attach the shift to}
        {--hours= : Shift duration in hours (defaults to config ironlock.review_bypass.max_duration_hours)}
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

        $site = Site::where('name', $this->option('site'))->whereNull('archived_at')->first();

        if (! $site) {
            $available = Site::whereNull('archived_at')->pluck('name')->implode(', ');
            $this->error("Site \"{$this->option('site')}\" not found (or archived). Active sites: {$available}");

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
            ->whereIn('status', [Shift::STATUS_SCHEDULED, Shift::STATUS_CHECKED_IN, Shift::STATUS_ACTIVE])
            ->orderByDesc('created_at')
            ->first();

        $overrideUntil = now()->addDays($days);

        if ($existing) {
            // Refresh site/geofence/duration too, not just the override date —
            // otherwise re-running with a different --site (e.g. moving from a
            // throwaway test site to the real one) would silently leave the
            // shift on the old site.
            $existing->update([
                'site_id' => $site->id,
                'geofence_id' => $geofence->id,
                'scheduled_end' => $existing->scheduled_start->copy()->addHours($hours),
                'checkin_override_until' => $overrideUntil,
            ]);
            $this->info("Existing review shift found ({$existing->reference}) — refreshed site/duration/login window.");

            return $existing;
        }

        $scheduledStart = now();
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
}
