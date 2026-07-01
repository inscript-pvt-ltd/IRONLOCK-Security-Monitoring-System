{{--
    On-screen report page (Phase 8) — the standalone, print-friendly document a
    report opens as after generation. Light "wf-" design from
    Details/Important/IronLock Report Details.html.

    All timestamps render as <time class="rpt-ts" data-ts="{iso}"> and a JS pass
    localises them to the viewer's own timezone (TIMESTAMP_HANDLING.md) — the
    admin never sees raw UTC here. Downloads (PDF / CSV) and Print live in the
    top action bar; they hit the admin-gated report routes.

    Expected vars: $report (Report model), $meta (array), $data (array).
--}}
@php
    $docTag = $data['doc_tag'] ?? strtoupper($report->typeLabel());
    $title = $data['title'] ?? $report->typeLabel();
    $subtitle = $data['subtitle'] ?? '';
    $statusLabel = $data['shift_info']['status_label'] ?? 'Generated';
    $shiftRef = $data['shift_info']['shift_reference'] ?? null;
    $formats = $report->downloadFormats();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — IronLock</title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;background:#e9e9e7;font-family:"Helvetica Neue",Arial,system-ui,sans-serif;color:#3a3a3a;-webkit-font-smoothing:antialiased}
        a{color:inherit}
        .wf-page{max-width:1040px;margin:28px auto;background:#fff;border:1px solid #cfcfcf}
        .wf-mono{font-family:ui-monospace,"SF Mono",Menlo,Consolas,monospace}
        .wf-topbar{display:flex;justify-content:space-between;gap:8px;padding:14px 36px;border-bottom:1px solid #e2e2e2;flex-wrap:wrap;align-items:center}
        .wf-back{font-size:12px;color:#777;text-decoration:none}
        .wf-back:hover{color:#3a3a3a}
        .wf-actions{display:flex;gap:8px;flex-wrap:wrap}
        .wf-btn{display:inline-flex;align-items:center;gap:7px;font-size:12px;letter-spacing:.02em;padding:8px 14px;border:1px solid #b9b9b9;background:#fafafa;color:#444;cursor:pointer;border-radius:3px;text-decoration:none}
        .wf-btn:hover{background:#f0f0f0;border-color:#9a9a9a}
        .wf-btn.solid{background:#3a3a3a;color:#fff;border-color:#3a3a3a}
        .wf-head{padding:30px 36px 26px;border-bottom:2px solid #3a3a3a}
        .wf-head-top{display:flex;justify-content:space-between;align-items:flex-start;gap:24px}
        .wf-logo{display:flex;align-items:center;gap:11px}
        /* Real IronLock mark, rendered pure black-on-white to match the report's monochrome print aesthetic. */
        .wf-logo-img{height:42px;width:auto;display:block;filter:grayscale(1) brightness(0);opacity:.9;-webkit-print-color-adjust:exact;print-color-adjust:exact}
        .wf-logo-img.sm{height:24px}
        .wf-doctag{font-size:10px;letter-spacing:.18em;color:#888;border:1px dashed #b9b9b9;padding:5px 10px;border-radius:3px}
        .wf-title{font-size:26px;font-weight:700;color:#2c2c2c;margin:22px 0 4px;letter-spacing:-.01em}
        .wf-subtitle{font-size:13px;color:#8a8a8a}
        .wf-meta{display:grid;grid-template-columns:repeat(3,1fr);gap:14px 40px;margin-top:22px}
        .wf-meta-item{border-left:2px solid #dcdcdc;padding-left:12px}
        .wf-meta-k{font-size:9.5px;letter-spacing:.13em;text-transform:uppercase;color:#a2a2a2;margin-bottom:3px}
        .wf-meta-v{font-size:13px;color:#3a3a3a}
        .wf-badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;padding:3px 9px;border:1px solid #9a9a9a;border-radius:3px;color:#444;background:#f6f6f6;white-space:nowrap}
        .wf-badge .dot{font-size:9px;line-height:1}
        .wf-badge.strong{border-color:#3a3a3a;background:#3a3a3a;color:#fff}
        .wf-badge.ok{border-color:#3a3a3a}
        .wf-badge.warn,.wf-badge.dash{border-style:dashed}
        .wf-badge.bad{border-color:#8a2a2a;color:#8a2a2a}
        .wf-badge.muted{color:#999;border-color:#cfcfcf}
        .wf-sec{border-bottom:1px solid #e6e6e6}
        .wf-sechead{list-style:none;cursor:pointer;display:flex;align-items:center;gap:12px;padding:18px 36px;user-select:none}
        .wf-sechead::-webkit-details-marker{display:none}
        .wf-sechead:hover{background:#fafafa}
        .wf-secnum{font-size:11px;font-weight:700;color:#fff;background:#3a3a3a;width:24px;height:24px;border-radius:3px;display:inline-flex;align-items:center;justify-content:center;flex:none}
        .wf-sectitle{font-size:15px;font-weight:700;color:#2c2c2c;letter-spacing:.01em}
        .wf-secdesc{font-size:11px;color:#aaa;margin-left:auto;font-weight:400}
        .wf-chev{width:9px;height:9px;border-right:2px solid #999;border-bottom:2px solid #999;transform:rotate(45deg);transition:transform .15s;flex:none;margin-left:8px}
        details[open] .wf-chev{transform:rotate(-135deg)}
        .wf-secbody{padding:6px 36px 30px}
        .wf-info{display:grid;grid-template-columns:repeat(2,1fr);gap:0 48px}
        .wf-row{display:flex;justify-content:space-between;gap:16px;padding:9px 0;border-bottom:1px dashed #e4e4e4;font-size:13px}
        .wf-row .k{color:#999}
        .wf-row .v{color:#3a3a3a;text-align:right}
        .wf-cards{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
        .wf-card{border:1px solid #dcdcdc;border-radius:4px;padding:13px 14px;display:flex;flex-direction:column;gap:9px}
        .wf-card .ck{font-size:10px;letter-spacing:.07em;text-transform:uppercase;color:#a2a2a2}
        .wf-card .cv{font-size:15px;font-weight:600;color:#333}
        .wf-table{width:100%;border-collapse:collapse;font-size:12.5px}
        .wf-table th{text-align:left;font-size:9.5px;letter-spacing:.1em;text-transform:uppercase;color:#a2a2a2;font-weight:600;padding:9px 10px;border-bottom:1.5px solid #cfcfcf}
        .wf-table td{padding:10px;border-bottom:1px solid #ececec;color:#444;vertical-align:top}
        .wf-table tr:hover td{background:#fafafa}
        .wf-table .wf-mono{font-size:12px;color:#555}
        .wf-tl{position:relative;padding-left:28px;margin-top:6px}
        .wf-tl::before{content:"";position:absolute;left:7px;top:6px;bottom:6px;width:2px;background:#dcdcdc}
        .wf-tl-item{position:relative;padding:0 0 22px 18px}
        .wf-tl-item::before{content:"";position:absolute;left:-25px;top:3px;width:14px;height:14px;border:2px solid #3a3a3a;background:#fff;border-radius:50%}
        .wf-tl-item.dash::before{border-style:dashed}
        .wf-tl-time{font-size:11px;color:#999;margin-bottom:2px}
        .wf-tl-type{font-size:13px;font-weight:600;color:#2c2c2c;display:flex;align-items:center;gap:8px}
        .wf-tl-desc{font-size:12px;color:#888;margin-top:2px}
        .wf-ph{position:relative;border:1px dashed #b9b9b9;background:#f6f6f6;display:flex;align-items:center;justify-content:center;color:#aaa;font-size:11px;letter-spacing:.04em}
        .wf-note{font-size:11px;color:#aaa;margin-top:10px;font-style:italic}
        .wf-panel{border:1px solid #cfcfcf;border-radius:4px;padding:16px 18px}
        .wf-panel.warn{border-style:dashed;border-color:#9a9a9a;background:#f7f7f5}
        .wf-panel-head{display:flex;align-items:center;gap:9px;font-size:12px;font-weight:700;color:#444;letter-spacing:.05em;margin-bottom:12px}
        .wf-foot{padding:24px 36px 30px;border-top:2px solid #3a3a3a;display:flex;justify-content:space-between;align-items:flex-end;gap:24px;flex-wrap:wrap}
        .wf-foot-k{font-size:9.5px;letter-spacing:.12em;text-transform:uppercase;color:#a2a2a2;margin-bottom:3px}
        .wf-foot-v{font-size:12px;color:#555}
        .wf-conf{font-size:10px;letter-spacing:.2em;text-transform:uppercase;color:#999;border:1px dashed #b9b9b9;padding:7px 13px;border-radius:3px}
        .wf-gallery{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
        .wf-shot{width:150px}
        .wf-shot .wf-ph{height:104px;border-radius:3px}
        .wf-shot-cap{font-size:10px;color:#999;margin-top:4px;line-height:1.4}
        .wf-vr{border:1px solid #dcdcdc;border-radius:4px;padding:16px 18px;margin-bottom:14px}
        .wf-vr-meta{display:grid;grid-template-columns:repeat(4,1fr);gap:12px 18px}
        .wf-mini-k{font-size:9px;letter-spacing:.1em;text-transform:uppercase;color:#a8a8a8;margin-bottom:2px}
        .wf-mini-v{font-size:12px;color:#444}
        .wf-empty{color:#aaa;font-size:12px;font-style:italic;padding:6px 0}
        @media print{body{background:#fff}.wf-page{margin:0;border:none;max-width:none}.wf-topbar{display:none}.wf-sechead{cursor:default}}
    </style>
</head>
<body>
    <div class="wf-page">
        <div class="wf-topbar">
            <a class="wf-back" href="{{ route('admin.reports.index') }}">← Back to Reports</a>
            <div class="wf-actions">
                <a class="wf-btn solid" data-dl href="{{ route('admin.reports.download', ['report' => $report->id, 'format' => 'pdf']) }}">Download PDF</a>
                @if (in_array('csv', $formats, true))
                    <a class="wf-btn" data-dl href="{{ route('admin.reports.download', ['report' => $report->id, 'format' => 'csv']) }}">Download CSV</a>
                @endif
                <button type="button" class="wf-btn" onclick="window.print()">Print Report</button>
            </div>
        </div>

        <header class="wf-head">
            <div class="wf-head-top">
                <div class="wf-logo">
                    <img class="wf-logo-img" src="{{ asset('Images/logo/logo.png') }}" alt="IronLock">
                </div>
                <div class="wf-doctag">{{ $docTag }}</div>
            </div>
            <h1 class="wf-title">{{ $title }}</h1>
            @if ($subtitle)<div class="wf-subtitle">{{ $subtitle }}</div>@endif
            <div class="wf-meta">
                <div class="wf-meta-item"><div class="wf-meta-k">Report Reference ID</div><div class="wf-meta-v wf-mono">{{ $meta['reference_id'] ?? '—' }}</div></div>
                <div class="wf-meta-item"><div class="wf-meta-k">Generated Date &amp; Time</div><div class="wf-meta-v"><time class="rpt-ts" data-ts="{{ $meta['generated_at'] ?? '' }}">{{ $meta['generated_at'] ?? '—' }}</time></div></div>
                <div class="wf-meta-item"><div class="wf-meta-k">Report Status</div><div class="wf-meta-v"><span class="wf-badge strong"><span class="dot">●</span>{{ $statusLabel }}</span></div></div>
                <div class="wf-meta-item"><div class="wf-meta-k">Generated By</div><div class="wf-meta-v">{{ $meta['generated_by'] ?? '—' }}</div></div>
                @if ($shiftRef)<div class="wf-meta-item"><div class="wf-meta-k">Shift Reference</div><div class="wf-meta-v wf-mono">{{ $shiftRef }}</div></div>@endif
                <div class="wf-meta-item"><div class="wf-meta-k">Document Class</div><div class="wf-meta-v">Internal · Confidential</div></div>
            </div>
        </header>

        @yield('report-body')

        <footer class="wf-foot">
            <div style="display:flex;gap:48px;flex-wrap:wrap">
                <div><div class="wf-foot-k">Report Reference</div><div class="wf-foot-v wf-mono">{{ $meta['reference_id'] ?? '—' }}</div></div>
                <div><div class="wf-foot-k">Report Generated</div><div class="wf-foot-v"><time class="rpt-ts" data-ts="{{ $meta['generated_at'] ?? '' }}">{{ $meta['generated_at'] ?? '—' }}</time></div></div>
                <div><div class="wf-foot-k">System</div><div class="wf-foot-v">IronLock Security Monitoring</div></div>
            </div>
            <div style="display:flex;align-items:center;gap:16px">
                <div class="wf-logo"><img class="wf-logo-img sm" src="{{ asset('Images/logo/logo.png') }}" alt="IronLock"></div>
                <div class="wf-conf">Confidential — Internal Security Report</div>
            </div>
        </footer>
    </div>

    @yield('report-extra')

    <script>
        // Localise every <time data-ts="…Z"> to the viewer's own timezone.
        // Storage is UTC; display is local (TIMESTAMP_HANDLING.md §4).
        (function () {
            var MON = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            function p(n){return (n<10?'0':'')+n;}
            function fmt(el, d) {
                var mode = el.className.indexOf('rpt-ts-time') !== -1 ? 'time'
                         : el.className.indexOf('rpt-ts-date') !== -1 ? 'date' : 'full';
                var t = p(d.getHours()) + ':' + p(d.getMinutes()) + (mode === 'time' && el.dataset.sec ? ':' + p(d.getSeconds()) : '');
                var date = d.getDate() + ' ' + MON[d.getMonth()] + ' ' + d.getFullYear();
                if (mode === 'time') return t;
                if (mode === 'date') return date;
                return date + ' · ' + t;
            }
            document.querySelectorAll('.rpt-ts, .rpt-ts-time, .rpt-ts-date').forEach(function (el) {
                var raw = el.dataset.ts;
                if (!raw) { el.textContent = '—'; return; }
                var d = new Date(raw);
                if (isNaN(d)) return;
                el.textContent = fmt(el, d);
                el.title = 'UTC: ' + raw;
            });

            // Tag the download links with the viewer's own timezone so the
            // (server-rendered) PDF/CSV print times in the same local zone the
            // page shows — the stored data stays UTC.
            try {
                var tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
                if (tz) {
                    document.querySelectorAll('a[data-dl]').forEach(function (a) {
                        a.href += (a.href.indexOf('?') > -1 ? '&' : '?') + 'tz=' + encodeURIComponent(tz);
                    });
                }
            } catch (e) { /* no Intl → server falls back to UTC */ }
        })();
    </script>
</body>
</html>
