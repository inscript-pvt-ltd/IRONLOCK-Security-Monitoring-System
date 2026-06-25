<?php

namespace App\Domains\Alerts\Services;

use App\Domains\Alerts\Models\Alert;
use App\Domains\Guards\Models\Guard;
use App\Domains\GPS\Models\GuardLocation;
use App\Domains\Sites\Models\Site;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AlertService — create, acknowledge, resolve and list supervisor alerts.
 *
 * Alerts are raised by the system (e.g. CheckZoneExitJob) and surfaced on the
 * admin dashboard. Acknowledgement/resolution are recorded against the admin
 * who actioned them. All field names follow the `alerts` migration via the
 * Alert model's fillable.
 */
class AlertService
{
    /**
     * Create an alert. Callers pass the type-specific fields (type, severity,
     * title, description); guard/shift/status/raised_at are defaulted here.
     */
    public function createAlert(string $guardId, string $shiftId, array $data): Alert
    {
        return Alert::create(array_merge([
            'guard_id' => $guardId,
            'shift_id' => $shiftId,
            'status' => 'OPEN',
            'raised_at' => now(),
        ], $data));
    }

    /**
     * Raise a CRITICAL ZONE_EXIT alert: the guard stayed outside the geofence
     * past the site grace period. The last-known coordinates and exit time are
     * baked into the description (there are no lat/lng columns on alerts).
     */
    public function createZoneExitAlert(
        string $guardId,
        string $shiftId,
        GuardLocation $location,
        ?Guard $guard,
        ?Site $site,
        string $exitedAt
    ): Alert {
        $guardName = $guard ? trim($guard->first_name . ' ' . $guard->last_name) : 'Unknown Guard';
        $siteName = $site?->name ?? 'Unknown Site';
        $graceMins = $site?->grace_period_minutes ?? 5;
        $exitedFmt = Carbon::parse($exitedAt)->format('H:i:s');

        return $this->createAlert($guardId, $shiftId, [
            'type' => 'ZONE_EXIT',
            'severity' => 'CRITICAL',
            'title' => "Zone Exit — {$guardName}",
            'description' => "{$guardName} has been outside {$siteName} for more than {$graceMins} minutes. "
                . "First exited at {$exitedFmt}. "
                . "Last known location: {$location->latitude}, {$location->longitude}.",
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
        string $occurredAt
    ): Alert {
        $guardName = $guard ? trim($guard->first_name . ' ' . $guard->last_name) : 'Unknown Guard';
        $occurredFmt = Carbon::parse($occurredAt)->format('H:i:s');

        $location = GuardLocation::where('guard_id', $guardId)
            ->orderByDesc('recorded_at')
            ->first();
        $where = $location
            ? "Last known location: {$location->latitude}, {$location->longitude}."
            : 'No recent location on file.';

        return $this->createAlert($guardId, $shiftId, [
            'type' => 'GUARD_UNRESPONSIVE',
            'severity' => 'CRITICAL',
            'title' => "Unresponsive — {$guardName}",
            'description' => "{$guardName} failed a wakefulness check ({$reason}) at {$occurredFmt}. "
                . "Welfare check required. {$where}",
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
     * Record that an alert has been resolved (the situation is closed out).
     */
    public function resolveAlert(string $alertId, string $resolvedBy): bool
    {
        $alert = Alert::find($alertId);

        if (!$alert) {
            return false;
        }

        $alert->update([
            'resolved_by' => $resolvedBy,
            'resolved_at' => now(),
        ]);

        return true;
    }

    /**
     * The Alert Feed worklist (ALT-006): alerts matching the supervisor's
     * filters, CRITICAL-first then newest-first, paginated. Distinct from
     * getActiveAlerts() (which is the OPEN-only, unpaginated dashboard preview).
     *
     * @param  array  $filters  severity|type|site_id|guard_id|status (any omitted/blank = no constraint)
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
            'guard_name' => $a->assignedGuard
                ? trim($a->assignedGuard->first_name . ' ' . $a->assignedGuard->last_name)
                : 'Unknown',
            'site_name' => $a->shift?->site?->name ?? '—',
            'title' => $a->title,
            'age' => $a->raised_at->diffForHumans(),
        ])->toArray();
    }
}
