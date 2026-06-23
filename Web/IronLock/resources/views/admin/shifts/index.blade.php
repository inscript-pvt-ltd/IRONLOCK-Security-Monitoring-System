@extends('admin.layouts.app')

@section('title', 'Shift Scheduling - Admin Dashboard')
@section('page-title', 'Shift Scheduling')

@section('topbar-actions')
    <button id="jump-today-btn" class="topbar-filter" style="background:var(--surface-dark);color:var(--text-secondary);border:1.5px solid var(--border-dark);padding:4px 12px;border-radius:4px;font-size:11px;cursor:pointer;font-weight:bold;">↑ Today</button>
    <button id="new-shift-btn" class="btn-primary-sm" style="background:var(--deep-security-blue);color:white;border:none;padding:6px 12px;border-radius:4px;font-size:11px;font-weight:bold;cursor:pointer;">+ New Shift</button>
@endsection

@section('styles')
<style>
    /* ── Layout ─────────────────────────────────────── */
    .shifts-main {
        position: relative;
        min-height: calc(100vh - var(--header-h) - 32px);
        margin-right: 0;
        transition: margin-right 0.3s ease;
    }

    .shifts-main.drawer-open {
        margin-right: 262px; /* matches drawer width + border */
    }

    /* ── Calendar table (.cal) ───────────────────────── */
    .cal-container {
        background: var(--surface-dark);
        border: 1.5px solid var(--border-dark);
        border-radius: 4px;
        overflow-x: auto;
        overflow-y: hidden;
        margin-bottom: 12px;
        /* Firefox */
        scrollbar-width: thin;
        scrollbar-color: var(--deep-security-blue) var(--bg-dark);
    }

    /* Custom horizontal scrollbar (WebKit/Chromium) */
    .cal-container::-webkit-scrollbar {
        height: 10px;
    }
    .cal-container::-webkit-scrollbar-track {
        background: var(--bg-dark);
        border-radius: 0 0 4px 4px;
    }
    .cal-container::-webkit-scrollbar-thumb {
        background: var(--deep-security-blue);
        border-radius: 6px;
        border: 2px solid var(--bg-dark);
    }
    .cal-container::-webkit-scrollbar-thumb:hover {
        background: var(--premium-gold);
    }

    .cal {
        width: 100%;
        border-collapse: collapse;
        font-size: 11px;
    }

    .cal thead th {
        background: var(--border-dark);
        padding: 8px 6px;
        text-align: center;
        font-size: 10px;
        font-weight: bold;
        color: var(--text-secondary);
        border: 1px solid var(--border-dark);
        white-space: nowrap;
    }

    /* Guard column header — sticky so it pins while scrolling horizontally */
    .cal thead th:first-child {
        position: sticky;
        left: 0;
        z-index: 5;
        background: var(--border-dark);
        text-align: left;
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        padding-left: 10px;
    }

    /* Month tabs above the calendar — one per month in the 3-month range. They
       live OUTSIDE the scrolling table so they're always visible. The tab for
       the month currently in view is highlighted; clicking one scrolls the
       calendar to that month. */
    .cal-month-tabs {
        display: flex;
        gap: 6px;
        margin-bottom: 8px;
    }

    .cal-month-tab {
        flex: 1;
        padding: 7px 4px;
        font-size: 10px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--text-muted);
        background: var(--bg-dark);
        border: 1px solid var(--border-dark);
        border-radius: 5px;
        cursor: pointer;
        transition: background 0.15s, color 0.15s, border-color 0.15s;
    }

    .cal-month-tab:hover {
        color: var(--text-primary);
        border-color: var(--deep-security-blue);
    }

    .cal-month-tab.active {
        color: var(--text-primary);
        background: var(--deep-security-blue);
        border-color: var(--deep-security-blue);
    }

    /* Day columns are sized in JS (sizeDayColumns) so roughly one week fills
       the viewport, like the old weekly view — the rest of the 3-month range
       is reached with the horizontal scrollbar. --day-col-w is set on the
       table; a sensible fallback keeps columns readable before JS runs.
       Single-line "Mon 22" header, matching the original weekly table. */
    .cal-day-th {
        width: var(--day-col-w, 120px);
        min-width: var(--day-col-w, 120px);
        padding: 8px 6px;
        font-size: 10px;
        font-weight: bold;
        white-space: nowrap;
    }

    /* Match the body day cells to the header column width. The sticky guard
       column keeps its own fixed 90px and is excluded here. */
    .cal tbody td:not(.guard-col) {
        width: var(--day-col-w, 120px);
        min-width: var(--day-col-w, 120px);
    }

    /* Today column */
    .col-today {
        background: rgba(255, 193, 7, 0.09) !important;
        border-left: 1px solid rgba(255, 193, 7, 0.5) !important;
        border-right: 1px solid rgba(255, 193, 7, 0.5) !important;
    }
    .cal thead th.col-today { color: var(--premium-gold); }

    /* Weekend columns */
    .col-weekend { background: rgba(0, 0, 0, 0.12) !important; }

    .cal tbody td {
        background: var(--surface-dark);
        border: 1px solid var(--border-dark);
        padding: 5px 4px;
        text-align: center;
        vertical-align: middle;
        min-height: 42px;
        height: 42px;
    }

    .cal tbody td.guard-col {
        position: sticky;
        left: 0;
        z-index: 4;
        background: var(--surface-dark);
        box-shadow: 2px 0 4px rgba(0,0,0,0.25);
        text-align: left;
        font-weight: bold;
        font-size: 10px;
        color: var(--text-primary);
        padding-left: 10px;
        width: 90px;
        min-width: 90px;
        white-space: nowrap;
    }

    .cal tbody td.day-cell {
        cursor: pointer;
        transition: background 0.15s;
    }

    .cal tbody td.day-cell:hover {
        background: var(--border-dark);
    }

    /* Whole occupied cell is clickable to edit, not just the chip. */
    .cal tbody td.shift-cell { cursor: pointer; }

    /* Merged overnight shift cells */
    .cal tbody td.overnight-merged {
        text-align: center;
        background: var(--surface-dark);
        border: 1px solid var(--border-dark);
        position: relative;
    }

    .cal tbody td.overnight-merged::after {
        content: '';
        position: absolute;
        top: 50%;
        left: 25%;
        right: 25%;
        height: 1px;
        background: var(--border-dark);
        z-index: 1;
    }

    /* The merged overnight chip keeps its rounded, padded pill shape but takes
       its colour from the shift's status class (.missed → red, .active → green,
       etc.) — so a missed overnight cell fills red exactly like a single-day
       missed cell. (No background is set here, otherwise it would out-specify
       the status modifiers and force every overnight chip blue.) */
    .cal tbody td.overnight-merged .shift-blk {
        position: relative;
        z-index: 2;
        border-radius: 8px;
        padding: 4px 12px;
        margin: 0 auto;
        display: inline-block;
    }

    .shift-blk {
        display: inline-block;
        background: var(--deep-security-blue);
        color: white;
        font-size: 9px;
        font-weight: bold;
        padding: 3px 7px;
        border-radius: 2px;
        border: 1.5px solid var(--deep-security-blue);
        cursor: pointer;
        white-space: nowrap;
        transition: opacity 0.15s;
    }

    .shift-blk:hover { opacity: 0.85; }

    .shift-blk.active    { background: var(--success-green); border-color: var(--success-green); }
    .shift-blk.checked-in { background: var(--warning-amber); border-color: var(--warning-amber); }
    .shift-blk.completed { background: var(--text-muted); border-color: var(--text-muted); }

    .shift-blk.completed::after {
        content: '✓';
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 13px;
        height: 13px;
        background: var(--success-green);
        color: white;
        border-radius: 50%;
        font-size: 8px;
        font-weight: bold;
        margin-left: 5px;
        vertical-align: middle;
        line-height: 1;
    }
    .shift-blk.cancelled { background: transparent; border-color: var(--text-muted); color: var(--text-muted); border-style: dashed; }
    .shift-blk.missed    { background: var(--error-red); border-color: var(--error-red); }
    .shift-blk.wtr-warn-block { border-style: dashed; }

    /* ── Active Shifts table ─────────────────────────── */
    .active-container {
        background: var(--surface-dark);
        border: 1.5px solid var(--border-dark);
        border-radius: 4px;
        overflow: hidden;
        margin-bottom: 12px;
    }

    .active-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 11px;
    }

    .active-table th {
        background: var(--border-dark);
        padding: 8px 12px;
        text-align: left;
        font-size: 9px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--text-secondary);
        white-space: nowrap;
    }

    .active-table td {
        background: var(--surface-dark);
        padding: 10px 12px;
        border-bottom: 1px solid var(--border-dark);
        color: var(--text-primary);
        vertical-align: middle;
    }

    .active-table tr:last-child td { border-bottom: none; }

    .active-ref {
        font-weight: bold;
        color: var(--premium-gold);
        white-space: nowrap;
    }

    .active-guard { font-weight: bold; }

    .active-timeline { color: var(--text-secondary); white-space: nowrap; }
    .active-timeline .started { color: var(--success-green); }

    .active-empty {
        text-align: center;
        padding: 22px;
        color: var(--text-muted);
        font-size: 11px;
    }

    .active-view-btn {
        display: inline-block;
        padding: 4px 12px;
        font-size: 10px;
        font-weight: bold;
        border-radius: 4px;
        text-decoration: none;
        background: var(--deep-security-blue);
        color: #fff;
        border: 1px solid var(--deep-security-blue);
        transition: all 0.2s;
        white-space: nowrap;
    }

    .active-view-btn:hover {
        background: transparent;
        color: var(--text-primary);
        border-color: var(--premium-gold);
    }

    /* ── Needs-resolution flag (missed / ended-early) ── */
    /* A shift awaiting supervisor resolution turns solid red and carries a
       ⚠ triangle marker, regardless of its underlying status. */
    .shift-blk.needs-resolve {
        background: var(--error-red);
        border-color: var(--error-red);
        color: #fff;
    }
    .shift-blk.needs-resolve .resolve-flag { margin-right: 4px; font-weight: bold; }

    /* ── In-drawer resolve (supervisor recovery) ─────── */
    /* Info banner shown on the edit form when the opened shift needs resolving. */
    .resolve-banner {
        margin-top: 4px;
        /* Match the standard field-to-field spacing so the gap above the
           GUARD label is consistent with the rest of the form (the label
           itself keeps margin-top:0 for the no-banner New Shift case). */
        margin-bottom: 12px;
        padding: 9px 10px;
        border-radius: 4px;
        font-size: 10px;
        line-height: 1.45;
        border: 1px solid var(--error-red);
        background: rgba(220,53,69,0.07);
        color: var(--text-secondary);
    }
    .resolve-banner strong { display: block; color: var(--error-red); margin-bottom: 2px; }

    /* Inline error inside the resolve view. */
    .resolve-view-error {
        display: none;
        margin-top: 10px;
        padding: 8px 10px;
        background: rgba(220,53,69,0.08);
        border: 1px solid var(--critical-red);
        border-radius: 4px;
        font-size: 10px;
        color: var(--critical-red);
        white-space: pre-line;
        line-height: 1.4;
    }
    /* overnight continuation shown in the next day's column */
    .shift-blk.shift-cont {
        background: transparent;
        border: 1.5px dashed var(--deep-security-blue);
        color: var(--text-secondary);
        font-size: 8px;
    }
    .shift-blk.shift-cont:hover { opacity: 0.8; }

    /* ── WTR warning rows below calendar ─────────────── */
    .wtr-warn {
        padding: 7px 12px;
        border: 1px solid var(--warning-amber);
        border-radius: 3px;
        font-size: 10px;
        color: var(--text-secondary);
        margin-bottom: 5px;
        background: rgba(255,193,7,0.04);
    }

    .wtr-warn.error {
        border-color: var(--critical-red);
        background: rgba(220,53,69,0.04);
        color: var(--text-secondary);
    }

    .wtr-warn.dashed { border-style: dashed; }

    /* ── Weekly hours section ─────────────────────────── */
    .section-header {
        font-size: 10px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--text-muted);
        margin: 16px 0 6px;
    }

    .wtr-hours-container {
        background: var(--surface-dark);
        border: 1.5px solid var(--border-dark);
        border-radius: 4px;
        overflow: hidden;
        margin-bottom: 10px;
    }

    .wtr-hours-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 10px;
    }

    .wtr-hours-table th,
    .wtr-hours-table td {
        border: 1px solid var(--border-dark);
        padding: 6px 10px;
    }

    .wtr-hours-table th {
        background: var(--border-dark);
        color: var(--text-secondary);
        font-weight: bold;
        text-align: left;
    }

    .wtr-hours-table td {
        background: var(--surface-dark);
        color: var(--text-primary);
    }

    .wtr-status-chip {
        display: inline-block;
        padding: 2px 7px;
        border-radius: 10px;
        font-size: 9px;
        font-weight: bold;
        border: 1px solid;
    }

    .wtr-status-chip.ok   { color: var(--success-green); border-color: var(--success-green); }
    .wtr-status-chip.warn { color: var(--warning-amber); border-color: var(--warning-amber); }
    .wtr-status-chip.over { color: var(--critical-red);  border-color: var(--critical-red); }

    /* ── Right Drawer ─────────────────────────────────── */
    .shift-drawer {
        width: 260px;
        background: var(--surface-dark);
        border-left: 1.5px solid var(--border-dark);
        padding: 16px;
        position: fixed;
        right: 0;
        top: var(--header-h);
        bottom: 0;
        transform: translateX(100%);
        transition: transform 0.3s ease;
        z-index: 1000;
        overflow-y: auto;
    }

    .shift-drawer.open { transform: translateX(0); }

    /* Custom scrollbar — same blue/gold treatment as the schedule table
       (.cal-container), but vertical for the drawer. */
    .shift-drawer {
        scrollbar-width: thin;
        scrollbar-color: var(--deep-security-blue) var(--bg-dark);
    }
    .shift-drawer::-webkit-scrollbar { width: 10px; }
    .shift-drawer::-webkit-scrollbar-track {
        background: var(--bg-dark);
        border-radius: 4px;
    }
    .shift-drawer::-webkit-scrollbar-thumb {
        background: var(--deep-security-blue);
        border-radius: 6px;
        border: 2px solid var(--bg-dark);
    }
    .shift-drawer::-webkit-scrollbar-thumb:hover {
        background: var(--premium-gold);
    }

    .drawer-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 14px;
        padding-bottom: 10px;
        border-bottom: 1px solid var(--border-dark);
    }

    .drawer-title-text {
        font-size: 12px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--premium-gold);
    }

    .drawer-close {
        background: transparent;
        border: none;
        color: var(--text-muted);
        font-size: 18px;
        cursor: pointer;
        line-height: 1;
        padding: 0;
    }

    .drawer-close:hover { color: var(--text-primary); }

    .drawer-label {
        display: block;
        font-size: 10px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--text-secondary);
        margin-bottom: 4px;
        margin-top: 12px;
    }

    .drawer-input,
    .drawer-select {
        width: 100%;
        padding: 7px 8px;
        background: var(--bg-dark);
        border: 1.5px solid var(--border-dark);
        border-radius: 4px;
        color: var(--text-primary);
        font-size: 11px;
        box-sizing: border-box;
    }

    .drawer-input:focus,
    .drawer-select:focus {
        outline: none;
        border-color: var(--premium-gold);
    }

    .drawer-select { cursor: pointer; }

    .time-row {
        display: flex;
        gap: 8px;
    }

    .time-row > div { flex: 1; }

    /* ── WTR inline check inside drawer ──────────────── */
    .wtr-inline {
        padding: 8px 10px;
        border-radius: 4px;
        font-size: 10px;
        border: 1px solid var(--border-dark);
        margin-top: 10px;
        background: var(--bg-dark);
        line-height: 1.4;
    }

    .wtr-inline.ok      { border-color: var(--success-green); color: var(--success-green); }
    .wtr-inline.warning { border-color: var(--warning-amber); color: var(--warning-amber); }
    .wtr-inline.error   { border-color: var(--critical-red);  color: var(--critical-red); }

    #wtr-violations-list div {
        margin-top: 4px;
        font-size: 10px;
        color: var(--text-secondary);
    }

    /* ── Override section inside drawer ──────────────── */
    .drawer-override {
        margin-top: 10px;
        border: 1px solid var(--warning-amber);
        border-radius: 4px;
        background: rgba(255,193,7,0.03);
        overflow: hidden;
    }

    .drawer-override-header {
        padding: 7px 10px;
        font-size: 10px;
        font-weight: bold;
        color: var(--warning-amber);
        background: rgba(255,193,7,0.07);
        border-bottom: 1px solid var(--border-dark);
    }

    .drawer-override-body { padding: 10px; }

    .drawer-textarea {
        width: 100%;
        padding: 7px 8px;
        background: var(--bg-dark);
        border: 1.5px solid var(--border-dark);
        border-radius: 4px;
        color: var(--text-primary);
        font-size: 10px;
        resize: vertical;
        min-height: 56px;
        box-sizing: border-box;
        line-height: 1.4;
    }

    .drawer-textarea:focus {
        outline: none;
        border-color: var(--premium-gold);
    }

    .override-cb-row {
        display: flex;
        gap: 8px;
        align-items: flex-start;
        padding: 6px 0;
        border-bottom: 1px solid var(--border-dark);
        font-size: 10px;
    }

    .override-cb-row:last-of-type { border-bottom: none; }
    .override-cb-row input[type="checkbox"] { margin-top: 2px; flex-shrink: 0; }

    .override-cb-label { color: var(--text-secondary); line-height: 1.3; }
    .override-cb-label strong { display: block; color: var(--text-primary); font-size: 10px; }

    /* ── Inline error inside drawer ──────────────────── */
    #shift-drawer-error {
        display: none;
        margin-top: 10px;
        padding: 8px 10px;
        background: rgba(220,53,69,0.08);
        border: 1px solid var(--critical-red);
        border-radius: 4px;
        font-size: 10px;
        color: var(--critical-red);
        white-space: pre-line;
        line-height: 1.4;
    }

    /* ── Drawer action buttons ───────────────────────── */
    .drawer-actions {
        display: flex;
        gap: 8px;
        margin-top: 14px;
        padding-top: 12px;
        border-top: 1px solid var(--border-dark);
    }

    .drawer-btn {
        flex: 1;
        padding: 8px;
        font-size: 11px;
        font-weight: bold;
        border-radius: 4px;
        cursor: pointer;
        text-align: center;
        border: 1px solid;
        transition: all 0.2s;
    }

    .drawer-btn-primary {
        background: var(--deep-security-blue);
        color: white;
        border-color: var(--deep-security-blue);
    }

    .drawer-btn-primary:hover:not(:disabled) {
        background: transparent;
        color: var(--deep-security-blue);
    }

    .drawer-btn-primary:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .drawer-btn-secondary {
        background: transparent;
        color: var(--text-secondary);
        border-color: var(--border-dark);
    }

    .drawer-btn-secondary:hover {
        border-color: var(--premium-gold);
        color: var(--premium-gold);
    }

    .drawer-btn-danger {
        background: transparent;
        color: var(--error-red);
        border-color: var(--error-red);
    }

    .drawer-btn-danger:hover {
        background: var(--error-red);
        color: #fff;
    }

    /* ── Spinner ─────────────────────────────────────── */
    .loading-spinner {
        display: inline-block;
        width: 11px;
        height: 11px;
        border: 2px solid rgba(255,255,255,0.3);
        border-top-color: white;
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
        vertical-align: middle;
        margin-right: 3px;
    }

    @keyframes spin { to { transform: rotate(360deg); } }

    /* ── Override log ────────────────────────────────── */
    .override-log {
        border-style: dashed;
        font-size: 10px;
        color: var(--text-muted);
        line-height: 1.6;
    }

    .override-log a { color: var(--premium-gold); text-decoration: none; }
    .override-log a:hover { text-decoration: underline; }

    .override-entry {
        padding: 6px 0;
        border-bottom: 1px solid var(--border-dark);
        font-size: 10px;
        line-height: 1.5;
    }

    .override-entry:last-child { border-bottom: none; }
