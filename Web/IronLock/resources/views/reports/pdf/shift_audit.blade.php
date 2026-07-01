@extends('reports.pdf.layout')

@php
    use Illuminate\Support\Carbon;
    $tz = $report_tz ?? 'UTC';
    $tzLabel = $report_tz_label ?? 'UTC';
    $f = fn ($iso) => $iso ? Carbon::parse($iso)->setTimezone($tz)->format('d M Y H:i:s') : '—';
@endphp

@section('report-body')
    <table class="kv">
        <tr><td class="k">Shift</td><td class="v">{{ $shift['reference'] }}</td>
            <td class="k">Guard</td><td class="v">{{ $shift['guard_name'] }}</td></tr>
        <tr><td class="k">Site</td><td class="v">{{ $shift['site_name'] }}</td>
            <td class="k">Events</td><td class="v">{{ count($events) }}</td></tr>
    </table>

    <p class="muted" style="margin-top:6px;">
        Append-only audit trail, in chronological order. Timestamps are the authoritative
        server-receipt times (shown in {{ $tzLabel }}); device-reported times are shown for
        reference only.
    </p>

    <h2 class="section">Event Trail</h2>
    @if (count($events))
        <table class="grid">
            <thead>
                <tr><th>#</th><th>Server Received</th><th>Event</th><th>Device Time</th><th>Detail</th></tr>
            </thead>
            <tbody>
                @foreach ($events as $i => $e)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $f($e['server_received_at']) }}</td>
                        <td>{{ $e['event_type'] }}</td>
                        <td>{{ $f($e['recorded_at']) }}</td>
                        <td class="muted" style="font-size:8px;">
                            @php
                                $md = is_array($e['metadata']) ? $e['metadata'] : [];
                                if (!$includes['hashes']) { unset($md['file_hash'], $md['sha256'], $md['sha256_hash']); }
                            @endphp
                            {{ empty($md) ? '—' : json_encode($md, JSON_UNESCAPED_SLASHES) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="empty">No events are recorded for this shift.</div>
    @endif
@endsection
