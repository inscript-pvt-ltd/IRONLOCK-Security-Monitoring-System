@extends('reports.pdf.layout')

@php
    use Illuminate\Support\Carbon;
    $tz = $report_tz ?? 'UTC';
    $tzLabel = $report_tz_label ?? 'UTC';
    $f = fn ($iso) => $iso ? Carbon::parse($iso)->setTimezone($tz)->format('d M Y H:i') : '—';
@endphp

@section('report-body')
    <p class="muted" style="margin:0 0 8px;">All times shown in {{ $tzLabel }}.</p>
    <table class="kv">
        <tr><td class="k">Period</td><td class="v">{{ $range_label }}</td></tr>
        <tr>
            <td class="k">Totals</td>
            <td class="v">
                {{ $counts['total'] }} alerts &nbsp;·&nbsp;
                <span class="pill pill-bad">{{ $counts['critical'] }} critical</span>
                <span class="pill pill-warn">{{ $counts['warning'] }} warning</span>
                <span class="pill pill-muted">{{ $counts['open'] }} open</span>
            </td>
        </tr>
    </table>

    <h2 class="section">Alerts</h2>
    @if (count($rows))
        <table class="grid">
            <thead>
                <tr><th>Raised</th><th>Type</th><th>Severity</th><th>Status</th><th>Guard</th><th>Site</th><th>Acknowledged</th></tr>
            </thead>
            <tbody>
                @foreach ($rows as $r)
                    <tr>
                        <td>{{ $f($r['raised_at']) }}</td>
                        <td>{{ $r['type'] }}</td>
                        <td>
                            <span class="pill {{ $r['severity'] === 'CRITICAL' ? 'pill-bad' : 'pill-warn' }}">{{ $r['severity'] }}</span>
                        </td>
                        <td>{{ $r['status'] }}</td>
                        <td>{{ $r['guard'] }}</td>
                        <td>{{ $r['site'] }}</td>
                        <td>{{ $f($r['acknowledged_at']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="empty">No alerts were raised in this period.</div>
    @endif
@endsection
