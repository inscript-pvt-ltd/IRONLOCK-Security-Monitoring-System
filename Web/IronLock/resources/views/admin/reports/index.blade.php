@extends('admin.layouts.app')

@section('title', 'Reports & Exports - IronLock')
@section('page-title', 'Reports & Exports')

@php
    // Page-level field spec per report type (D-09) — drives which inputs this
    // form shows. The output format is chosen later on the report page (every
    // type supports PDF + CSV), so it is not modelled here. Server re-validates.
    $typeSpec = [
        'shift_welfare'      => ['fields' => ['shift'],                            'includes' => ['nonce', 'hashes']],
        'compliance_summary' => ['fields' => ['date_range', 'guard', 'site'],      'includes' => []],
        'alert_history'      => ['fields' => ['date_range', 'guard', 'site'],      'includes' => []],
        'sia_licence_status' => ['fields' => [],                                   'includes' => []],
        'shift_audit'        => ['fields' => ['shift'],                            'includes' => ['hashes']],
        'wtr_compliance'     => ['fields' => ['guard_required', 'reference_date'], 'includes' => []],
    ];
@endphp

@section('styles')
<style>
    .reports-grid { display: flex; gap: 16px; align-items: flex-start; flex-wrap: wrap; }
    .reports-col { flex: 1; min-width: 320px; }

    .panel {
        background: var(--surface-dark); border: 1px solid var(--border-dark);
        border-radius: 8px; padding: 18px 18px 20px; margin-bottom: 16px;
    }
    .panel-title { font-size: 13px; font-weight: bold; color: var(--text-primary); margin-bottom: 14px; }

    .field { margin-bottom: 13px; }
    .field label { display: block; font-size: 11px; color: var(--text-secondary); margin-bottom: 5px; }
    .field-input, .field-select {
        width: 100%; background: var(--bg-dark); border: 1.5px solid var(--border-dark);
        color: var(--text-primary); font-size: 12px; font-family: inherit;
        padding: 8px 10px; border-radius: 5px;
    }
    .field-input:focus, .field-select:focus { outline: none; border-color: var(--premium-gold); }
    .field.hidden { display: none; }
    .field-row { display: flex; gap: 10px; }
    .field-row .field { flex: 1; }

    .includes-row { display: flex; flex-direction: column; gap: 11px; margin: 4px 0 6px; }
    .include-toggle { display: block; font-size: 11px; color: var(--text-secondary); cursor: pointer; }
    .include-head { display: flex; align-items: center; gap: 6px; font-weight: bold; color: var(--text-primary); }
    .include-desc { display: block; margin: 3px 0 0 22px; font-size: 10px; color: var(--text-muted); line-height: 1.45; }

    .btn-generate {
        width: 100%; margin-top: 6px; padding: 11px; border-radius: 6px;
        font-size: 13px; font-weight: bold; cursor: pointer;
        background: var(--premium-gold); color: #1a1407; border: none; transition: filter 0.2s ease;
    }
    .btn-generate:hover:not(:disabled) { filter: brightness(1.08); }
    .btn-generate:disabled { opacity: 0.55; cursor: default; }
    .gen-hint { font-size: 10px; color: var(--text-muted); margin-top: 6px; text-align: center; }
    .gen-error { color: var(--error-red); font-size: 11px; margin-top: 8px; min-height: 14px; }

    .gen-progress { margin-top: 12px; }
    .gen-progress-bar { height: 8px; border-radius: 5px; background: var(--bg-dark); border: 1px solid var(--border-dark); overflow: hidden; }
    .gen-progress-bar span { display: block; height: 100%; width: 0; background: var(--premium-gold); transition: width 0.25s ease; }
    .gen-progress-label { font-size: 11px; color: var(--text-secondary); margin-top: 7px; text-align: center; }

    table.exports { width: 100%; border-collapse: collapse; }
    table.exports th {
        text-align: left; font-size: 10px; text-transform: uppercase; color: var(--text-muted);
        border-bottom: 1px solid var(--border-dark); padding: 7px 8px;
    }
    table.exports td { font-size: 12px; color: var(--text-secondary); border-bottom: 1px solid var(--border-dark); padding: 8px; }
    table.exports tr:last-child td { border-bottom: none; }
    .exports-empty { color: var(--text-muted); font-size: 12px; padding: 14px 4px; }

    /* Row selection + icon actions */
    .th-check, td.exp-check-cell { width: 26px; text-align: center; padding-left: 4px; padding-right: 4px; }
    .exp-check, #exp-check-all { accent-color: var(--premium-gold); cursor: pointer; width: 14px; height: 14px; }

    .exp-actions { display: flex; gap: 5px; justify-content: flex-end; align-items: center; }
    .ico-btn {
        display: inline-flex; align-items: center; justify-content: center;
        width: 28px; height: 28px; padding: 0; border-radius: 5px; cursor: pointer;
        background: var(--bg-dark); border: 1px solid var(--border-dark); color: var(--text-secondary);
        text-decoration: none; transition: color .15s ease, border-color .15s ease, background .15s ease;
    }
    .ico-btn svg { width: 15px; height: 15px; }
    .ico-btn:hover { color: var(--premium-gold); border-color: var(--premium-gold); }
    .ico-btn.danger:hover { color: var(--error-red); border-color: var(--error-red); }

    .dl-wrap { position: relative; }
    .dl-menu {
        position: absolute; right: 0; top: calc(100% + 4px); min-width: 96px; z-index: 30;
        background: var(--surface-dark); border: 1px solid var(--border-dark); border-radius: 6px;
        overflow: hidden; box-shadow: 0 8px 22px rgba(0,0,0,0.45);
    }
    .dl-menu.hidden { display: none; }
    .dl-menu a { display: block; padding: 8px 13px; font-size: 11px; font-weight: bold; color: var(--text-secondary); text-decoration: none; }
    .dl-menu a:hover { background: var(--bg-dark); color: var(--premium-gold); }

    .bulk-bar {
        display: flex; align-items: center; justify-content: space-between; gap: 10px;
        margin-bottom: 10px; padding: 8px 11px; border-radius: 6px;
        background: rgba(212,175,55,0.08); border: 1px solid var(--border-dark);
    }
    .bulk-bar.hidden { display: none; }
    .bulk-count { font-size: 11px; color: var(--text-secondary); }
    .bulk-del {
        font-size: 11px; font-weight: bold; padding: 5px 12px; border-radius: 5px; cursor: pointer;
        background: transparent; border: 1px solid var(--error-red); color: var(--error-red);
    }
    .bulk-del:hover { background: var(--error-red); color: #fff; }
</style>
@endsection

@section('content')
<div class="reports-grid">
    {{-- Generator (D-09 left) --}}
    <div class="reports-col">
        <div class="panel">
            <div class="panel-title">Generate a Report</div>

            <div class="field">
                <label for="report_type">Report type</label>
                <select id="report_type" class="field-select">
                    @foreach ($types as $t)
                        <option value="{{ $t->value }}">{{ $t->label() }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Shift (welfare / audit) --}}
            <div class="field hidden" data-field="shift">
                <label for="f_shift">Shift</label>
                <select id="f_shift" class="field-select">
                    <option value="">— Select a shift —</option>
                    @foreach ($shifts as $s)
                        @php $g = $s->assignedGuard; @endphp
                        <option value="{{ $s->id }}">
                            {{ $s->reference ?? substr($s->id, 0, 8) }}
                            — {{ $g ? trim($g->first_name.' '.$g->last_name) : 'Unassigned' }}
                            ({{ ucfirst($s->status) }})
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- Date range (compliance / alerts) --}}
            <div class="field-row">
                <div class="field hidden" data-field="date_range">
                    <label for="f_from">From</label>
                    <input type="date" id="f_from" class="field-input">
                </div>
                <div class="field hidden" data-field="date_range">
                    <label for="f_to">To</label>
                    <input type="date" id="f_to" class="field-input">
                </div>
            </div>

            {{-- Reference date (WTR) --}}
            <div class="field hidden" data-field="reference_date">
                <label for="f_ref">Reference date <span style="color:var(--text-muted);">(end of 17-week window)</span></label>
                <input type="date" id="f_ref" class="field-input">
            </div>

            {{-- Guard --}}
            <div class="field hidden" data-field="guard">
                <label for="f_guard"><span data-guard-label>Guard (optional)</span></label>
                <select id="f_guard" class="field-select">
                    <option value="">— All guards —</option>
                    @foreach ($guards as $g)
                        <option value="{{ $g->id }}">{{ trim($g->first_name.' '.$g->last_name) }} ({{ $g->employee_code }})</option>
                    @endforeach
                </select>
            </div>

            {{-- Site --}}
            <div class="field hidden" data-field="site">
                <label for="f_site">Site (optional)</label>
                <select id="f_site" class="field-select">
                    <option value="">— All sites —</option>
                    @foreach ($sites as $st)
                        <option value="{{ $st->id }}">{{ $st->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Optional evidence includes (shift-scoped). Client-friendly labels
                 for what were the "Nonce audit trail" / "SHA-256 hashes" toggles. --}}
            <div class="field hidden" data-field="includes">
                <label>Include in export</label>
                <div class="includes-row">
                    <label class="include-toggle" data-include="nonce">
                        <span class="include-head"><input type="checkbox" id="inc_nonce"> Photo liveness proof</span>
                        <span class="include-desc">The one-time code each photo answered — proof it was taken live, not a reused image.</span>
                    </label>
                    <label class="include-toggle" data-include="hashes">
                        <span class="include-head"><input type="checkbox" id="inc_hashes"> Photo tamper-check</span>
                        <span class="include-desc">A unique fingerprint for each photo, so you can prove it hasn't been edited.</span>
                    </label>
                </div>
            </div>

            <button type="button" id="btn-generate" class="btn-generate">Generate Report</button>
            <div class="gen-hint">Opens the report page — download PDF or CSV there.</div>
            <div class="gen-error" id="gen-error"></div>

            {{-- Generation progress (0 → 100%) shown while the report builds. --}}
            <div class="gen-progress" id="gen-progress" hidden>
                <div class="gen-progress-bar"><span id="gen-progress-fill"></span></div>
                <div class="gen-progress-label"><span id="gen-progress-pct">0%</span> · Generating report…</div>
            </div>
        </div>
    </div>

    {{-- Previous exports (D-09 right) --}}
    <div class="reports-col">
        <div class="panel">
            <div class="panel-title">Previous Exports</div>

            {{-- Bulk selection bar — appears once one or more rows are checked. --}}
            <div class="bulk-bar hidden" id="bulk-bar">
                <span class="bulk-count" id="bulk-count">0 selected</span>
                <button type="button" class="bulk-del" id="bulk-del">Delete selected</button>
            </div>

            <table class="exports" id="exports-table">
                <thead>
                    <tr>
                        <th class="th-check"><input type="checkbox" id="exp-check-all" title="Select all"></th>
                        <th>Report</th><th>Scope</th><th>Generated</th><th></th>
                    </tr>
                </thead>
                <tbody id="exports-body">
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
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
(function () {
    var SPEC = @json($typeSpec);
    var token = document.querySelector('meta[name="csrf-token"]');
    token = token ? token.content : '';

    var typeSel   = document.getElementById('report_type');
    var guardLbl  = document.querySelector('[data-guard-label]');
    var errBox    = document.getElementById('gen-error');
    var btn       = document.getElementById('btn-generate');
    var progress  = document.getElementById('gen-progress');
    var progFill  = document.getElementById('gen-progress-fill');
    var progPct   = document.getElementById('gen-progress-pct');

    function showFields(type) {
        var spec = SPEC[type] || { fields: [], includes: [] };
        var wanted = spec.fields || [];
        var guardRequired = wanted.indexOf('guard_required') !== -1;

        // Toggle each field block by its data-field key.
        document.querySelectorAll('[data-field]').forEach(function (el) {
            var key = el.dataset.field;
            var on = wanted.indexOf(key) !== -1
                || (key === 'guard' && guardRequired)
                || (key === 'includes' && (spec.includes || []).length > 0);
            el.classList.toggle('hidden', !on);
        });

        // Guard label reflects required vs optional.
        if (guardLbl) guardLbl.textContent = guardRequired ? 'Guard (required)' : 'Guard (optional)';

        // Per-type include toggles.
        document.querySelectorAll('[data-include]').forEach(function (el) {
            el.style.display = (spec.includes || []).indexOf(el.dataset.include) !== -1 ? '' : 'none';
        });
    }

    typeSel.addEventListener('change', function () { errBox.textContent = ''; showFields(this.value); });
    showFields(typeSel.value);

    // Localise the server-rendered "Generated" times to the viewer's timezone.
    document.querySelectorAll('.rpt-local').forEach(function (el) {
        var d = new Date(el.dataset.ts);
        if (el.dataset.ts && !isNaN(d)) { el.textContent = d.toLocaleString(); el.title = 'UTC: ' + el.dataset.ts; }
    });

    // Tag each row's download links with the viewer's timezone so the PDF/CSV
    // print times in the same local zone (stored data stays UTC).
    try {
        var viewerTz = Intl.DateTimeFormat().resolvedOptions().timeZone;
        if (viewerTz) {
            document.querySelectorAll('.dl-menu a').forEach(function (a) {
                a.href += (a.href.indexOf('?') > -1 ? '&' : '?') + 'tz=' + encodeURIComponent(viewerTz);
            });
        }
    } catch (e) { /* no Intl → server falls back to UTC */ }

    // Animate the progress bar toward 90% while the request is in flight; the
    // response snaps it to 100% before redirecting to the report page.
    var progTimer = null;
    function startProgress() {
        var pct = 0;
        setProgress(0);
        progress.hidden = false;
        progTimer = setInterval(function () {
            pct += Math.max(1, (88 - pct) * 0.12);
            if (pct >= 90) pct = 90;
            setProgress(pct);
        }, 120);
    }
    function setProgress(pct) {
        progFill.style.width = pct + '%';
        progPct.textContent = Math.round(pct) + '%';
    }
    function stopProgress() { if (progTimer) { clearInterval(progTimer); progTimer = null; } }

    btn.addEventListener('click', async function () {
        errBox.textContent = '';
        var type = typeSel.value;

        var payload = {
            report_type: type,
            shift_id: document.getElementById('f_shift').value || null,
            guard_id: document.getElementById('f_guard').value || null,
            site_id: document.getElementById('f_site').value || null,
            date_from: document.getElementById('f_from').value || null,
            date_to: document.getElementById('f_to').value || null,
            reference_date: document.getElementById('f_ref').value || null,
            include_nonce: document.getElementById('inc_nonce').checked,
            include_hashes: document.getElementById('inc_hashes').checked,
        };

        var original = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Generating…';
        startProgress();

        try {
            var res = await fetch('{{ route('admin.reports.generate') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify(payload)
            });
            var data = await res.json();

            if (!data.success || !data.report) {
                var msg = data.error;
                if (!msg && data.errors) { msg = Object.values(data.errors)[0][0]; }
                errBox.textContent = msg || 'Unable to generate the report.';
                stopProgress();
                progress.hidden = true;
                btn.disabled = false;
                btn.textContent = original;
                return;
            }

            // Complete the bar, then open the report page.
            stopProgress();
            setProgress(100);
            setTimeout(function () { window.location = data.report.view_url; }, 350);
        } catch (e) {
            console.error('Report generation error:', e);
            errBox.textContent = 'Something went wrong. Please try again.';
            stopProgress();
            progress.hidden = true;
            btn.disabled = false;
            btn.textContent = original;
        }
    });

    // ── Previous Exports: select, per-row download menu, delete, bulk delete ──
    var REPORTS_BASE = '{{ url('admin/reports') }}';
    var body      = document.getElementById('exports-body');
    var checkAll  = document.getElementById('exp-check-all');
    var bulkBar   = document.getElementById('bulk-bar');
    var bulkCount = document.getElementById('bulk-count');
    var bulkDel   = document.getElementById('bulk-del');

    function selectedIds() {
        return Array.from(document.querySelectorAll('.exp-check:checked')).map(function (c) { return c.dataset.id; });
    }
    function refreshBulk() {
        var ids = selectedIds();
        var all = document.querySelectorAll('.exp-check');
        bulkBar.classList.toggle('hidden', ids.length === 0);
        bulkCount.textContent = ids.length + ' selected';
        if (checkAll) checkAll.checked = all.length > 0 && ids.length === all.length;
    }
    function removeRow(id) {
        var row = document.querySelector('[data-report-row="' + id + '"]');
        if (row) row.remove();
        if (!document.querySelector('[data-report-row]')) {
            body.innerHTML = '<tr id="exports-empty-row"><td colspan="5"><div class="exports-empty">No reports have been generated yet.</div></td></tr>';
        }
        refreshBulk();
    }
    function closeMenus(except) {
        document.querySelectorAll('.dl-menu').forEach(function (m) { if (m !== except) m.classList.add('hidden'); });
    }

    if (checkAll) {
        checkAll.addEventListener('change', function () {
            document.querySelectorAll('.exp-check').forEach(function (c) { c.checked = checkAll.checked; });
            refreshBulk();
        });
    }

    body.addEventListener('change', function (e) {
        if (e.target.classList.contains('exp-check')) refreshBulk();
    });

    body.addEventListener('click', async function (e) {
        // Download format menu toggle.
        var toggle = e.target.closest('.dl-toggle');
        if (toggle) {
            e.stopPropagation();
            var menu = toggle.parentNode.querySelector('.dl-menu');
            var wasHidden = menu.classList.contains('hidden');
            closeMenus();
            menu.classList.toggle('hidden', !wasHidden);
            return;
        }

        // Delete a single report.
        var del = e.target.closest('.del-btn');
        if (del) {
            if (!confirm('Delete this report? This cannot be undone.')) return;
            var id = del.dataset.id;
            try {
                var res = await fetch(REPORTS_BASE + '/' + id, {
                    method: 'DELETE',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token }
                });
                var data = await res.json();
                if (data.success) { removeRow(id); }
                else { alert(data.error || 'Unable to delete the report.'); }
            } catch (err) { console.error(err); alert('Something went wrong. Please try again.'); }
        }
    });

    // Close any open download menu when clicking elsewhere.
    document.addEventListener('click', function () { closeMenus(); });

    bulkDel.addEventListener('click', async function () {
        var ids = selectedIds();
        if (!ids.length) return;
        if (!confirm('Delete ' + ids.length + ' report' + (ids.length > 1 ? 's' : '') + '? This cannot be undone.')) return;
        try {
            var res = await fetch('{{ route('admin.reports.bulk-delete') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify({ ids: ids })
            });
            var data = await res.json();
            if (data.success) { ids.forEach(removeRow); }
            else { alert(data.error || 'Unable to delete the selected reports.'); }
        } catch (err) { console.error(err); alert('Something went wrong. Please try again.'); }
    });
})();
</script>
@endsection
