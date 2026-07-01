@extends('reports.web.layout')

@php
    // Builder emits ISO-8601 UTC (…Z); wrap datetimes in a localised <time>.
    $ts = function ($s) {
        if (!$s) return '<span style="color:#bbb">—</span>';
        $iso = strpos($s, ' ') !== false ? str_replace(' ', 'T', $s) . 'Z' : $s;
        return '<time class="rpt-ts" data-ts="' . e($iso) . '">' . e($s) . '</time>';
    };
    $c = $data['counts'];
@endphp

@section('report-body')
    <details class="wf-sec" open>
        <summary class="wf-sechead"><span class="wf-secnum">1</span><span class="wf-sectitle">Summary</span><span class="wf-secdesc">{{ $data['range_label'] }}</span><span class="wf-chev"></span></summary>
        <div class="wf-secbody">
            <div class="wf-cards">
                <div class="wf-card"><div class="ck">Total Alerts</div><div class="cv">{{ $c['total'] }}</div></div>
                <div class="wf-card"><div class="ck">Critical</div><div class="cv">{{ $c['critical'] }}</div></div>
                <div class="wf-card"><div class="ck">Warning</div><div class="cv">{{ $c['warning'] }}</div></div>
                <div class="wf-card"><div class="ck">Still Open</div><div class="cv">{{ $c['open'] }}</div></div>
            </div>
        </div>
    </details>

    <details class="wf-sec" open>
        <summary class="wf-sechead"><span class="wf-secnum">2</span><span class="wf-sectitle">Alerts</span><span class="wf-secdesc">{{ count($data['rows']) }} rows</span><span class="wf-chev"></span></summary>
        <div class="wf-secbody">
            @if (count($data['rows']))
                <table class="wf-table">
                    <thead><tr><th>Raised</th><th>Type</th><th>Severity</th><th>Status</th><th>Guard</th><th>Site</th><th>Acknowledged</th></tr></thead>
                    <tbody>
                        @foreach ($data['rows'] as $r)
                            <tr>
                                <td class="wf-mono">{!! $ts($r['raised_at']) !!}</td>
                                <td>{{ $r['type'] }}</td>
                                <td><span class="wf-badge {{ $r['severity'] === 'CRITICAL' ? 'strong' : 'warn' }}">{{ ucfirst(strtolower($r['severity'])) }}</span></td>
                                <td>{{ ucfirst(strtolower($r['status'])) }}</td>
                                <td>{{ $r['guard'] }}</td>
                                <td>{{ $r['site'] }}</td>
                                <td class="wf-mono">{!! $ts($r['acknowledged_at']) !!}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="wf-empty">No alerts were raised in this period.</div>
            @endif
        </div>
    </details>
@endsection
