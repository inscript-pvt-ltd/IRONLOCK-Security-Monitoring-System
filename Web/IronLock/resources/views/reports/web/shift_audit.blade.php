@extends('reports.web.layout')

@php
    $shift = $data['shift'];
    $events = $data['events'];
    $includeHashes = $data['includes']['hashes'] ?? false;
    $t = fn ($iso, $cls = 'rpt-ts') => $iso
        ? '<time class="' . $cls . '" data-ts="' . e($iso) . '">' . e($iso) . '</time>'
        : '<span style="color:#bbb">—</span>';
@endphp

@section('report-body')
    <details class="wf-sec" open>
        <summary class="wf-sechead"><span class="wf-secnum">1</span><span class="wf-sectitle">Shift</span><span class="wf-secdesc">Subject of the audit trail</span><span class="wf-chev"></span></summary>
        <div class="wf-secbody">
            <div class="wf-info">
                <div class="wf-row"><span class="k">Shift Reference</span><span class="v wf-mono">{{ $shift['reference'] }}</span></div>
                <div class="wf-row"><span class="k">Guard</span><span class="v">{{ $shift['guard_name'] }}</span></div>
                <div class="wf-row"><span class="k">Site</span><span class="v">{{ $shift['site_name'] }}</span></div>
                <div class="wf-row"><span class="k">Status</span><span class="v">{{ ucfirst($shift['status']) }}</span></div>
                <div class="wf-row"><span class="k">Total Events</span><span class="v">{{ count($events) }}</span></div>
            </div>
            <div class="wf-note">Append-only forensic trail. The authoritative time is the server-receipt time (localised to your timezone); the device time is retained for reference only.</div>
        </div>
    </details>

    <details class="wf-sec" open>
        <summary class="wf-sechead"><span class="wf-secnum">2</span><span class="wf-sectitle">Event Trail</span><span class="wf-secdesc">{{ count($events) }} events</span><span class="wf-chev"></span></summary>
        <div class="wf-secbody">
            @if (count($events))
                <table class="wf-table">
                    <thead><tr><th style="width:34px">#</th><th>Server Received</th><th>Event</th><th>Device Time</th><th>Detail</th></tr></thead>
                    <tbody>
                        @foreach ($events as $i => $e)
                            @php
                                $md = is_array($e['metadata']) ? $e['metadata'] : [];
                                if (!$includeHashes) { unset($md['file_hash'], $md['sha256'], $md['sha256_hash']); }
                            @endphp
                            <tr>
                                <td class="wf-mono">{{ $i + 1 }}</td>
                                <td class="wf-mono">{!! $t($e['server_received_at']) !!}</td>
                                <td>{{ $e['event_type'] }}</td>
                                <td class="wf-mono">{!! $t($e['recorded_at']) !!}</td>
                                <td style="font-size:11px;color:#888">{{ empty($md) ? '—' : json_encode($md, JSON_UNESCAPED_SLASHES) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="wf-empty">No events are recorded for this shift.</div>
            @endif
        </div>
    </details>
@endsection
