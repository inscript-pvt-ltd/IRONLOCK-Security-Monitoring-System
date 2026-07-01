@extends('reports.web.layout')

@php $c = $data['counts']; @endphp

@section('report-body')
    <details class="wf-sec" open>
        <summary class="wf-sechead"><span class="wf-secnum">1</span><span class="wf-sectitle">Summary</span><span class="wf-secdesc">As of {{ $data['generated_on'] }}</span><span class="wf-chev"></span></summary>
        <div class="wf-secbody">
            <div class="wf-cards">
                <div class="wf-card"><div class="ck">Active Guards</div><div class="cv">{{ $c['total'] }}</div></div>
                <div class="wf-card"><div class="ck">Valid</div><div class="cv">{{ $c['valid'] }}</div></div>
                <div class="wf-card"><div class="ck">Expiring &lt;30d</div><div class="cv">{{ $c['expiring'] }}</div></div>
                <div class="wf-card"><div class="ck">Expired</div><div class="cv">{{ $c['expired'] }}</div></div>
            </div>
        </div>
    </details>

    <details class="wf-sec" open>
        <summary class="wf-sechead"><span class="wf-secnum">2</span><span class="wf-sectitle">SIA Licences</span><span class="wf-secdesc">{{ count($data['rows']) }} guards</span><span class="wf-chev"></span></summary>
        <div class="wf-secbody">
            @if (count($data['rows']))
                <table class="wf-table">
                    <thead><tr><th>Code</th><th>Guard</th><th>Licence No.</th><th>Type</th><th>Expiry</th><th>Days</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach ($data['rows'] as $r)
                            @php $style = $r['status'] === 'VALID' ? 'ok' : ($r['status'] === 'EXPIRING' ? 'warn' : ($r['status'] === 'EXPIRED' ? 'bad' : 'muted')); @endphp
                            <tr>
                                <td class="wf-mono">{{ $r['employee_code'] }}</td>
                                <td>{{ $r['name'] }}</td>
                                <td class="wf-mono">{{ $r['licence_number'] }}</td>
                                <td>{{ $r['licence_type'] }}</td>
                                <td>{{ $r['expiry'] }}</td>
                                <td>{{ $r['days_remaining'] === null ? '—' : $r['days_remaining'] }}</td>
                                <td><span class="wf-badge {{ $style }}">{{ $r['status'] }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="wf-empty">No active guards on the roster.</div>
            @endif
        </div>
    </details>
@endsection
