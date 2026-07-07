@extends('admin.layouts.app')

@section('title', 'Live Map - IronLock')
@section('page-title', 'Live Map')

@section('styles')
<link rel="stylesheet" href="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.css" />
<style>
    /* The map page fills the content area; only the map/side-panel scroll. */
    .map-toolbar {
        display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        margin-bottom: 12px;
    }
    .map-toolbar .filter-select {
        background: var(--bg-dark); border: 1.5px solid var(--border-dark);
        color: var(--text-secondary); font-size: 11px; font-family: inherit;
        padding: 6px 10px; border-radius: 4px; cursor: pointer; min-width: 150px;
    }
    .map-toolbar .filter-select:focus { outline: none; border-color: var(--premium-gold); }
    .active-chip {
        font-size: 11px; font-weight: bold; color: var(--premium-gold);
        border: 1.5px solid var(--premium-gold); border-radius: 12px; padding: 4px 12px;
    }

    .map-wrap {
        position: relative;
        height: calc(100vh - var(--header-h) - 96px);
        min-height: 420px;
        border: 1.5px solid var(--border-dark);
        border-radius: 4px;
        overflow: hidden;
    }
    #live-map-page { width: 100%; height: 100%; background: var(--surface-dark); }

    /* ── Guard pins — colour + shape + border, never colour alone ──────────── */
    .map-pin {
        width: 26px; height: 26px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 9px; font-weight: 800; color: #06121f;
        box-shadow: 0 0 6px rgba(0,0,0,.6); cursor: pointer;
        box-sizing: border-box;
    }

    /* ── Legend (bottom-left) ─────────────────────────────────────────────── */
    .map-legend {
        position: absolute; left: 12px; bottom: 12px; z-index: 5;
        background: rgba(15,23,42,0.92); border: 1px solid var(--border-dark);
        border-radius: 6px; padding: 10px 12px; font-size: 10px; color: var(--text-secondary);
    }
    .map-legend-row { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
    .map-legend-row:last-child { margin-bottom: 0; }
    .legend-dot { width: 14px; height: 14px; border-radius: 50%; box-sizing: border-box; flex-shrink: 0; }

    /* ── Guard side panel (slides from the right, inside the map) ──────────── */
    .guard-panel {
        position: absolute; top: 0; right: 0; bottom: 0; width: 280px; z-index: 6;
        background: var(--surface-dark); border-left: 1.5px solid var(--border-dark);
        padding: 18px; overflow-y: auto;
        transform: translateX(100%); transition: transform 0.28s ease;
    }
    .guard-panel.open { transform: translateX(0); }

    .gp-title {
        font-size: 13px; font-weight: bold; color: var(--text-primary);
        display: flex; align-items: center; justify-content: space-between; gap: 8px;
        margin-bottom: 10px;
    }
    .gp-close { background: none; border: none; color: var(--text-muted); font-size: 18px; cursor: pointer; line-height: 1; }
    .gp-close:hover { color: var(--premium-gold); }

    .gp-status {
        display: inline-block; padding: 3px 10px; border-radius: 10px;
        font-size: 10px; font-weight: bold; margin-bottom: 14px;
    }
    .gp-status.inside { background: rgba(34,197,94,.18); color: var(--success-green); border: 1px solid var(--success-green); }
    .gp-status.outside { background: rgba(245,158,11,.18); color: var(--warning-amber); border: 1px solid var(--warning-amber); }
    .gp-status.alert,
    .gp-status.comms { background: rgba(239,68,68,.18); color: var(--error-red); border: 1px solid var(--error-red); }

    /* Offline callout (Phase 7) — neutral slate band making a *current* comms
       gap explicit ("offline since X"), distinct from the red alert chip. */
    .gp-offline {
        font-size: 11px; color: var(--text-secondary); line-height: 1.45;
        background: rgba(100,116,139,.12); border: 1px solid var(--border-dark);
        border-left: 3px solid #64748B; border-radius: 4px;
        padding: 7px 9px; margin-bottom: 14px;
    }

    .gp-field { margin-bottom: 11px; }
    .gp-label { display: block; font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); margin-bottom: 3px; }
    .gp-value { font-size: 12px; color: var(--text-primary); line-height: 1.4; }
    .gp-divider { border: none; border-top: 1px solid var(--border-dark); margin: 14px 0; }

    .gp-btn {
        display: block; width: 100%; text-align: center; text-decoration: none;
        padding: 8px 12px; font-size: 11px; font-weight: bold; border-radius: 4px;
        margin-top: 8px; transition: all .2s ease;
    }
    .gp-btn.primary { background: var(--deep-security-blue); color: #fff; }
    .gp-btn.primary:hover { background: var(--premium-gold); color: var(--bg-dark); }
    .gp-btn.outline { background: transparent; color: var(--error-red); border: 1px solid var(--error-red); }
    .gp-btn.outline:hover { background: rgba(239,68,68,.12); }

    .maplibregl-ctrl-attrib,
    .maplibregl-ctrl-logo { display: none !important; }
</style>
@endsection

@section('content')

<div class="map-toolbar">
    {{-- Only sites with a guard on an active shift appear here — those are the
         only sites the map can actually plot. A page-load snapshot; markers
         refresh every 15s. When nobody is on duty the list is just "All". --}}
    <select class="filter-select" id="site-filter" onchange="applySiteFilter()">
        <option value="">Active Sites: All</option>
        @foreach($sites as $site)
            <option value="{{ $site->id }}">{{ $site->name }}</option>
        @endforeach
    </select>
    <span class="active-chip">● <span id="active-count">0</span> active guards</span>
    <span style="font-size:11px;color:var(--text-muted);">Updates every 15s · Last: <span id="map-last-updated">—</span></span>
</div>

<div class="map-wrap">
    <div id="live-map-page"></div>

    <div class="map-legend">
        <div class="map-legend-row"><span class="legend-dot" style="background:#22C55E;border:2px solid #fff;"></span>Inside zone (●)</div>
        <div class="map-legend-row"><span class="legend-dot" style="background:#F59E0B;border:2px dashed #fff;"></span>Outside zone (▲)</div>
        <div class="map-legend-row"><span class="legend-dot" style="background:#22C55E;border:3px solid #DC2626;"></span>Open alert / unresponsive (✗)</div>
        <div class="map-legend-row"><span class="legend-dot" style="background:#64748B;border:2px dashed #fff;opacity:.5;"></span>Comms interrupted (⊘)</div>
    </div>

    <div class="guard-panel" id="guard-panel">
        <div class="gp-title">
            <span>Guard</span>
            <button class="gp-close" onclick="closeGuardPanel()" aria-label="Close">×</button>
        </div>
        <div id="guard-panel-body"></div>
    </div>
</div>

@endsection

@section('scripts')
<script src="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.js"></script>
<script>
    const liveGuardsUrl = '{{ route('admin.live-guards') }}';
    const shiftTimelineBase = '{{ url('admin/shifts') }}';
    const alertsFeedUrl = '{{ route('admin.alerts.index') }}';

    // Honour a ?site=<id> deep-link (e.g. from the Guards roster "Active Site"
    // link) so the map opens pre-filtered to that site and fits to its guards.
    let siteFilter = new URLSearchParams(window.location.search).get('site') || '';
    let selectedGuardId = null;
    const guardData = {};   // guard_id → latest payload (for the side panel)

    const map = new maplibregl.Map({
        container: 'live-map-page',
        style: 'https://basemaps.cartocdn.com/gl/dark-matter-gl-style/style.json',
        center: [-0.09, 51.5],
        zoom: 11,
    });
    map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');

    const markers = {};       // guard_id → maplibregl.Marker
    let mapReady = false;
    let pending = null;
    let hasFitBounds = false;

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function initials(name) {
        return (name || '?').split(' ').filter(Boolean).map(w => w[0]).slice(0, 2).join('').toUpperCase();
    }

    function zoneColor(g) {
        if (g.comms_interrupted) return '#64748B';
        if (g.zone_status === 'INSIDE_ZONE') return '#22C55E';
        if (g.zone_status === 'OUTSIDE_ZONE') return '#F59E0B';
        return '#64748B';
    }

    // Pin styling encodes status with shape + border + opacity, not colour alone:
    //   inside → solid · outside → dashed · open alert → bold red border · comms → faded dashed
    function stylePin(el, g) {
        el.style.background = zoneColor(g);
        el.style.borderStyle = 'solid';
        el.style.borderWidth = '2px';
        el.style.borderColor = '#fff';
        el.style.opacity = '1';
        if (g.zone_status === 'OUTSIDE_ZONE' && !g.comms_interrupted) el.style.borderStyle = 'dashed';
        if (g.comms_interrupted) { el.style.borderStyle = 'dashed'; el.style.opacity = '.5'; }
        if (g.has_open_alert) { el.style.borderColor = '#DC2626'; el.style.borderWidth = '3px'; }
    }

    function makePinEl(g) {
        const el = document.createElement('div');
        el.className = 'map-pin';
        el.textContent = initials(g.guard_name);
        stylePin(el, g);
        el.addEventListener('click', () => openGuardPanel(g.guard_id));
        return el;
    }

    function emptyFC() { return { type: 'FeatureCollection', features: [] }; }

    map.on('load', () => {
        map.addSource('geofences', { type: 'geojson', data: emptyFC() });
        map.addLayer({ id: 'geofence-fill', type: 'fill', source: 'geofences',
            paint: { 'fill-color': ['get', 'color'], 'fill-opacity': 0.08 } });
        map.addLayer({ id: 'geofence-line', type: 'line', source: 'geofences',
            paint: { 'line-color': ['get', 'color'], 'line-width': 2 } });
        mapReady = true;
        if (pending) { render(pending); pending = null; }
    });

    function visible(guards) {
        return siteFilter ? guards.filter(g => g.site_id === siteFilter) : guards;
    }

    function render(allGuards) {
        const guards = visible(allGuards);
        const activeIds = new Set(guards.map(g => g.guard_id));

        // Drop markers no longer visible (off shift or filtered out).
        Object.keys(markers).forEach(id => {
            if (!activeIds.has(id)) { markers[id].remove(); delete markers[id]; }
        });

        const fenceFeatures = [];

        guards.forEach(g => {
            guardData[g.guard_id] = g;

            if (g.geofence_coordinates && g.geofence_coordinates.length > 2) {
                const ring = g.geofence_coordinates.map(p => [p[1], p[0]]); // [lat,lng] → [lng,lat]
                const f = ring[0], l = ring[ring.length - 1];
                if (f[0] !== l[0] || f[1] !== l[1]) ring.push(f);
                fenceFeatures.push({
                    type: 'Feature',
                    properties: { color: g.zone_status === 'INSIDE_ZONE' ? '#22C55E' : '#F59E0B' },
                    geometry: { type: 'Polygon', coordinates: [ring] },
                });
            }

            if (g.latitude === null || g.longitude === null) return; // no fix yet
            const lngLat = [g.longitude, g.latitude];

            if (!markers[g.guard_id]) {
                markers[g.guard_id] = new maplibregl.Marker({ element: makePinEl(g), anchor: 'center' })
                    .setLngLat(lngLat).addTo(map);
            } else {
                const m = markers[g.guard_id];
                m.setLngLat(lngLat);
                stylePin(m.getElement(), g); // restyle in place
            }
        });

        const src = map.getSource('geofences');
        if (src) src.setData({ type: 'FeatureCollection', features: fenceFeatures });

        if (!hasFitBounds) {
            const pts = guards.filter(g => g.latitude !== null && g.longitude !== null).map(g => [g.longitude, g.latitude]);
            if (pts.length) {
                try {
                    const b = pts.reduce((acc, c) => acc.extend(c), new maplibregl.LngLatBounds(pts[0], pts[0]));
                    map.fitBounds(b, { padding: 70, maxZoom: 16, duration: 600 });
                    hasFitBounds = true;
                } catch (e) {}
            }
        }

        // Keep an open side panel fresh.
        if (selectedGuardId && guardData[selectedGuardId]) renderPanel(guardData[selectedGuardId]);
    }

    let lastPayload = [];
    function refresh() {
        fetch(liveGuardsUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(json => {
                if (!json.success) return;
                lastPayload = json.data.guards;
                if (mapReady) render(lastPayload); else pending = lastPayload;
                document.getElementById('active-count').textContent = visible(lastPayload).length;
                document.getElementById('map-last-updated').textContent = new Date().toLocaleTimeString();
            })
            .catch(() => { /* transient — keep last good state */ });
    }

    function applySiteFilter() {
        siteFilter = document.getElementById('site-filter').value;
        hasFitBounds = false;            // re-fit to the filtered set
        closeGuardPanel();
        if (mapReady) render(lastPayload);
        document.getElementById('active-count').textContent = visible(lastPayload).length;
    }

    // ── Guard side panel ───────────────────────────────────────────────────
    function fmtTime(iso) {
        if (!iso) return '—';
        const d = new Date(iso);
        return isNaN(d) ? '—' : d.toLocaleString();
    }

    function statusChip(g) {
        if (g.comms_interrupted) return { cls: 'comms', txt: '⊘ Comms Interrupted' };
        if (g.has_open_alert) return { cls: 'alert', txt: '✗ Open Alert' };
        if (g.zone_status === 'OUTSIDE_ZONE') return { cls: 'outside', txt: '▲ Outside Zone' };
        return { cls: 'inside', txt: '● Inside Zone' };
    }

    function renderPanel(g) {
        const s = statusChip(g);
        const w = g.wakefulness || { confirmed: 0, failed: 0, total: 0 };
        const p = g.photos || { fulfilled: 0, missed: 0, total: 0 };
        const coords = (g.latitude !== null && g.longitude !== null)
            ? `${g.latitude.toFixed(4)}, ${g.longitude.toFixed(4)}` : 'No fix yet';
        const failedTxt = w.failed > 0 ? ` <span style="color:var(--error-red);">(${w.failed} failed)</span>` : '';
        const photoMissedTxt = p.missed > 0 ? ` <span style="color:var(--error-red);">(${p.missed} missed)</span>` : '';
        const openAlertBtn = g.has_open_alert
            ? `<a class="gp-btn outline" href="${alertsFeedUrl}">Open Alert →</a>` : '';

        // Make a *current* comms gap explicit: when the guard is offline now, the
        // last fix is also their "last synced" point — buffered pings backfill on
        // reconnect (the timeline then shows the COMMS_GAP / SYNC_FLUSH band).
        const offlineNotice = g.comms_interrupted
            ? `<div class="gp-offline">⊘ Offline since ${escapeHtml(g.last_seen_human || 'unknown')} — buffered data will backfill on reconnect.</div>`
            : '';
        const lastPingLabel = g.comms_interrupted ? 'Last synced' : 'Last GPS ping';

        document.querySelector('#guard-panel .gp-title span').textContent = g.guard_name;
        document.getElementById('guard-panel-body').innerHTML = `
            <div class="gp-status ${s.cls}">${s.txt}</div>
            ${offlineNotice}
            <div class="gp-field"><span class="gp-label">Site</span><div class="gp-value">${escapeHtml(g.site_name || '—')}</div></div>
            <div class="gp-field"><span class="gp-label">${lastPingLabel}</span>
                <div class="gp-value">${escapeHtml(g.last_seen_human || 'Never')}<br><span style="color:var(--text-muted);font-size:11px;">${coords}</span></div></div>
            <div class="gp-field"><span class="gp-label">Shift started</span>
                <div class="gp-value">${fmtTime(g.shift_started_at)} ${g.shift_reference ? '· #' + escapeHtml(g.shift_reference) : ''}</div></div>
            <div class="gp-field"><span class="gp-label">Welfare checks</span>
                <div class="gp-value">${w.confirmed} / ${w.total} ✓${failedTxt}</div></div>
            <div class="gp-field"><span class="gp-label">Photo checks</span>
                <div class="gp-value">${p.fulfilled} / ${p.total} ✓${photoMissedTxt}</div></div>
            <hr class="gp-divider"/>
            <a class="gp-btn primary" href="${shiftTimelineBase}/${g.shift_id}/timeline">View Shift →</a>
            ${openAlertBtn}
        `;
    }

    function openGuardPanel(guardId) {
        const g = guardData[guardId];
        if (!g) return;
        selectedGuardId = guardId;
        renderPanel(g);
        document.getElementById('guard-panel').classList.add('open');
    }

    function closeGuardPanel() {
        selectedGuardId = null;
        document.getElementById('guard-panel').classList.remove('open');
    }

    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeGuardPanel(); });

    // Reflect a ?site= deep-link in the dropdown so the visible filter matches
    // the active one. A stale/removed id simply leaves the select on "All".
    if (siteFilter) {
        const sel = document.getElementById('site-filter');
        if (sel) sel.value = siteFilter;
    }

    refresh();
    setInterval(refresh, 15000);
</script>
@endsection
