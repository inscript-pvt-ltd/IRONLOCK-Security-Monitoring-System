<?php

namespace App\Domains\Alerts\Services;

use App\Domains\Alerts\Models\Alert;
use App\Domains\Guards\Models\Guard;
use App\Domains\GPS\Models\GuardLocation;
use App\Domains\Sites\Models\Site;
use App\Jobs\CheckZoneExitJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AlertService — create, acknowledge and list supervisor alerts.
 *
 * Alerts are raised by the system (e.g. CheckZoneExitJob) and surfaced on the
 * admin dashboard. Acknowledgement is the terminal state (there is no separate
 * resolve step) and is recorded against the admin who actioned it. All field
 * names follow the `alerts` migration via the Alert model's fillable.
 */
class AlertService
{
    /**
     * Create an alert. Callers pass the type-specific fields (type, severity,
     * title, description); guard/shift/status/raised_at are defaulted here.
     * $shiftId is nullable for guard-level alerts with no shift context (e.g.
     * SIA licence expiry).
     */
    public function createAlert(string $guardId, ?string $shiftId, array $data): Alert
    {
        return Alert::create(array_merge([
            'guard_id' => $guardId,
            'shift_id' => $shiftId,
            'status' => 'OPEN',
            'raised_at' => now(),
        ], $data));
    }

    /**
     * Raise an SIA licence-expiry alert for a guard (REP-004 · Phase 8). A
     * guard-level alert (no shift): WARNING while the licence is within the
     * warning window, CRITICAL once it has expired (the guard cannot legally
     * work). Idempotent per guard per day — if an SIA alert was already raised
     * for this guard today, returns null rather than re-raising, so a daily
     * schedule cannot spam the feed.
     */
    public function createSiaExpiryAlert(Guard $guard): ?Alert
    {
        $expiry = $guard->sia_licence_expiry;
        if ($expiry === null) {
            return null;
        }

        // Per-day idempotency.
        $alreadyToday = Alert::where('guard_id', $guard->id)
            ->where('type', 'SIA_EXPIRY_WARNING')
            ->where('raised_at', '>=', Carbon::now()->startOfDay())
            ->exists();
        if ($alreadyToday) {
            return null;
        }

        $name = trim($guard->first_name . ' ' . $guard->last_name) ?: 'Guard';
        $expiryFmt = $expiry->format('d M Y');
        $expired = $expiry->lessThan(Carbon::now());

        if ($expired) {
            $severity = 'CRITICAL';
            $title = "SIA Licence Expired — {$name}";
            $description = "{$name}'s SIA licence expired on {$expiryFmt}. The guard cannot legally work until it is renewed — remove from scheduling and arrange renewal.";
        } else {
            $days = (int) ceil(Carbon::now()->floatDiffInDays($expiry));
            $severity = 'WARNING';
            $title = "SIA Licence Expiring — {$name}";
            $description = "{$name}'s SIA licence expires on {$expiryFmt} ({$days} day" . ($days === 1 ? '' : 's') . " remaining). Arrange renewal before it lapses.";
        }

        return $this->createAlert($guard->id, null, [
            'type' => 'SIA_EXPIRY_WARNING',
            'severity' => $severity,
            'title' => $title,
            'description' => $description,
        ]);
    }

    /**
     * Raise a CRITICAL ZONE_EXIT alert. Two flavours, per $reason:
     *  - SHIFT_START: the guard was still outside the geofence at their scheduled
     *    shift start time (they had the whole check-in window to reach their post).
     *  - MID_SHIFT: the guard stepped out during the shift and stayed out past the
     *    site grace period.
     * The last-known coordinates and the exit time are baked into the description
     * (there are no lat/lng columns on alerts).
     */
    public function createZoneExitAlert(
        string $guardId,
        string $shiftId,
        GuardLocation $location,
        ?Guard $guard,
        ?Site $site,
        string $exitedAt,
        string $reason = CheckZoneExitJob::REASON_MID_SHIFT
    ): Alert {
        $guardName = $guard ? trim($guard->first_name . ' ' . $guard->last_name) : 'Unknown Guard';
        $siteName = $site?->name ?? 'Unknown Site';
        $lastKnown = "Last known location: {$location->latitude}, {$location->longitude}.";

        if ($reason === CheckZoneExitJob::REASON_SHIFT_START) {
            $title = "Not At Post — {$guardName}";
            $description = "{$guardName} was still outside {$siteName} at their scheduled shift start time. "
                . $lastKnown;
        } else {
            $graceMins = $site?->grace_period_minutes ?? Site::DEFAULT_GRACE_PERIOD_MINUTES;
            $exitedFmt = Carbon::parse($exitedAt)->format('H:i:s');
            $title = "Zone Exit — {$guardName}";
            $description = "{$guardName} has been outside {$siteName} for more than {$graceMins} minutes. "
                . "Exited at {$exitedFmt}. "
                . $lastKnown;
        }

        return $this->createAlert($guardId, $shiftId, [
            'type' => 'ZONE_EXIT',
            'severity' => 'CRITICAL',
            'title' => $title,
            'description' => $description,
        ]);
    }

