@extends('reports.pdf.layout')

@section('report-body')
    <table class="kv">
        <tr><td class="k">Guard</td><td class="v">{{ $guard_name }}</td>
            <td class="k">Employee code</td><td class="v">{{ $employee_code ?? '—' }}</td></tr>
        <tr><td class="k">Reference period</td><td class="v">{{ $period_start }} → {{ $period_end }} ({{ $reference_weeks }} weeks)</td>
            <td class="k">Weekly limit</td><td class="v">{{ number_format($weekly_limit, 0) }} h avg</td></tr>
    </table>

    <h2 class="section">Assessment</h2>
    <table class="kv">
        <tr>
            <td class="k">Total hours worked</td><td class="v">{{ number_format($total_hours, 2) }} h</td>
            <td class="k">Average weekly</td>
            <td class="v">
                {{ number_format($average_weekly, 2) }} h
                @if ($compliant)<span class="pill pill-ok">COMPLIANT</span>
                @else<span class="pill pill-bad">BREACH</span>@endif
            </td>
        </tr>
    </table>
    <p class="muted" style="margin-top:6px;">
        Working Time Regulations 1998: average weekly working time must not exceed 48 hours,
        measured over a rolling {{ $reference_weeks }}-week reference period.
    </p>

    <h2 class="section">Contributing Shifts</h2>
    @if (count($rows))
        <table class="grid">
            <thead><tr><th>Shift</th><th>Date</th><th>Status</th><th>Hours</th></tr></thead>
            <tbody>
                @foreach ($rows as $r)
                    <tr>
                        <td>{{ $r['reference'] }}</td>
                        <td>{{ $r['date'] ?? '—' }}</td>
                        <td>{{ ucfirst($r['status']) }}</td>
                        <td>{{ number_format((float) $r['hours'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="empty">No shifts fall within this reference period.</div>
    @endif
@endsection
