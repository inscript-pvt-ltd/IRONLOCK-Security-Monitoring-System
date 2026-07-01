@extends('reports.pdf.layout')

@php
    $pct = fn ($v) => $v === null ? '—' : number_format((float) $v, 1) . '%';
    $yn = fn ($v) => $v === null ? '—' : ($v ? 'Y' : 'N');
@endphp

@section('report-body')
    <table class="kv">
        <tr><td class="k">Scope</td><td class="v">{{ $scope }}</td></tr>
        @if ($range_label)<tr><td class="k">Period</td><td class="v">{{ $range_label }}</td></tr>@endif
        <tr><td class="k">Shifts included</td><td class="v">{{ $totals['shifts'] }}</td></tr>
    </table>

    <h2 class="section">Per-Shift Compliance</h2>
    @if (count($rows))
        <table class="grid">
            <thead>
                <tr>
                    <th>Shift</th><th>Date</th><th>Guard</th><th>Site</th>
                    <th>Hours</th><th>WTR</th><th>On time</th><th>GPS</th><th>Wake</th><th>Photos</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $r)
                    <tr>
                        <td>{{ $r['reference'] }}</td>
                        <td>{{ $r['date'] ?? '—' }}</td>
                        <td>{{ $r['guard'] }}</td>
                        <td>{{ $r['site'] }}</td>
                        <td>{{ $r['actual_hours'] !== null ? number_format((float) $r['actual_hours'], 2) : '—' }}</td>
                        <td>
                            @if ($r['wtr_violations'] > 0)<span class="pill pill-warn">{{ $r['wtr_violations'] }}</span>
                            @else<span class="pill pill-ok">OK</span>@endif
                        </td>
                        <td>{{ $yn($r['started_on_time']) }}/{{ $yn($r['ended_on_time']) }}</td>
                        <td>{{ $pct($r['gps_coverage']) }}</td>
                        <td>{{ $r['wakefulness']['confirmed'] }}/{{ $r['wakefulness']['total'] }}</td>
                        <td>{{ $r['photos']['approved'] }}/{{ $r['photos']['images'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals">
            <strong>Totals:</strong>
            {{ $totals['shifts'] }} shifts &nbsp;·&nbsp;
            WTR violations: {{ $totals['wtr_violations'] }} &nbsp;·&nbsp;
            Wakefulness confirmed: {{ $totals['wakefulness_confirmed'] }}/{{ $totals['wakefulness_total'] }} &nbsp;·&nbsp;
            Photos approved: {{ $totals['photos_approved'] }}/{{ $totals['photos_images'] }}
        </div>
    @else
        <div class="empty">No completed shifts match this scope.</div>
    @endif
@endsection
