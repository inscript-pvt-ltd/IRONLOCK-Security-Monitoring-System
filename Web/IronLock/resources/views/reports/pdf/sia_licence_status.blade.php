@extends('reports.pdf.layout')

@section('report-body')
    <table class="kv">
        <tr><td class="k">As of</td><td class="v">{{ $generated_on }}</td></tr>
        <tr>
            <td class="k">Roster</td>
            <td class="v">
                {{ $counts['total'] }} active guards &nbsp;·&nbsp;
                <span class="pill pill-ok">{{ $counts['valid'] }} valid</span>
                <span class="pill pill-warn">{{ $counts['expiring'] }} expiring</span>
                <span class="pill pill-bad">{{ $counts['expired'] }} expired</span>
            </td>
        </tr>
    </table>

    <h2 class="section">SIA Licences</h2>
    @if (count($rows))
        <table class="grid">
            <thead>
                <tr><th>Code</th><th>Guard</th><th>Licence No.</th><th>Type</th><th>Expiry</th><th>Days</th><th>Status</th></tr>
            </thead>
            <tbody>
                @foreach ($rows as $r)
                    <tr>
                        <td>{{ $r['employee_code'] }}</td>
                        <td>{{ $r['name'] }}</td>
                        <td>{{ $r['licence_number'] }}</td>
                        <td>{{ $r['licence_type'] }}</td>
                        <td>{{ $r['expiry'] }}</td>
                        <td>{{ $r['days_remaining'] === null ? '—' : $r['days_remaining'] }}</td>
                        <td>
                            @php
                                $cls = match ($r['status']) {
                                    'VALID' => 'pill-ok',
                                    'EXPIRING' => 'pill-warn',
                                    'EXPIRED' => 'pill-bad',
                                    default => 'pill-muted',
                                };
                            @endphp
                            <span class="pill {{ $cls }}">{{ $r['status'] }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="empty">No active guards on the roster.</div>
    @endif
@endsection
