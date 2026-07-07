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
        'shift_audit'        => ['fields' => ['shift'],                            'includes' => []],
        'wtr_compliance'     => ['fields' => ['guard_required', 'reference_date'], 'includes' => []],
    ];
@endphp

@section('styles')
<style>
    .reports-grid { display: flex; gap: 16px; align-items: flex-start; flex-wrap: wrap; }
    .reports-col { flex: 1; min-width: 320px; }

    /* Keep the generator in view while scrolling a long exports list. Only when
       the two columns sit side by side — once they wrap (narrow screens) the
       generator scrolls normally so it doesn't pin over the list. `.content` is
       the scroll container, so top:0 pins it to the top of the content area. */
    @media (min-width: 720px) {
        .gen-col { position: sticky; top: 0; align-self: flex-start; }
    }

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

    /* Previous Exports pager */
    .exports-pager {
        display: flex; align-items: center; justify-content: space-between; gap: 10px;
        margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border-dark);
    }
    .exports-pager.hidden { display: none; }
    .pager-info { font-size: 11px; color: var(--text-muted); }
    .pager-btn {
        font-size: 11px; font-weight: bold; padding: 6px 12px; border-radius: 5px; cursor: pointer;
        background: var(--bg-dark); border: 1px solid var(--border-dark); color: var(--text-secondary);
        transition: color .15s ease, border-color .15s ease;
    }
    .pager-btn:hover:not(:disabled) { color: var(--premium-gold); border-color: var(--premium-gold); }
    .pager-btn:disabled { opacity: 0.4; cursor: default; }
</style>
@endsection

