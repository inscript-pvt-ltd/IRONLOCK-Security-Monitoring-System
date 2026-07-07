{{-- Previous Exports rows. Rendered both server-side on first paint and by the
     paged JSON endpoint (ReportController@list) — one source of truth for the
     row markup. Expects $previous (a paginator or collection of Report). The
     viewer-timezone localisation of the "Generated" time + the ?tz= tagging of
     the download links is done in JS after each (re)render. --}}
@forelse ($previous as $r)
    @php $fmts = $r->downloadFormats(); @endphp
    <tr data-report-row="{{ $r->id }}">
        <td class="exp-check-cell"><input type="checkbox" class="exp-check" data-id="{{ $r->id }}"></td>
        <td>{{ $r->typeLabel() }}</td>
        <td>{{ $r->shift->reference ?? '—' }}</td>
        <td><time class="rpt-local" data-ts="{{ optional($r->generated_at)->utc()->format('Y-m-d\TH:i:s\Z') }}">{{ optional($r->generated_at)->format('d M Y H:i') }} UTC</time></td>
        <td>
            <div class="exp-actions">
                <a class="ico-btn" href="{{ route('admin.reports.show', $r->id) }}" title="Open report">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
                </a>
                <div class="dl-wrap">
                    <button type="button" class="ico-btn dl-toggle" title="Download">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    </button>
                    <div class="dl-menu hidden">
                        @foreach ($fmts as $fmt)
                            <a href="{{ route('admin.reports.download', ['report' => $r->id, 'format' => $fmt]) }}">↓ {{ strtoupper($fmt) }}</a>
                        @endforeach
                    </div>
                </div>
                <button type="button" class="ico-btn danger del-btn" data-id="{{ $r->id }}" title="Delete report">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                </button>
            </div>
        </td>
    </tr>
@empty
    <tr id="exports-empty-row"><td colspan="5"><div class="exports-empty">No reports have been generated yet.</div></td></tr>
@endforelse
