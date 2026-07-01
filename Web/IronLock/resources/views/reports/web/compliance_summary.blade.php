@extends('reports.web.layout')

@php
    $totals = $data['totals'];
    $pct = fn ($v) => $v === null ? '—' : number_format((float) $v, 1) . '%';
    $yn = fn ($v) => $v === null ? '—' : ($v ? 'Y' : 'N');
@endphp

@section('report-body')
    <details class="wf-sec" open>
        <summary class="wf-sechead"><span class="wf-secnum">1</span><span class="wf-sectitle">Scope</span><span class="wf-secdesc">{{ $data['range_label'] ?? $data['scope'] }}</span><span class="wf-chev"></span></summary>
        <div class="wf-secbody">
            <div class="wf-cards">
                <div class="wf-card"><div class="ck">Shifts</div><div class="cv">{{ $totals['shifts'] }}</div></div>
                <div class="wf-card"><div class="ck">WTR Violations</div><div class="cv">{{ $totals['wtr_violations'] }}</div></div>
                <div class="wf-card"><div class="ck">Wakefulness</div><div class="cv">{{ $totals['wakefulness_confirmed'] }}/{{ $totals['wakefulness_total'] }}</div></div>
                <div class="wf-card"><div class="ck">Photos Approved</div><div class="cv">{{ $totals['photos_approved'] }}/{{ $totals['photos_images'] }}</div></div>
            </div>
        </div>
    </details>

    <details class="wf-sec" open>
        <summary class="wf-sechead"><span class="wf-secnum">2</span><span class="wf-sectitle">Per-Shift Compliance</span><span class="wf-secdesc">{{ count($data['rows']) }} shifts</span><span class="wf-chev"></span></summary>
        <div class="wf-secbody">
            @if (count($data['rows']))
                <table class="wf-table">
                    <thead><tr><th>Shift</th><th>Date</th><th>Guard</th><th>Site</th><th>Hours</th><th>WTR</th><th>On time</th><th>GPS</th><th>Wake</th><th>Photos</th></tr></thead>
                    <tbody>
                        @foreach ($data['rows'] as $r)
                            <tr>
                                <td class="wf-mono">{{ $r['reference'] }}</td>
                                <td>{{ $r['date'] ?? '—' }}</td>
                                <td>{{ $r['guard'] }}</td>
                                <td>{{ $r['site'] }}</td>
                                <td>{{ $r['actual_hours'] !== null ? number_format((float) $r['actual_hours'], 2) : '—' }}</td>
                                <td>
                                    @if ($r['wtr_violations'] > 0)<span class="wf-badge warn">{{ $r['wtr_violations'] }}</span>
                                    @else<span class="wf-badge ok">OK</span>@endif
                                </td>
                                <td>{{ $yn($r['started_on_time']) }}/{{ $yn($r['ended_on_time']) }}</td>
                                <td>{{ $pct($r['gps_coverage']) }}</td>
                                <td>{{ $r['wakefulness']['confirmed'] }}/{{ $r['wakefulness']['total'] }}</td>
                                <td>{{ $r['photos']['approved'] }}/{{ $r['photos']['images'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="wf-empty">No completed shifts match this scope.</div>
            @endif
        </div>
    </details>
@endsection