@section('content')
<div class="reports-grid">
    {{-- Generator (D-09 left) --}}
    <div class="reports-col gen-col">
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
                        <span class="include-desc">Adds a <strong>Liveness Proof</strong> result to each photo in the Photo Verification section — proof it was taken live on request, not a reused image.</span>
                    </label>
                    <label class="include-toggle" data-include="hashes">
                        <span class="include-head"><input type="checkbox" id="inc_hashes"> Image integrity check</span>
                        <span class="include-desc">Adds an <strong>Image Integrity</strong> result under each photo in the Photo Verification section — a unique fingerprint proving the file hasn't been edited.</span>
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
                    @include('admin.reports._rows')
                </tbody>
            </table>

            {{-- Pager for the Previous Exports list. Rendered from the paginator's
                 first-page state; the buttons swap the tbody via the JSON list
                 endpoint. Hidden while there is only a single page. --}}
            @php
                $expPage = $previous->currentPage();
                $expLast = $previous->lastPage();
                $expTotal = $previous->total();
            @endphp
            <div class="exports-pager @if ($expLast <= 1) hidden @endif" id="exports-pager"
                 data-page="{{ $expPage }}" data-last="{{ $expLast }}" data-total="{{ $expTotal }}">
                <button type="button" class="pager-btn" id="pg-prev" @if ($expPage <= 1) disabled @endif>‹ Prev</button>
                <span class="pager-info" id="pg-info">Page {{ $expPage }} of {{ $expLast }} · {{ $expTotal }} total</span>
                <button type="button" class="pager-btn" id="pg-next" @if ($expPage >= $expLast) disabled @endif>Next ›</button>
            </div>
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
    var GEN_LABEL = btn.textContent; // "Generate Report" — for restoring after a run

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

    // On Generate we disable the button + show progress, then redirect to the
    // report page. Pressing the browser Back button restores THIS page from the
    // back-forward cache (bfcache) frozen in that mid-generation state — scripts
    // don't re-run on a bfcache restore, so the button would stay disabled.
    // Reset it whenever the page is shown from cache.
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return; // fresh load already renders an enabled button
        stopProgress();
        progress.hidden = true;
        setProgress(0);
        btn.disabled = false;
        btn.textContent = GEN_LABEL;
    });

    // ── Previous Exports: paged list, select, download menu, delete ──────────
    var REPORTS_BASE = '{{ url('admin/reports') }}';
    var LIST_URL     = '{{ route('admin.reports.list') }}';
    var body      = document.getElementById('exports-body');
    var checkAll  = document.getElementById('exp-check-all');
    var bulkBar   = document.getElementById('bulk-bar');
    var bulkCount = document.getElementById('bulk-count');
    var bulkDel   = document.getElementById('bulk-del');

    // Pager state — seeded from the server-rendered first page.
    var pager   = document.getElementById('exports-pager');
    var pgPrev  = document.getElementById('pg-prev');
    var pgNext  = document.getElementById('pg-next');
    var pgInfo  = document.getElementById('pg-info');
    var curPage   = parseInt(pager.getAttribute('data-page'), 10) || 1;
    var lastPage  = parseInt(pager.getAttribute('data-last'), 10) || 1;
    var totalRows = parseInt(pager.getAttribute('data-total'), 10) || 0;
    var loading   = false;

    // Localise row times + tz-tag download links for the currently-rendered rows.
    // Re-run after every page swap so freshly-injected rows get the same treatment
    // (stored data stays UTC; the ?tz= just makes the PDF/CSV print in local time).
    var viewerTz = '';
    try { viewerTz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) { /* no Intl → server falls back to UTC */ }
    function decorateRows() {
        body.querySelectorAll('.rpt-local').forEach(function (el) {
            var d = new Date(el.dataset.ts);
            if (el.dataset.ts && !isNaN(d)) { el.textContent = d.toLocaleString(); el.title = 'UTC: ' + el.dataset.ts; }
        });
        if (!viewerTz) return;
        body.querySelectorAll('.dl-menu a').forEach(function (a) {
            if (a.dataset.tzTagged) return;
            a.href += (a.href.indexOf('?') > -1 ? '&' : '?') + 'tz=' + encodeURIComponent(viewerTz);
            a.dataset.tzTagged = '1';
        });
    }
    decorateRows(); // first (server-rendered) page

    function updatePager() {
        pager.classList.toggle('hidden', lastPage <= 1);
        pgInfo.textContent = 'Page ' + curPage + ' of ' + lastPage + ' · ' + totalRows + ' total';
        pgPrev.disabled = loading || curPage <= 1;
        pgNext.disabled = loading || curPage >= lastPage;
    }

    // Fetch one page of the list and swap the tbody. Event handlers are delegated
    // on `body`, so replacing its contents keeps them wired. Clears any selection.
    async function loadPage(page) {
        if (loading) return;
        loading = true;
        updatePager();
        try {
            var res = await fetch(LIST_URL + '?page=' + encodeURIComponent(page), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            var data = await res.json();
            if (!data.success) throw new Error('list failed');
            body.innerHTML = data.html;
            curPage   = data.pagination.current_page;
            lastPage  = data.pagination.last_page;
            totalRows = data.pagination.total;
            closeMenus();
            if (checkAll) checkAll.checked = false;
            decorateRows();
            refreshBulk();
        } catch (err) {
            console.error('Exports list error:', err);
        } finally {
            loading = false;
            updatePager();
        }
    }

    pgPrev.addEventListener('click', function () { if (curPage > 1) loadPage(curPage - 1); });
    pgNext.addEventListener('click', function () { if (curPage < lastPage) loadPage(curPage + 1); });

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
    // After a delete, reload the current page so it backfills from the next page
    // and the counts stay correct (the server clamps a now-past-the-end page back
    // to the real last page, so we never land on a blank list).
    function afterDelete() { loadPage(curPage); }
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
                if (data.success) { afterDelete(); }
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
            if (data.success) { afterDelete(); }
            else { alert(data.error || 'Unable to delete the selected reports.'); }
        } catch (err) { console.error(err); alert('Something went wrong. Please try again.'); }
    });
})();
</script>
@endsection
