{{--
    Shared PDF layout for all Phase 8 reports (rendered by dompdf).

    dompdf has limited CSS support, so this deliberately uses table/block
    layout and inline-ish styles rather than flexbox/grid. The header carries
    the IronLock brand + report title + generation provenance; the fixed footer
    carries the regulatory note (REP-003) and a live page number.

    Expected vars:
      $title  string  report title
      $meta   array   ['generated_at' => Carbon, 'generated_by' => string]
--}}
@php
    $generatedAt = $meta['generated_at'] ?? null;
    $generatedBy = $meta['generated_by'] ?? 'IronLock Admin';
    $tz = $report_tz ?? 'UTC';
    $tzLabel = $report_tz_label ?? 'UTC';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 130px 40px 80px 40px; }

        * { box-sizing: border-box; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #1A2332;
            margin: 0;
        }

        /* Fixed header — repeats on every page. */
        .doc-header {
            position: fixed;
            top: -100px; left: 0; right: 0;
            height: 90px;
            border-bottom: 2px solid #0A1931;
            padding-bottom: 8px;
        }
        .brand { font-size: 20px; font-weight: bold; color: #0A1931; }
        .brand .lock { color: #C8A94B; }
        .doc-title { font-size: 15px; font-weight: bold; margin-top: 6px; color: #0A1931; }
        .doc-meta { margin-top: 4px; font-size: 9px; color: #5A6472; }

        /* Fixed footer — repeats on every page, with the regulatory note. */
        .doc-footer {
            position: fixed;
            bottom: -60px; left: 0; right: 0;
            height: 50px;
            border-top: 1px solid #C7CDD6;
            padding-top: 6px;
            font-size: 8px;
            color: #7A828E;
        }
        .doc-footer .page-num:after { content: counter(page); }

        h2.section {
            font-size: 12px;
            color: #0A1931;
            border-bottom: 1px solid #C7CDD6;
            padding-bottom: 3px;
            margin: 18px 0 8px 0;
        }

        table.kv { width: 100%; border-collapse: collapse; }
        table.kv td { padding: 3px 6px; vertical-align: top; }
        table.kv td.k { color: #5A6472; width: 32%; }
        table.kv td.v { font-weight: bold; }

        table.grid { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.grid th {
            background: #0A1931; color: #fff; text-align: left;
            padding: 5px 6px; font-size: 9px; text-transform: uppercase;
        }
        table.grid td { padding: 5px 6px; border-bottom: 1px solid #E2E6EC; font-size: 10px; }
        table.grid tr:nth-child(even) td { background: #F6F8FA; }

        .pill { padding: 1px 6px; border-radius: 8px; font-size: 8px; font-weight: bold; }
        .pill-ok { background: #E3F5EA; color: #1F7A3D; }
        .pill-warn { background: #FCEFD6; color: #9A6B00; }
        .pill-bad { background: #FBE3E3; color: #B02020; }
        .pill-muted { background: #ECEFF3; color: #5A6472; }

        .muted { color: #7A828E; }
        .totals { margin-top: 10px; font-size: 10px; }
        .empty { color: #7A828E; font-style: italic; padding: 8px 0; }
        .missed-note { color: #8a5a00; background: #fdf6e6; border-left: 3px solid #b7791f; padding: 6px 9px; margin: 8px 0 6px; font-size: 9px; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="doc-header">
        <div class="brand">Iron<span class="lock">Lock</span></div>
        <div class="doc-title">{{ $title }}</div>
        <div class="doc-meta">
            Generated {{ $generatedAt ? $generatedAt->copy()->setTimezone($tz)->format('d M Y H:i') . ' ' . $tzLabel : '—' }}
            &nbsp;·&nbsp; by {{ $generatedBy }}
        </div>
    </div>

    <div class="doc-footer">
        IronLock Security Monitoring — Confidential. Retained as compliance evidence
        (Working Time Regulations 1998 · BS 8484). &nbsp; Page <span class="page-num"></span>
    </div>

    <main>
        @yield('report-body')
    </main>
</body>
</html>