</style>
@endsection

@section('content')
<div class="shifts-main">

    <!-- ① Calendar table (thead + tbody populated by JS) -->
    <!-- Month tabs (populated by JS) — always-visible, click to jump to a month -->
    <div class="cal-month-tabs" id="cal-month-tabs"></div>
    <div class="cal-container">
        <table class="cal" id="calendar-table"></table>
    </div>

    <!-- ①b Active Shifts — only shifts currently in progress (populated by JS) -->
    <div class="section-header">Active Shifts — currently in progress</div>
    <div class="active-container">
        <table class="active-table" id="active-shifts-table">
            <thead>
                <tr>
                    <th>Shift ID</th>
                    <th>Site</th>
                    <th>Guard</th>
                    <th>Timeline</th>
                    <th style="text-align:right;">Action</th>
                </tr>
            </thead>
            <tbody id="active-shifts-tbody">
                <tr><td colspan="5" class="active-empty">Loading…</td></tr>
            </tbody>
        </table>
    </div>

    <!-- ② WTR warning rows below calendar (populated by JS) -->
    <div id="wtr-warnings-section"></div>

    <!-- ③ Weekly Hours Tracking -->
    <div class="section-header">Weekly Hours Tracking — WTR 1998 · 48h avg over 17-week reference period (REP-005)</div>
    <div class="wtr-hours-container">
        <table class="wtr-hours-table" id="weekly-hours-table">
            <thead>
                <tr>
                    <th>Guard</th>
                    <th>This week (scheduled)</th>
                    <th>17-week avg</th>
                    <th>48h status</th>
                </tr>
            </thead>
            <tbody id="weekly-hours-tbody"></tbody>
        </table>
    </div>

    <!-- ④ Override log -->
    <div class="wtr-warn override-log" id="override-log-section">
        <strong>Override log (ADM-006 / ADM-007):</strong> Any WTR limit manually bypassed is recorded here with admin identity + timestamp.<br>
        <span id="override-log-summary">[ No overrides this week ]</span><span id="override-log-toggle" style="display:none;">&nbsp;·&nbsp; <a href="#" id="override-history-btn">View override history ↓</a></span>
        <div id="override-history-entries" style="display:none; margin-top:8px; border-top:1px solid var(--border-dark); padding-top:8px;"></div>
    </div>

