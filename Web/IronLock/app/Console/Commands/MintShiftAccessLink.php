<?php

namespace App\Console\Commands;

use App\Domains\Authentication\Services\ShiftAccessLinkService;
use App\Domains\Guards\Models\Guard;
use App\Domains\Shifts\Models\Shift;
use App\Domains\Sites\Models\Site;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Mint a one-time Shift Access Link (SSO) for testing.
 *
 * Generates a real, single-use, 1-hour token for a guard's shift and prints the
 * https link, the app-scheme link, and the raw token — handy for QA and for the
 * mobile team to fire at the simulator. By default it reuses the guard's nearest
 * redeemable shift; --new creates a near-now scheduled shift so the login window
 * is open immediately and the token redeems cleanly.
 *
 *   php artisan ironlock:shift-access-link GRD6583
 *   php artisan ironlock:shift-access-link GRD6583 --new
 */
class MintShiftAccessLink extends Command
{
    protected $signature = 'ironlock:shift-access-link
                            {employee_code : The guard\'s employee code (or email)}
                            {--new : Create a fresh near-now scheduled shift instead of reusing one}';

    protected $description = 'Mint a one-time Shift Access Link (SSO) token for a guard, for testing';

    public function handle(ShiftAccessLinkService $links): int
    {
        $identifier = (string) $this->argument('employee_code');

        $guard = Guard::where('employee_code', $identifier)
            ->orWhere('email', $identifier)
            ->first();

        if (!$guard) {
            $this->error("No guard found for '{$identifier}'.");
            return self::FAILURE;
        }

        if ($guard->employment_status !== 'active') {
            $this->warn("Heads up: guard {$guard->employee_code} is not 'active' — redemption will fail SHIFT_ACCESS_UNAUTHORIZED.");
        }

        $shift = $this->resolveShift($guard);

        if (!$shift) {
            $this->error('No redeemable shift found for this guard. Re-run with --new to create a near-now test shift.');
            return self::FAILURE;
        }

        $result = $links->generate($shift, null);

        $this->newLine();
        $this->info('Shift Access Link minted ✓');
        $this->line('  Guard       : ' . trim($guard->first_name . ' ' . $guard->last_name) . " ({$guard->employee_code})");
        $this->line('  Shift       : ' . ($shift->reference ?? $shift->id) . " [{$shift->status}]");
        $this->line('  Window opens: ' . optional($shift->scheduled_start)->toIso8601String());
        $this->line('  Expires at  : ' . $result['expires_at']->toIso8601String() . ' (1 hour)');
        $this->newLine();
        $this->line('  Raw token   : ' . $result['token']);
        $this->line('  https link  : ' . $result['url']);
        $this->line('  app scheme  : ' . rtrim((string) config('ironlock.shift_access_app_scheme', 'ironlock://shift-access/'), '/') . '/' . $result['token']);
        $this->newLine();
        $this->comment('  Single-use — redeeming it (or minting another) invalidates it.');

        return self::SUCCESS;
    }

    /**
     * Reuse the guard's nearest redeemable shift, or create a near-now one with
     * --new (or when none exists is not auto-created unless requested).
     */
    private function resolveShift(Guard $guard): ?Shift
    {
        if (!$this->option('new')) {
            $existing = Shift::where('guard_id', $guard->id)
                ->whereIn('status', [
                    Shift::STATUS_SCHEDULED, Shift::STATUS_CHECKED_IN,
                    Shift::STATUS_ACTIVE, Shift::STATUS_MISSED,
                ])
                ->orderByRaw('ABS(TIMESTAMPDIFF(SECOND, scheduled_start, NOW()))')
                ->first();

            if ($existing) {
                return $existing;
            }

            $this->warn('No existing redeemable shift — creating a near-now test shift (same as --new).');
        }

        $site = Site::first();

        return Shift::create([
            'guard_id' => $guard->id,
            'site_id' => $site?->id,
            'scheduled_start' => Carbon::now(),
            'scheduled_end' => Carbon::now()->addHours(8),
            'status' => Shift::STATUS_SCHEDULED,
        ]);
    }
}
