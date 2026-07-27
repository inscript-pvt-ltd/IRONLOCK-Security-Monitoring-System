<?php

namespace App\Console\Commands;

use App\Domains\Alerts\Services\AlertService;
use App\Domains\Compliance\Services\ComplianceCalculator;
use App\Domains\Shifts\Models\Shift;
use App\Domains\Shifts\Models\ShiftEvent;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Alert on a scheduled photo/wakefulness verification mark that fell inside a
 * recorded offline window and left NO trace at all — neither an offline
 * capture flushed by the device nor an online catch-up dispatch. Both
 * dispatchers (photos:dispatch-scheduled, wakefulness:dispatch) deliberately
 * skip firing a mark online once it is older than catchup_staleness_seconds
 * (default 180s), to avoid a "surprise" request landing long after the guard
 * has already reconnected — the app's own offline capture is meant to have
 * covered it. When the device never captured it either, the mark simply has
 * no trace, and until now that produced only a neutral, report-only "Missed"
 * note (ComplianceCalculator::missedScheduleMarks) with no supervisor-facing
 * alert anywhere — a genuine "did the guard ever verify?" gap could go
 * completely unseen outside a generated report.
 *
 * This sweep promotes that case to the same CRITICAL, is_offline alert an
 * offline wakefulness failure already gets, so it surfaces on the Alert Feed
 * / dashboard exactly like every other unresponsive-guard case, for both
 * photo and wakefulness schedules.
 *
 * Runs every minute. Waits PROCESS_DELAY_SECONDS after a gap's COMMS_GAP_END
 * before treating its marks as final — the device's GPS / wakefulness /
 * photo offline-flush calls can land as separate HTTP requests, so a mark
 * must not be declared trace-less before they've all had a chance to arrive.
 * Idempotent: dedups against a PHOTO_MARK_MISSED / WAKEFULNESS_MARK_MISSED
 * audit event already written for that exact mark, so nothing is ever
 * alerted twice, and (mirroring missedScheduleMarks' own self-healing
 * design) a late-arriving offline capture simply stops a mark from being
 * "missed" before this sweep gets to it — no synthetic rows to reconcile.
 */
class SweepMissedScheduleMarks extends Command
{
    protected $signature = 'schedule:missed-sweep';

    protected $description = 'Alert on scheduled photo/wakefulness verification marks left with no trace after a comms gap closes';

    /** Grace after a COMMS_GAP_END before its marks are treated as final. */
    private const PROCESS_DELAY_SECONDS = 120;

    /** Bound how far back we look for a closed gap, so the sweep stays cheap. */
    private const LOOKBACK_HOURS = 48;

    public function handle(ComplianceCalculator $compliance, AlertService $alerts): int
    {
        $now = Carbon::now();
        $cutoff = $now->copy()->subSeconds(self::PROCESS_DELAY_SECONDS);
        $since = $now->copy()->subHours(self::LOOKBACK_HOURS);

        $shiftIds = ShiftEvent::where('event_type', 'COMMS_GAP_END')
            ->where('recorded_at', '<', $cutoff)
            ->where('recorded_at', '>=', $since)
            ->distinct()
            ->pluck('shift_id');

        $raised = 0;

        foreach ($shiftIds as $shiftId) {
            $shift = Shift::with('assignedGuard')->find($shiftId);
            if (!$shift) {
                continue;
            }

            $missed = $compliance->missedScheduleMarks($shift);

            foreach (['photos' => 'PHOTO_MARK_MISSED', 'wakefulness' => 'WAKEFULNESS_MARK_MISSED'] as $key => $eventType) {
                foreach ($missed[$key] as $markIso) {
                    $mark = Carbon::parse($markIso);

                    // Belt-and-braces alongside the COMMS_GAP_END cutoff above: never
                    // act on a mark that is itself still inside the process-delay
                    // window relative to now.
                    if ($mark->greaterThan($cutoff)) {
                        continue;
                    }

                    if ($this->alreadyAlerted($shift->id, $eventType, $markIso)) {
                        continue;
                    }

                    $this->raise($shift, $key, $eventType, $mark, $alerts);
                    $raised++;
                }
            }
        }

        $this->info("Raised {$raised} missed-schedule-mark alert(s).");

        return self::SUCCESS;
    }

    private function alreadyAlerted(string $shiftId, string $eventType, string $markIso): bool
    {
        return ShiftEvent::where('shift_id', $shiftId)
            ->where('event_type', $eventType)
            ->where('metadata->mark_at', $markIso)
            ->exists();
    }

    private function raise(Shift $shift, string $key, string $eventType, Carbon $mark, AlertService $alerts): void
    {
        try {
            ShiftEvent::create([
                'id' => (string) Str::uuid(),
                'shift_id' => $shift->id,
                'guard_id' => $shift->guard_id,
                'event_type' => $eventType,
                'metadata' => [
                    'mark_at' => $mark->toISOString(),
                    'reason' => 'NO_TRACE_AFTER_RECONNECT',
                ],
                'recorded_at' => $mark,
                'server_received_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            // Audit is non-critical; still raise the alert below.
        }

        try {
            $guard = $shift->assignedGuard;
            $name = $guard ? trim($guard->first_name . ' ' . $guard->last_name) : 'Unknown Guard';
            $markFmt = $mark->format('H:i:s');

            // Built inline rather than via AlertService::createGuardUnresponsiveAlert:
            // that helper's copy ("flushed on reconnect") assumes a failure record
            // actually arrived from the device. Here nothing ever arrived — the
            // wording has to say that plainly instead.
            if ($key === 'photos') {
                $alerts->createAlert($shift->guard_id, $shift->id, [
                    'type' => 'PHOTO_TIMEOUT',
                    'severity' => 'CRITICAL',
                    'is_offline' => true,
                    'title' => 'Photo Missed (Offline) — ' . $name,
                    'description' => "{$name} had a verification photo due at {$markFmt} during a connectivity gap. "
                        . 'The device never captured or flushed it, and the gap closed too long ago for a live '
                        . 'catch-up request. No photo was ever provided for this check — review the shift and confirm welfare.',
                ]);
            } else {
                $alerts->createAlert($shift->guard_id, $shift->id, [
                    'type' => 'GUARD_UNRESPONSIVE',
                    'severity' => 'CRITICAL',
                    'is_offline' => true,
                    'title' => 'Unresponsive (Offline) — ' . $name,
                    'description' => "{$name} had a wakefulness check due at {$markFmt} during a connectivity gap. "
                        . 'No response was ever captured or flushed by the device, and the gap closed too long ago '
                        . 'for a live catch-up challenge. Review the shift and confirm welfare.',
                ]);
            }
        } catch (\Throwable $e) {
            // Alerting is best-effort; the audit event already holds.
        }
    }
}