    /**
     * Raise a CRITICAL GUARD_UNRESPONSIVE alert: the guard failed a wakefulness
     * code-challenge — wrong code, or no response before the deadline (spec
     * §9.5). The guard's last-known coordinates and the failure time are baked
     * into the description (there are no lat/lng columns on alerts). The guard's
     * last position is looked up best-effort so the supervisor can dispatch a
     * welfare check.
     */
    public function createGuardUnresponsiveAlert(
        string $guardId,
        string $shiftId,
        ?Guard $guard,
        string $reason,
        string $occurredAt,
        bool $isOffline = false
    ): Alert {
        $guardName = $guard ? trim($guard->first_name . ' ' . $guard->last_name) : 'Unknown Guard';
        $occurredFmt = Carbon::parse($occurredAt)->format('H:i:s');

        $location = GuardLocation::where('guard_id', $guardId)
            ->orderByDesc('recorded_at')
            ->first();
        $where = $location
            ? "Last known location: {$location->latitude}, {$location->longitude}."
            : 'No recent location on file.';

        // An offline flush is retroactive: the failure happened during a
        // connectivity gap and only reached us on reconnect, by which point the
        // guard is back online. Say so, so a supervisor reads it as "review what
        // happened" rather than a live "respond now" incident.
        $description = $isOffline
            ? "{$guardName} failed an OFFLINE wakefulness check ({$reason}) at {$occurredFmt} while disconnected; "
                . "flushed on reconnect. Review the shift and confirm welfare. {$where}"
            : "{$guardName} failed a wakefulness check ({$reason}) at {$occurredFmt}. "
                . "Welfare check required. {$where}";

        return $this->createAlert($guardId, $shiftId, [
            'type' => 'GUARD_UNRESPONSIVE',
            'severity' => 'CRITICAL',
            'is_offline' => $isOffline,
            'title' => ($isOffline ? 'Unresponsive (Offline) — ' : 'Unresponsive — ') . $guardName,
            'description' => $description,
        ]);
    }

    /**
     * Acknowledge an open alert. Returns false if it doesn't exist or has
     * already been acknowledged (idempotent — no double-ack).
     */
    public function acknowledgeAlert(string $alertId, string $acknowledgedBy, string $note = ''): bool
    {
        $alert = Alert::find($alertId);

        if (!$alert || $alert->status !== 'OPEN') {
            return false;
        }

        $alert->update([
            'status' => 'ACKNOWLEDGED',
            'acknowledged_by' => $acknowledgedBy,
            'acknowledged_at' => now(),
            'acknowledgment_note' => $note,
        ]);

        return true;
    }

    /**
     * Acknowledge a batch of alerts with one shared outcome note. Reuses the
     * single-alert path (so the OPEN-only guard and audit fields are identical);
     * already-resolved or unknown ids are skipped. The whole batch runs in one
     * transaction — it's all-or-nothing, so a failure mid-way leaves none of the
     * selected alerts acknowledged. Returns the number actually acknowledged.
     */
    public function acknowledgeAlerts(array $alertIds, string $acknowledgedBy, string $note = ''): int
    {
        return DB::transaction(function () use ($alertIds, $acknowledgedBy, $note) {
            $acknowledged = 0;

            foreach (array_unique($alertIds) as $alertId) {
                if ($this->acknowledgeAlert((string) $alertId, $acknowledgedBy, $note)) {
                    $acknowledged++;
                }
            }

            return $acknowledged;
        });
    }