</div>

<!-- ── Right Drawer ────────────────────────────────── -->
<div id="shift-drawer" class="shift-drawer">
    <div class="drawer-header">
        <span class="drawer-title-text" id="drawer-title-text">New Shift</span>
        <button class="drawer-close" onclick="closeShiftDrawer()" title="Close">&times;</button>
    </div>

    <form id="shift-form">
        <!-- Shown only when the opened shift needs supervisor resolution -->
        <div id="resolve-banner" class="resolve-banner" style="display:none;"></div>

        <!-- Shown when editing a shift whose start time is already in the past -->
        <div id="past-shift-notice"
             style="display:none; margin-bottom:12px; padding:8px 10px; border-radius:6px;
                    font-size:11px; line-height:1.4; color:#92400e;
                    background:#fef3c7; border:1px solid #fcd34d;">
            This shift's start time has already passed. To save changes, move the
            <strong>Date / Start</strong> to a future time first.
        </div>

        <label class="drawer-label" style="margin-top:0;">Guard</label>
        <select id="guard-select" class="drawer-select" required>
            <option value="">Select Guard</option>
        </select>

        <label class="drawer-label">Site</label>
        <select id="site-select" class="drawer-select" required>
            <option value="">Select Site</option>
        </select>

        <label class="drawer-label">Date</label>
        <input type="date" id="shift-date" class="drawer-input" required>

        <div class="time-row">
            <div>
                <label class="drawer-label">Start</label>
                <input type="time" id="shift-start" class="drawer-input" required>
            </div>
            <div>
                <label class="drawer-label">Duration (hrs)</label>
                <input type="number" id="shift-duration" class="drawer-input"
                       min="0.5" max="16" step="0.5" placeholder="8" required>
            </div>
        </div>
        <div id="computed-end-time" style="display:none; font-size:10px; color:var(--text-secondary); margin-top:4px; padding:4px 0;">
            Ends: <strong id="computed-end-display" style="color:var(--text-primary);"></strong>
        </div>

        <!-- WTR live check -->
        <div id="wtr-check" class="wtr-inline" style="display:none;">
            <span id="wtr-check-text"></span>
            <div id="wtr-violations-list"></div>
        </div>

        <!-- Override section (warnings only, not blockers) -->
        <div id="override-section" class="drawer-override" style="display:none;">
            <div class="drawer-override-header">⚠ WTR Override — Justification Required</div>
            <div class="drawer-override-body">
                <label class="drawer-label" style="margin-top:0;">Justification *</label>
                <textarea id="override-justification" class="drawer-textarea" rows="3"
                    placeholder="Document reason for this WTR override (operational necessity, emergency cover, etc.)…"></textarea>

                <div class="override-cb-row" id="override-row-12hr" style="display:none;">
                    <input type="checkbox" id="override-12hr-warning">
                    <div class="override-cb-label">
                        <strong>Override 12h Duration Warning</strong>
                        Acknowledge shift exceeds recommended 12h limit
                    </div>
                </div>
                <div class="override-cb-row" id="override-row-11hr" style="display:none;">
                    <input type="checkbox" id="override-11hr-rest">
                    <div class="override-cb-label">
                        <strong>Override 11h Rest Warning</strong>
                        Acknowledge insufficient rest between shifts
                    </div>
                </div>
            </div>
        </div>

        <!-- Inline error -->
        <div id="shift-drawer-error"></div>

        <!-- Actions -->
        <div class="drawer-actions">
            <button type="submit" class="drawer-btn drawer-btn-primary" id="save-shift-btn">Save</button>
            <!-- Replaces Save when the shift needs resolution -->
            <button type="button" class="drawer-btn drawer-btn-primary" id="resolve-open-btn" style="display:none;">Resolve</button>
            <button type="button" class="drawer-btn drawer-btn-secondary" onclick="closeShiftDrawer()">Close</button>
        </div>
        <!-- Cancel-shift action — only for a shift that hasn't started yet -->
        <div class="drawer-actions" id="cancel-shift-row" style="display:none; margin-top:8px;">
            <button type="button" class="drawer-btn drawer-btn-danger" id="cancel-shift-btn">Cancel Shift</button>
        </div>
        <!-- View full timeline — shown for a completed shift -->
        <div class="drawer-actions" id="view-timeline-row" style="display:none; margin-top:8px;">
            <a class="drawer-btn drawer-btn-primary" id="view-timeline-btn" href="#"
               style="text-decoration:none; line-height:1.4;">View Timeline →</a>
        </div>
    </form>

    <!-- Resolve view — swaps in over the edit form (Back returns to it) -->
    <div id="resolve-view" style="display:none;">
        <div id="resolve-view-info" class="resolve-banner"></div>

        <label class="drawer-label">Outcome</label>
        <select id="resolve-outcome" class="drawer-select"></select>

        <div id="resolve-guard-row" style="display:none;">
            <label class="drawer-label">Reassign to</label>
            <select id="resolve-guard" class="drawer-select">
                <option value="">Select guard</option>
            </select>
        </div>

        <label class="drawer-label">Reason</label>
        <select id="resolve-reason" class="drawer-select">
            <option value="emergency">Emergency (medical/family)</option>
            <option value="transport">Transport / Traffic</option>
            <option value="illness">Illness</option>
            <option value="personal">Personal</option>
            <option value="operational">Operational</option>
            <option value="other">Other</option>
        </select>

        <label class="drawer-label">Note (optional)</label>
        <textarea id="resolve-note" class="drawer-textarea" maxlength="500"
                  placeholder="Add any context for the audit trail…"></textarea>

        <div id="resolve-view-error" class="resolve-view-error"></div>

        <div class="drawer-actions">
            <button type="button" class="drawer-btn drawer-btn-primary" id="resolve-confirm-btn">Resolve</button>
            <button type="button" class="drawer-btn drawer-btn-secondary" id="resolve-back-btn">← Back</button>
        </div>
    </div>

    <!-- Cancel view — swaps in over the edit form (Back returns to it) -->
    <div id="cancel-view" style="display:none;">
        <div class="resolve-banner">
            <strong>Cancel this shift?</strong>This calls the shift off before the guard goes on duty
            and <strong>permanently removes it from the system</strong> — this can't be undone.
            To schedule the correct shift afterwards, add a new one.
        </div>

        <label class="drawer-label">Reason</label>
        <select id="cancel-reason" class="drawer-select">
            <option value="scheduling_error">Scheduling error (wrong day/guard/site)</option>
            <option value="emergency">Emergency / operational</option>
            <option value="client_request">Client cancelled / site closed</option>
            <option value="other">Other</option>
        </select>

        <label class="drawer-label">Note (optional)</label>
        <textarea id="cancel-note" class="drawer-textarea" maxlength="500"
                  placeholder="Add any context for your own reference…"></textarea>

        <div id="cancel-view-error" class="resolve-view-error"></div>

        <div class="drawer-actions">
            <button type="button" class="drawer-btn drawer-btn-danger" id="cancel-confirm-btn">Cancel Shift</button>
            <button type="button" class="drawer-btn drawer-btn-secondary" id="cancel-back-btn">← Back</button>
        </div>
    </div>
</div>
@endsection

@section('scripts')
{{-- shifts.js self-initialises on load (see bottom of the file). Do NOT call
     ShiftCalendar.init() again here — a second init binds a duplicate form
     submit handler, which fires saveShift() twice (one PUT + one stray POST
     that then "conflicts" with the shift just saved). --}}
<script src="{{ asset('js/admin/shifts.js') }}?v={{ filemtime(public_path('js/admin/shifts.js')) }}"></script>
@endsection
