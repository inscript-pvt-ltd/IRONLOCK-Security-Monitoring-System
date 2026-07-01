<?php

namespace App\Domains\Reports\Builders;

use App\Domains\Reports\Exceptions\ReportGenerationException;
use App\Domains\Reports\Support\ReportType;
use App\Domains\Shifts\Models\Shift;
use App\Domains\Shifts\Models\ShiftEvent;

/**
 * Shift Audit Trail (SEC-001/003) — the complete append-only shift_events
 * record for one shift, in chronological order, with each event's
 * authoritative server timestamp, device timestamp and metadata. This is the
 * forensic/regulatory trail: it renders exactly what is stored, adds nothing,
 * and omits nothing.
 */
class ShiftAuditReportBuilder extends ReportBuilder
{
    public function type(): ReportType
    {
        return ReportType::ShiftAudit;
    }

    public function build(array $params): array
    {
        $shift = Shift::with(['assignedGuard', 'site'])
            ->find($params['shift_id'] ?? null);

        if (!$shift) {
            throw new ReportGenerationException('The shift for this report could not be found.');
        }

        $includeHashes = (bool) ($params['include_hashes'] ?? false);
        $iso = fn ($dt) => $dt ? \Illuminate\Support\Carbon::parse($dt)->utc()->format('Y-m-d\TH:i:s\Z') : null;

        $guard = $shift->assignedGuard;

        // Chronological (oldest first) so the trail reads as it happened. Ordered
        // by the authoritative server timestamp, matching the timeline's ordering.
        // Timestamps are ISO-8601 UTC so the on-screen page localises them.
        $events = ShiftEvent::where('shift_id', $shift->id)
            ->orderBy('server_received_at')
            ->orderBy('created_at')
            ->get()
            ->map(fn (ShiftEvent $e) => [
                'event_type' => $e->event_type,
                'recorded_at' => $iso($e->recorded_at),
                'server_received_at' => $iso($e->server_received_at ?? $e->created_at),
                'metadata' => $e->metadata ?? [],
            ])->all();

        return [
            'title' => $this->type()->label(),
            'view' => 'reports.pdf.shift_audit',
            'view_data' => [
                'title' => $this->type()->label(),
                'doc_tag' => 'SHIFT AUDIT TRAIL',
                'subtitle' => 'Append-only forensic event record for a single security shift',
                'shift' => [
                    'reference' => $shift->reference ?? $shift->id,
                    'guard_name' => $guard ? trim($guard->first_name . ' ' . $guard->last_name) : '—',
                    'site_name' => $shift->site->name ?? '—',
                    'status' => $shift->status,
                ],
                'events' => $events,
                'includes' => ['hashes' => $includeHashes],
            ],
            // Flat event-trail CSV. The ISO-UTC time cells are localised to the
            // viewer's timezone at download.
            'csv' => [
                'headers' => ['#', 'Server Received', 'Event Type', 'Device Time', 'Metadata'],
                'rows' => array_map(function ($e, $i) use ($includeHashes) {
                    $md = is_array($e['metadata']) ? $e['metadata'] : [];
                    if (!$includeHashes) {
                        unset($md['file_hash'], $md['sha256'], $md['sha256_hash']);
                    }

                    return [
                        $i + 1,
                        $e['server_received_at'],
                        $e['event_type'],
                        $e['recorded_at'],
                        empty($md) ? '' : json_encode($md, JSON_UNESCAPED_SLASHES),
                    ];
                }, $events, array_keys($events)),
            ],
            'shift_id' => $shift->id,
            'slug' => 'shift-audit-' . $this->slugify($shift->reference ?? $shift->id),
        ];
    }
}