    /**
     * The Alert Feed worklist (ALT-006): alerts matching the supervisor's
     * filters, CRITICAL-first then newest-first, paginated. Distinct from
     * getActiveAlerts() (which is the OPEN-only, unpaginated dashboard preview).
     *
     * @param  array  $filters  severity|type|site_id|guard_id|status|offline (any omitted/blank = no constraint)
     * @return array{rows: array, pagination: array}
     */
    public function getAlertsFiltered(array $filters, int $perPage): array
    {
        $query = Alert::with(['assignedGuard', 'shift.site'])
            ->orderByRaw("FIELD(severity, 'CRITICAL', 'WARNING')")
            ->orderBy('raised_at', 'desc');

        if (!empty($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        // Offline tab: 'offline' → only retroactive offline-flush alerts,
        // 'online' → only live alerts, blank/absent → no constraint.
        if (!empty($filters['offline'])) {
            $query->where('is_offline', $filters['offline'] === 'offline');
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['guard_id'])) {
            $query->where('guard_id', $filters['guard_id']);
        }
        if (!empty($filters['site_id'])) {
            $siteId = $filters['site_id'];
            $query->whereHas('shift', fn ($q) => $q->where('site_id', $siteId));
        }

        $page = $query->paginate($perPage);

        return [
            'rows' => collect($page->items())->map(fn (Alert $a) => [
                'id' => $a->id,
                'type' => $a->type,
                'severity' => $a->severity,
                'is_offline' => (bool) $a->is_offline,
                'status' => $a->status,
                'guard_name' => $a->assignedGuard
                    ? trim($a->assignedGuard->first_name . ' ' . $a->assignedGuard->last_name)
                    : 'Unknown',
                'site_name' => $a->shift?->site?->name ?? '—',
                'age' => $a->raised_at?->diffForHumans() ?? '—',
                'can_ack' => $a->status === 'OPEN',
            ])->toArray(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
        ];
    }

    /**
     * Full detail for the Alert Feed slide-in panel (ALT-007). Null if the alert
     * doesn't exist.
     */
    public function getAlertDetail(string $alertId): ?array
    {
        /** @var Alert|null $alert */
        $alert = Alert::with(['assignedGuard', 'shift.site'])->find($alertId);

        if (!$alert) {
            return null;
        }

        return [
            'id' => $alert->id,
            'type' => $alert->type,
            'severity' => $alert->severity,
            'is_offline' => (bool) $alert->is_offline,
            'status' => $alert->status,
            'guard_name' => $alert->assignedGuard
                ? trim($alert->assignedGuard->first_name . ' ' . $alert->assignedGuard->last_name)
                : 'Unknown',
            'site_name' => $alert->shift?->site?->name ?? '—',
            'description' => $alert->description,
            'raised_at' => $alert->raised_at?->format('d M Y · H:i:s'),
            'raised_ago' => $alert->raised_at?->diffForHumans(),
            'acknowledged_at' => $alert->acknowledged_at?->format('d M Y · H:i:s'),
            'acknowledgment_note' => $alert->acknowledgment_note,
            'can_ack' => $alert->status === 'OPEN',
            'shift_id' => $alert->shift_id,
            'shift_url' => $alert->shift_id ? route('admin.shifts.timeline', $alert->shift_id) : null,
        ];
    }

    /**
     * Count of open (unacknowledged) alerts — powers the sidebar nav badge.
     */
    public function openAlertCount(): int
    {
        return Alert::where('status', 'OPEN')->count();
    }

    /**
     * IDs of the currently-open CRITICAL alerts — lets the dashboard's global
     * watcher detect a *genuinely new* critical between polls (so it can sound
     * an audible cue) rather than only seeing the count move.
     *
     * @return string[]
     */
    public function openCriticalAlertIds(): array
    {
        return Alert::where('status', 'OPEN')
            ->where('severity', 'CRITICAL')
            ->orderBy('raised_at', 'desc')
            ->pluck('id')
            ->all();
    }

    /**
     * Open alerts for the dashboard, CRITICAL first then newest-first. Returns
     * the array shape the dashboard blade expects:
     * id, type, severity, guard_name, site_name, title, age.
     */
    public function getActiveAlerts(?string $guardId = null): array
    {
        $query = Alert::with(['assignedGuard', 'shift.site'])
            ->where('status', 'OPEN')
            ->orderByRaw("FIELD(severity, 'CRITICAL', 'WARNING')")
            ->orderBy('raised_at', 'desc');

        if ($guardId) {
            $query->where('guard_id', $guardId);
        }

        return $query->get()->map(fn (Alert $a) => [
            'id' => $a->id,
            'type' => $a->type,
            'severity' => $a->severity,
            'is_offline' => (bool) $a->is_offline,
            'guard_name' => $a->assignedGuard
                ? trim($a->assignedGuard->first_name . ' ' . $a->assignedGuard->last_name)
                : 'Unknown',
            'site_name' => $a->shift?->site?->name ?? '—',
            'title' => $a->title,
            'age' => $a->raised_at?->diffForHumans() ?? '—',
        ])->toArray();
    }
}
