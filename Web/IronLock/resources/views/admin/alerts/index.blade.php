@extends('admin.layouts.app')

@section('title', 'Alert Feed - IronLock')
@section('page-title', 'Alert Feed')

@section('styles')
<style>
    /* ── Filter bar (ALT-006) ─────────────────────────────────────────────── */
    .filter-bar {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 14px;
    }

    .filter-select {
        background: var(--bg-dark);
        border: 1.5px solid var(--border-dark);
        color: var(--text-secondary);
        font-size: 11px;
        font-family: inherit;
        padding: 6px 10px;
        border-radius: 4px;
        cursor: pointer;
        min-width: 130px;
    }
    .filter-select:focus { outline: none; border-color: var(--premium-gold); }

    /* ── Severity badges — colour + icon + text, never colour alone ────────── */
    .sev-badge {
        padding: 2px 8px;
        font-size: 10px;
        font-weight: bold;
        border-radius: 4px;
        text-transform: uppercase;
        white-space: nowrap;
    }
    .sev-badge.critical { background: var(--critical-red); color: #fff; }
    .sev-badge.warning  { background: var(--warning-amber); color: #1a1206; }

    /* ── Offline chip — marks alerts raised from an offline flush ──────────── */
    .offline-chip {
        display: inline-block;
        padding: 1px 6px;
        margin-left: 4px;
        font-size: 10px;
        font-weight: bold;
        border-radius: 4px;
        white-space: nowrap;
        background: rgba(120, 120, 160, 0.18);
        color: var(--text-muted);
        border: 1px solid rgba(120, 120, 160, 0.35);
    }

    /* ── Status pills ─────────────────────────────────────────────────────── */
    .status-pill {
        padding: 2px 9px;
        font-size: 10px;
        font-weight: bold;
        border-radius: 10px;
        white-space: nowrap;
    }
    .status-pill.open {
        background: rgba(107, 114, 128, 0.18);
        color: var(--text-secondary);
        border: 1px solid var(--border-dark);
    }
    .status-pill.acknowledged {
        background: rgba(34, 197, 94, 0.15);
        color: var(--success-green);
        border: 1px solid var(--success-green);
    }

    /* Critical + still-open rows pulse their left edge until acknowledged. */
    @keyframes alert-pulse {
        0%, 100% { box-shadow: inset 3px 0 0 var(--critical-red); }
        50%      { box-shadow: inset 3px 0 0 #ff6b6b; }
    }
    tr.row-critical-open td:first-child { animation: alert-pulse 2s infinite; }

    .alert-feed-table tbody tr { cursor: pointer; }
    .alert-feed-table tbody tr:hover { background: var(--border-dark); }
    .feed-actions { display: flex; gap: 6px; }

    .feed-empty {
        text-align: center;
        padding: 28px;
        color: var(--text-muted);
        font-size: 12px;
    }

    /* ── Pagination ───────────────────────────────────────────────────────── */
    .feed-pagination {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-top: 10px;
        font-size: 11px;
        color: var(--text-muted);
    }
    .feed-pagination .btn-sm[disabled] { opacity: 0.4; cursor: default; }

    /* ── Alert Detail drawer (ALT-007) — mirrors the Guards drawer ─────────── */
    .alert-drawer {
        width: 320px;
        background: var(--surface-dark);
        border-left: 1.5px solid var(--border-dark);
        padding: 20px;
        position: fixed;
        right: 0;
        top: var(--header-h);
        bottom: 0;
        transform: translateX(100%);
        transition: transform 0.3s ease;
        z-index: 1000;
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: var(--deep-security-blue) var(--bg-dark);
    }
    .alert-drawer.open { transform: translateX(0); }
    .alert-drawer::-webkit-scrollbar { width: 10px; }
    .alert-drawer::-webkit-scrollbar-track { background: var(--bg-dark); border-radius: 4px; }
    .alert-drawer::-webkit-scrollbar-thumb {
        background: var(--deep-security-blue); border-radius: 6px; border: 2px solid var(--bg-dark);
    }
    .alert-drawer::-webkit-scrollbar-thumb:hover { background: var(--premium-gold); }

    .drawer-overlay {
        position: fixed; inset: 0;
        background: rgba(0, 0, 0, 0.5);
        display: none; z-index: 999;
    }
    .drawer-overlay.show { display: block; }

    .drawer-title {
        font-size: 13px; font-weight: bold;
        margin-bottom: 16px; padding-bottom: 8px;
        border-bottom: 1.5px solid var(--border-dark);
        color: var(--text-primary);
        display: flex; align-items: center; justify-content: space-between; gap: 8px;
    }
    .drawer-close {
        background: none; border: none; color: var(--text-muted);
        font-size: 18px; cursor: pointer; line-height: 1;
    }
    .drawer-close:hover { color: var(--premium-gold); }

    .detail-field { margin-bottom: 12px; }
    .detail-label {
        display: block; font-size: 10px; font-weight: bold;
        text-transform: uppercase; letter-spacing: 0.06em;
        color: var(--text-muted); margin-bottom: 4px;
    }
    .detail-value { font-size: 12px; color: var(--text-primary); line-height: 1.45; }
    .detail-value.desc { font-size: 11px; color: var(--text-secondary); }

    .drawer-divider { border: none; border-top: 1px solid var(--border-dark); margin: 16px 0; }

    .ack-textarea {
        width: 100%; height: 70px; resize: vertical;
        background: var(--bg-dark); border: 1.5px solid var(--border-dark);
        border-radius: 4px; color: var(--text-primary);
        font-size: 11px; font-family: inherit; padding: 8px 10px;
    }
    .ack-textarea:focus { outline: none; border-color: var(--premium-gold); }
    .ack-textarea.error { border-color: var(--error-red); background: rgba(239, 68, 68, 0.08); }
    .ack-error { color: var(--error-red); font-size: 10px; margin-top: 4px; display: none; }
    .ack-error.show { display: block; }

    .drawer-btn {
        width: 100%; padding: 9px 12px; font-size: 11px; font-weight: bold;
        border-radius: 4px; cursor: pointer; border: none; margin-top: 8px;
        transition: all 0.2s ease;
    }
    .drawer-btn.primary { background: var(--deep-security-blue); color: #fff; }
    .drawer-btn.primary:hover { background: var(--premium-gold); color: var(--bg-dark); }
    .drawer-btn.outline {
        background: transparent; color: var(--text-secondary); border: 1px solid var(--border-dark);
        text-align: center; text-decoration: none; display: block;
    }
    .drawer-btn.outline:hover { border-color: var(--premium-gold); color: var(--premium-gold); }

    .ack-note-box {
        background: var(--bg-dark); border: 1px solid var(--border-dark);
        border-radius: 4px; padding: 9px 10px; font-size: 11px; color: var(--text-secondary);
    }

    /* ── Bulk acknowledge bar (appears when ≥1 alert is ticked) ─────────────── */
    .alert-checkbox, #select-all-alerts { cursor: pointer; accent-color: var(--premium-gold); }

    .bulk-ack-bar {
        display: none;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 12px;
        padding: 10px 14px;
        background: rgba(212, 175, 55, 0.08);
        border: 1.5px solid var(--premium-gold);
        border-radius: 6px;
    }
    .bulk-ack-count {
        font-size: 11px; font-weight: bold; color: var(--premium-gold);
        white-space: nowrap;
    }
    .bulk-ack-input {
        flex: 1; min-width: 200px;
        background: var(--bg-dark); border: 1.5px solid var(--border-dark);
        border-radius: 4px; color: var(--text-primary);
        font-size: 11px; font-family: inherit; padding: 7px 10px;
    }
    .bulk-ack-input:focus { outline: none; border-color: var(--premium-gold); }
    .bulk-ack-input.error { border-color: var(--error-red); background: rgba(239, 68, 68, 0.08); }
    .bulk-ack-error {
        flex-basis: 100%; color: var(--error-red); font-size: 10px; display: none;
    }
    .bulk-ack-error.show { display: block; }
</style>
@endsection

@section('content')

<!-- Filter bar (ALT-006) -->
<div class="filter-bar">
    <select class="filter-select" id="f-severity" onchange="onFilterChange()">
        <option value="">Severity: All</option>
        <option value="CRITICAL">● Critical</option>
        <option value="WARNING">▲ Warning</option>
    </select>

    <select class="filter-select" id="f-type" onchange="onFilterChange()">
        <option value="">Type: All</option>
        @foreach($filterTypes as $type)
            <option value="{{ $type }}">{{ $type }}</option>
        @endforeach
    </select>

    <select class="filter-select" id="f-site" onchange="onFilterChange()">
        <option value="">Site: All</option>
        @foreach($filterSites as $site)
            <option value="{{ $site->id }}">{{ $site->name }}</option>
        @endforeach
    </select>

    <select class="filter-select" id="f-guard" onchange="onFilterChange()">
        <option value="">Guard: All</option>
        @foreach($filterGuards as $guard)
            <option value="{{ $guard['id'] }}">{{ $guard['name'] }}</option>
        @endforeach
    </select>

    <select class="filter-select" id="f-status" onchange="onFilterChange()">
        <option value="OPEN">Status: Open</option>
        <option value="ACKNOWLEDGED">Status: Acknowledged</option>
        <option value="">Status: All</option>
    </select>

    <select class="filter-select" id="f-offline" onchange="onFilterChange()">
        <option value="">Source: All</option>
        <option value="offline">⚑ Offline flush</option>
        <option value="online">Live</option>
    </select>
</div>

<!-- Bulk acknowledge bar (ALT-008) — shown when ≥1 open alert is selected -->
<div class="bulk-ack-bar" id="bulk-ack-bar">
    <span class="bulk-ack-count" id="bulk-ack-count">0 selected</span>
    <input type="text" class="bulk-ack-input" id="bulk-ack-note" maxlength="1000"
           placeholder="Outcome note — applied to every selected alert (required)…">
    <button class="btn-sm btn-primary-sm" id="bulk-ack-btn" onclick="submitBulkAck()">Acknowledge Selected</button>
    <div class="bulk-ack-error" id="bulk-ack-error">A note is required to acknowledge.</div>
</div>

<!-- Alert table -->
<div class="table-container">
    <table class="table alert-feed-table">
        <thead>
            <tr>
                <th style="width: 28px;"><input type="checkbox" id="select-all-alerts" onclick="toggleSelectAll(this)" title="Select all open alerts"></th>
                <th>Severity</th>
                <th>Alert Type</th>
                <th>Guard</th>
                <th>Site</th>
                <th>Age</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="alerts-tbody">
            <tr><td colspan="8" class="feed-empty">Loading alerts…</td></tr>
        </tbody>
    </table>
</div>

<div class="feed-pagination">
    <button class="btn-sm btn-secondary-sm" id="page-prev" onclick="changePage(-1)">← Prev</button>
    <span id="page-label">Page 1 of 1</span>
    <button class="btn-sm btn-secondary-sm" id="page-next" onclick="changePage(1)">Next →</button>
</div>

<!-- Alert Detail drawer (ALT-007) -->
<div class="drawer-overlay" id="alert-overlay" onclick="closeAlertDrawer()"></div>
<div class="alert-drawer" id="alert-drawer">
    <div class="drawer-title">
        <span>Alert Detail</span>
        <button class="drawer-close" onclick="closeAlertDrawer()" aria-label="Close">×</button>
    </div>
    <div id="drawer-body">
        <!-- populated by JS -->
    </div>
</div>

@endsection

@section('scripts')
<script>
    const listUrl = '{{ route('admin.alerts.list') }}';
    const showUrlBase = '{{ url('admin/alerts') }}';
    const bulkAckUrl = '{{ route('admin.alerts.bulkAcknowledge') }}';
    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    let currentPage = 1;
    let lastPage = 1;
    let openAlertId = null;  // alert currently shown in the drawer
    let selectedIds = new Set();  // ticked open-alert ids (for bulk acknowledge)

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function currentFilters() {
        return {
            severity: document.getElementById('f-severity').value,
            type: document.getElementById('f-type').value,
            site_id: document.getElementById('f-site').value,
            guard_id: document.getElementById('f-guard').value,
            status: document.getElementById('f-status').value,
            offline: document.getElementById('f-offline').value,
        };
    }

    function offlineChip(isOffline) {
        return isOffline ? ' <span class="offline-chip" title="Raised from an offline flush on reconnect">⚑ Offline</span>' : '';
    }

    function sevBadge(sev) {
        const icon = sev === 'CRITICAL' ? '● CRIT' : '▲ WARN';
        return `<span class="sev-badge ${sev.toLowerCase()}">${icon}</span>`;
    }

    function statusPill(status) {
        const cls = status === 'ACKNOWLEDGED' ? 'acknowledged' : 'open';
        const label = status === 'ACKNOWLEDGED' ? "Ack'd" : 'Open';
        return `<span class="status-pill ${cls}">${label}</span>`;
    }

    function renderRows(rows) {
        const tbody = document.getElementById('alerts-tbody');
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="feed-empty">No alerts match these filters.</td></tr>';
            selectedIds.clear();
            refreshBulkBar();
            refreshSelectAllState();
            return;
        }
        tbody.innerHTML = rows.map(r => {
            const rowCls = (r.severity === 'CRITICAL' && r.status === 'OPEN') ? 'row-critical-open' : '';
            const ackBtn = r.can_ack
                ? `<button class="btn-sm btn-secondary-sm" onclick="event.stopPropagation(); openAlert('${r.id}', true)">Ack</button>`
                : '';
            // Only open alerts are selectable for bulk acknowledge.
            const checkboxCell = r.can_ack
                ? `<td onclick="event.stopPropagation();"><input type="checkbox" class="alert-checkbox" value="${r.id}" ${selectedIds.has(r.id) ? 'checked' : ''} onclick="event.stopPropagation(); onAlertCheckboxToggle(this);"></td>`
                : `<td></td>`;
            return `<tr class="${rowCls}" onclick="openAlert('${r.id}', false)">
                ${checkboxCell}
                <td>${sevBadge(r.severity)}</td>
                <td>${escapeHtml(r.type)}${offlineChip(r.is_offline)}</td>
                <td>${escapeHtml(r.guard_name)}</td>
                <td>${escapeHtml(r.site_name)}</td>
                <td style="color:var(--text-muted);">${escapeHtml(r.age)}</td>
                <td>${statusPill(r.status)}</td>
                <td><div class="feed-actions">
                    <button class="btn-sm btn-primary-sm" onclick="event.stopPropagation(); openAlert('${r.id}', false)">Open</button>
                    ${ackBtn}
                </div></td>
            </tr>`;
        }).join('');

        // Drop selections that are no longer on the page (e.g. acked elsewhere,
        // filtered out, or paged away) so the count stays truthful.
        const present = new Set(rows.filter(r => r.can_ack).map(r => r.id));
        selectedIds.forEach(id => { if (!present.has(id)) selectedIds.delete(id); });
        refreshBulkBar();
        refreshSelectAllState();
    }

    // ── Bulk select + acknowledge ───────────────────────────────────────────
    function onAlertCheckboxToggle(cb) {
        if (cb.checked) selectedIds.add(cb.value); else selectedIds.delete(cb.value);
        refreshBulkBar();
        refreshSelectAllState();
    }

    function toggleSelectAll(master) {
        document.querySelectorAll('.alert-checkbox').forEach(cb => {
            cb.checked = master.checked;
            if (master.checked) selectedIds.add(cb.value); else selectedIds.delete(cb.value);
        });
        refreshBulkBar();
    }

    function refreshSelectAllState() {
        const master = document.getElementById('select-all-alerts');
        if (!master) return;
        const boxes = Array.from(document.querySelectorAll('.alert-checkbox'));
        const checked = boxes.filter(b => b.checked).length;
        master.checked = boxes.length > 0 && checked === boxes.length;
        master.indeterminate = checked > 0 && checked < boxes.length;
    }

    function refreshBulkBar() {
        const bar = document.getElementById('bulk-ack-bar');
        const count = selectedIds.size;
        document.getElementById('bulk-ack-count').textContent =
            `${count} selected`;
        bar.style.display = count > 0 ? 'flex' : 'none';
        if (count === 0) {
            document.getElementById('bulk-ack-error').classList.remove('show');
            document.getElementById('bulk-ack-note').classList.remove('error');
        }
    }

    function submitBulkAck() {
        const ids = Array.from(selectedIds);
        if (!ids.length) return;

        const noteEl = document.getElementById('bulk-ack-note');
        const errEl = document.getElementById('bulk-ack-error');
        const btn = document.getElementById('bulk-ack-btn');
        const note = (noteEl.value || '').trim();
        if (!note) {
            noteEl.classList.add('error');
            errEl.textContent = 'A note is required to acknowledge.';
            errEl.classList.add('show');
            noteEl.focus();
            return;
        }

        btn.disabled = true;
        const original = btn.textContent;
        btn.textContent = 'Acknowledging…';

        fetch(bulkAckUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ ids, note }),
        })
            .then(r => r.json())
            .then(body => {
                if (body.success) {
                    selectedIds.clear();
                    noteEl.value = '';
                    noteEl.classList.remove('error');
                    errEl.classList.remove('show');
                    refreshBulkBar();
                    loadAlerts();
                    if (typeof body.open_count === 'number') updateNavAlertBadge(body.open_count);
                } else {
                    errEl.textContent = body.message || 'Could not acknowledge — please retry.';
                    errEl.classList.add('show');
                    loadAlerts();
                }
            })
            .catch(() => {
                errEl.textContent = 'Network error — please retry.';
                errEl.classList.add('show');
            })
            .finally(() => {
                btn.disabled = false;
                btn.textContent = original;
            });
    }

    function loadAlerts() {
        const params = new URLSearchParams(currentFilters());
        params.set('page', currentPage);
        fetch(`${listUrl}?${params.toString()}`, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(json => {
                if (!json.success) return;
                renderRows(json.rows);
                lastPage = json.pagination.last_page || 1;
                currentPage = json.pagination.current_page || 1;
                document.getElementById('page-label').textContent = `Page ${currentPage} of ${lastPage} · ${json.pagination.total} total`;
                document.getElementById('page-prev').disabled = currentPage <= 1;
                document.getElementById('page-next').disabled = currentPage >= lastPage;
            })
            .catch(() => { /* transient — keep last good state */ });
    }

    function onFilterChange() { currentPage = 1; loadAlerts(); }

    function changePage(delta) {
        const next = currentPage + delta;
        if (next < 1 || next > lastPage) return;
        currentPage = next;
        loadAlerts();
    }

    // ── Detail drawer ──────────────────────────────────────────────────────
    function openAlert(id, focusNote) {
        openAlertId = id;
        fetch(`${showUrlBase}/${id}`, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(json => {
                if (!json.success) return;
                renderDrawer(json.alert);
                document.getElementById('alert-drawer').classList.add('open');
                document.getElementById('alert-overlay').classList.add('show');
                if (focusNote) {
                    const ta = document.getElementById('ack-note');
                    if (ta) ta.focus();
                }
            });
    }

    function renderDrawer(a) {
        const ackSection = a.can_ack
            ? `<hr class="drawer-divider"/>
               <div class="detail-field">
                   <label class="detail-label">Add Outcome Note (required)</label>
                   <textarea class="ack-textarea" id="ack-note" placeholder="What was done / what was found…"></textarea>
                   <div class="ack-error" id="ack-error">A note is required to acknowledge this alert.</div>
               </div>
               <button class="drawer-btn primary" onclick="submitAck('${a.id}')">ACKNOWLEDGE</button>`
            : `<hr class="drawer-divider"/>
               <div class="detail-field">
                   <label class="detail-label">Acknowledged</label>
                   <div class="detail-value">${escapeHtml(a.acknowledged_at || '—')}</div>
               </div>
               <div class="detail-field">
                   <label class="detail-label">Outcome Note</label>
                   <div class="ack-note-box">${escapeHtml(a.acknowledgment_note || '—')}</div>
               </div>`;

        const shiftBtn = a.shift_url
            ? `<a class="drawer-btn outline" href="${a.shift_url}">View Shift Detail →</a>`
            : '';

        document.getElementById('drawer-body').innerHTML = `
            <div class="detail-field">
                <label class="detail-label">Type</label>
                <div class="detail-value">${sevBadge(a.severity)} &nbsp; ${escapeHtml(a.type)}</div>
            </div>
            <div class="detail-field">
                <label class="detail-label">Guard</label>
                <div class="detail-value">${escapeHtml(a.guard_name)}</div>
            </div>
            <div class="detail-field">
                <label class="detail-label">Site</label>
                <div class="detail-value">${escapeHtml(a.site_name)}</div>
            </div>
            <div class="detail-field">
                <label class="detail-label">Raised</label>
                <div class="detail-value">${escapeHtml(a.raised_at || '—')} <span style="color:var(--text-muted);">(${escapeHtml(a.raised_ago || '')})</span></div>
            </div>
            <div class="detail-field">
                <label class="detail-label">Status</label>
                <div class="detail-value">${statusPill(a.status)}</div>
            </div>
            <div class="detail-field">
                <label class="detail-label">Description</label>
                <div class="detail-value desc">${escapeHtml(a.description || '—')}</div>
            </div>
            ${ackSection}
            ${shiftBtn}
        `;
    }

    function submitAck(id) {
        const ta = document.getElementById('ack-note');
        const err = document.getElementById('ack-error');
        const note = (ta.value || '').trim();
        if (!note) {
            ta.classList.add('error');
            err.classList.add('show');
            ta.focus();
            return;
        }
        fetch(`${showUrlBase}/${id}/acknowledge`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ note }),
        })
            .then(r => r.json().then(j => ({ status: r.status, body: j })))
            .then(({ status, body }) => {
                if (body.success) {
                    closeAlertDrawer();
                    loadAlerts();
                    if (typeof body.open_count === 'number') updateNavAlertBadge(body.open_count);
                } else {
                    // Already acknowledged by someone else, or validation failed — refresh.
                    err.textContent = body.message || 'Could not acknowledge — please retry.';
                    err.classList.add('show');
                    loadAlerts();
                }
            })
            .catch(() => {
                err.textContent = 'Network error — please retry.';
                err.classList.add('show');
            });
    }

    function closeAlertDrawer() {
        openAlertId = null;
        document.getElementById('alert-drawer').classList.remove('open');
        document.getElementById('alert-overlay').classList.remove('show');
    }

    // Update the sidebar "Alerts" badge if present (defined in the layout).
    function updateNavAlertBadge(count) {
        if (window.setNavAlertBadge) window.setNavAlertBadge(count);
    }

    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAlertDrawer(); });

    // Deep-link filters from the dashboard KPI cards (e.g. Critical Alerts →
    // ?severity=CRITICAL&status=OPEN, Pending Acks → ?status=OPEN). Pre-set the
    // matching dropdowns before the first load so the feed opens pre-filtered.
    (function () {
        const qp = new URLSearchParams(window.location.search);
        const setSel = (id, val) => {
            if (val === null) return;
            const el = document.getElementById(id);
            if (el && [...el.options].some(o => o.value === val)) el.value = val;
        };
        setSel('f-severity', qp.get('severity'));
        setSel('f-status', qp.get('status'));
        setSel('f-site', qp.get('site_id'));
        setSel('f-guard', qp.get('guard_id'));
        setSel('f-type', qp.get('type'));
        setSel('f-offline', qp.get('offline'));
    })();

    // Initial load, then poll every 15s (matches the dashboard cadence).
    loadAlerts();
    setInterval(loadAlerts, 15000);

    // Deep-link from the dashboard "Ack" button: ?ack=<id> auto-opens that
    // alert's detail drawer, focused on the note box.
    const ackParam = new URLSearchParams(window.location.search).get('ack');
    if (ackParam) openAlert(ackParam, true);
</script>
@endsection
