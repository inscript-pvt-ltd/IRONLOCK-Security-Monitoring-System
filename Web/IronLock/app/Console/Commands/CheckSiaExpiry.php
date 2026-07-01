<?php

namespace App\Console\Commands;

use App\Domains\Alerts\Services\AlertService;
use App\Domains\Guards\Models\Guard;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Raise SIA licence-expiry alerts (REP-004 · Phase 8).
 *
 * A guard whose SIA licence is expired, or expiring within the warning window,
 * cannot be relied on to legally work. This scans active, non-erased guards and
 * raises one alert per affected guard: WARNING while the licence is inside the
 * window, CRITICAL once it has expired. AlertService makes the raise idempotent
 * per guard per day, so running daily (or by hand) never spams the feed.
 *
 * The alert surfaces in the existing Alert Feed (D-03) with no new UI — it is a
 * guard-level alert with no shift context (alerts.shift_id is nullable as of
 * Phase 8).
 */
class CheckSiaExpiry extends Command
{
    protected $signature = 'sia:check-expiry {--days=30 : Warning window in days before expiry}';

    protected $description = 'Raise SIA licence-expiry alerts for active guards (expired or expiring soon)';

    public function handle(AlertService $alerts): int
    {
        $days = (int) $this->option('days');
        $threshold = Carbon::now()->addDays($days);

        // Active, non-erased guards whose licence is at or past the warning point
        // (this also captures already-expired licences).
        $guards = Guard::where('employment_status', 'active')
            ->notErased()
            ->whereNotNull('sia_licence_expiry')
            ->whereDate('sia_licence_expiry', '<=', $threshold)
            ->get();

        $raised = 0;
        foreach ($guards as $guard) {
            if ($alerts->createSiaExpiryAlert($guard) !== null) {
                $raised++;
            }
        }

        $this->info("SIA expiry check complete — {$guards->count()} guard(s) within the window, {$raised} alert(s) raised.");

        return self::SUCCESS;
    }
}
