@extends('admin.layouts.app')

@section('title', 'Shift Scheduling - Admin Dashboard')
@section('page-title', 'Shift Scheduling')

@section('topbar-actions')
    <select id="week-selector" class="topbar-filter">
        <option value="current">Week: {{ date('d') }}–{{ date('d', strtotime('+6 days')) }} {{ date('M') }}</option>
        <option value="next">Next week</option>
        <option value="prev">Previous week</option>
    </select>
    <button id="new-shift-btn" class="btn-primary-sm" style="background:var(--deep-security-blue);color:white;border:none;padding:6px 12px;border-radius:4px;font-size:11px;font-weight:bold;cursor:pointer;">+ New Shift</button>
@endsection

@section('styles')
<style>
    /* ── Layout ─────────────────────────────────────── */
    .shifts-main {
        position: relative;
        min-height: calc(100vh - 64px);
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
        overflow: hidden;
        margin-bottom: 12px;
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

    .cal thead th:first-child {
        text-align: left;
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        padding-left: 10px;
    }

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

    .cal tbody td.overnight-merged .shift-blk {
        position: relative;
        z-index: 2;
        background: var(--deep-security-blue);
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
    .shift-blk.completed { background: var(--text-muted); border-color: var(--text-muted); }
    .shift-blk.cancelled { background: transparent; border-color: var(--text-muted); color: var(--text-muted); border-style: dashed; }
    .shift-blk.wtr-warn-block { border-style: dashed; }
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
        top: 48px;
        bottom: 0;
        transform: translateX(100%);
        transition: transform 0.3s ease;
        z-index: 1000;
        overflow-y: auto;
    }

    .shift-drawer.open { transform: translateX(0); }

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
    <div class="cal-container">
        <table class="cal" id="calendar-table"></table>
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

                <div class="override-cb-row">
                    <input type="checkbox" id="override-12hr-warning">
                    <div class="override-cb-label">
                        <strong>Override 12h Duration Warning</strong>
                        Acknowledge shift exceeds recommended 12h limit
                    </div>
                </div>
                <div class="override-cb-row">
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
            <button type="button" class="drawer-btn drawer-btn-secondary" onclick="closeShiftDrawer()">Cancel</button>
        </div>
    </form>
</div>
@endsection

@section('scripts')
<script src="{{ asset('js/admin/shifts.js') }}"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        ShiftCalendar.init();
    });
</script>
@endsection
