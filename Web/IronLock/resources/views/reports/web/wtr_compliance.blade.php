@extends('reports.web.layout')

@section('report-body')
    <details class="wf-sec" open>
        <summary class="wf-sechead"><span class="wf-secnum">1</span><span class="wf-sectitle">Assessment</span><span class="wf-secdesc">{{ $data['reference_weeks'] }}-week reference period</span><span class="wf-chev"></span></summary>
        <div class="wf-secbody">
            <div class="wf-info">
                <div class="wf-row"><span class="k">Guard</span><span class="v">{{ $data['guard_name'] }}</span></div>
                <div class="wf-row"><span class="k">Employee Code</span><span class="v wf-mono">{{ $data['employee_code'] ?? '—' }}</span></div>
                <div class="wf-row"><span class="k">Reference Period</span><span class="v">{{ $data['period_start'] }} → {{ $data['period_end'] }}</span></div>
                <div class="wf-row"><span class="k">Weekly Limit</span><span class="v">{{ number_format($data['weekly_limit'], 0) }} h avg</span></div>
                <div class="wf-row"><span class="k">Total Hours Worked</span><span class="v">{{ number_format($data['total_hours'], 2) }} h</span></div>
                <div class="wf-row"><span class="k">Average Weekly</span>
                    <span class="v">{{ number_format($data['average_weekly'], 2) }} h
                        <span class="wf-badge {{ $data['compliant'] ? 'ok' : 'bad' }}">{{ $data['compliant'] ? 'COMPLIANT' : 'BREACH' }}</span>
                    </span>
                </div>
            </div>
            <div class="wf-note">Working Time Regulations 1998: average weekly working time must not exceed 48 hours over a rolling {{ $data['reference_weeks'] }}-week reference period.</div>
        </div>
    </details>

    <details class="wf-sec" open>
        <summary class="wf-sechead"><span class="wf-secnum">2</span><span class="wf-sectitle">Contributing Shifts</span><span class="wf-secdesc">{{ count($data['rows']) }} shifts</span><span class="wf-chev"></span></summary>
        <div class="wf-secbody">
            @if (count($data['rows']))
                <table class="wf-table">
                    <thead><tr><th>Shift</th><th>Date</th><th>Status</th><th>Hours</th></tr></thead>
                    <tbody>
                        @foreach ($data['rows'] as $r)
                            <tr>
                                <td class="wf-mono">{{ $r['reference'] }}</td>
                                <td>{{ $r['date'] ?? '—' }}</td>
                                <td>{{ ucfirst($r['status']) }}</td>
                                <td>{{ number_format((float) $r['hours'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="wf-empty">No shifts fall within this reference period.</div>
            @endif
        </div>
    </details>
@endsection
