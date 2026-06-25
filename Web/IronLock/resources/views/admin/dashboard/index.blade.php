@extends('admin.layouts.app')

@section('title', 'Dashboard - IronLock')
@section('page-title', 'Dashboard')

@section('styles')
<link rel="stylesheet" href="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.css" />
<style>
    #live-map {
        height: 420px;
        border: 1.5px solid var(--border-dark);
        border-radius: 4px;
        background: var(--surface-dark);
        overflow: hidden;
        z-index: 0;
    }

    /* ── Live-guard MapLibre popup (matches the Sites page card styling) ───── */
    .maplibregl-popup-content {
        background: #0f1929 !important;
        border: none !important;
        border-radius: 10px !important;
        box-shadow: 0 16px 48px rgba(0,0,0,.85), 0 0 0 1px rgba(212,175,55,0.18) !important;
        padding: 10px 14px !important;
        min-width: 180px !important;
        font-family: inherit !important;
    }
    .maplibregl-popup-anchor-bottom .maplibregl-popup-tip,
    .maplibregl-popup-anchor-bottom-left .maplibregl-popup-tip,
    .maplibregl-popup-anchor-bottom-right .maplibregl-popup-tip {
        border-top-color: #0f1929 !important;
        border-bottom-color: transparent !important;
        border-left-color: transparent !important;
        border-right-color: transparent !important;
    }
    .maplibregl-popup-anchor-top .maplibregl-popup-tip,
    .maplibregl-popup-anchor-top-left .maplibregl-popup-tip,
    .maplibregl-popup-anchor-top-right .maplibregl-popup-tip {
        border-bottom-color: #0f1929 !important;
        border-top-color: transparent !important;
        border-left-color: transparent !important;
        border-right-color: transparent !important;
    }
    .guard-popup-name {
        font-size: 12px; font-weight: 700; color: #D4AF37;
        letter-spacing: 0.3px; margin-bottom: 4px;
    }
    .guard-popup-meta {
        font-size: 10px; color: #94a3b8; line-height: 1.5;
    }
    .maplibregl-ctrl-attrib,
    .maplibregl-ctrl-logo { display: none !important; }

    .map-updated-label {
        font-size: 11px;
        color: var(--text-muted);
        margin-top: 6px;
        margin-bottom: 16px;
    }

    /* Dashboard-specific styles using IronLock color palette */
    .alert-row {
        background: var(--surface-dark);
        border: 1.5px solid var(--border-dark);
        border-radius: 4px;
        padding: 12px;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 12px;
        cursor: pointer;
        transition: border-color 0.2s ease;
    }

    .alert-row:hover {
        border-color: var(--premium-gold);
    }

    .alert-row.critical {
        border-left: 3px solid var(--critical-red);
        animation: pulse-border 2s infinite;
    }

    .alert-row.warning {
        border-left: 3px solid var(--warning-amber);
    }

    @keyframes pulse-border {
        0%, 100% { border-left-color: var(--critical-red); }
        50% { border-left-color: #ff6b6b; }
    }

    .alerts-pagination {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 8px;
        font-size: 11px;
        color: var(--text-muted);
    }

    .section-header {
        font-size: 10px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--text-muted);
        margin-bottom: 12px;
        margin-top: 24px;
    }

    .section-header:first-child {
        margin-top: 0;
    }

    .guards-table-container {
        background: var(--surface-dark);
        border: 1.5px solid var(--border-dark);
        border-radius: 4px;
        overflow: hidden;
    }

    .completion-banner {
        background: rgba(34, 197, 94, 0.1);
        border: 1px solid var(--success-green);
        border-radius: 4px;
        padding: 16px;
        margin-bottom: 24px;
        color: var(--success-green);
    }

    .completion-banner h3 {
        margin: 0 0 12px 0;
        font-size: 14px;
    }

    .completion-banner p {
        margin: 0;
        font-size: 12px;
        line-height: 1.4;
    }
</style>
@endsection

@section('topbar-actions')
<form method="POST" action="{{ route('admin.logout') }}" style="display: inline;">
    @csrf
    <button type="submit" class="btn-sm btn-secondary-sm">Logout</button>
</form>
@endsection

@section('content')


<!-- KPI Cards -->
<div class="kpi-row">
    <div class="kpi-card" onclick="location.href='{{ route('admin.guards.index') }}'">
        <div class="kpi-value" style="color: var(--premium-gold);">{{ $stats['active_guards'] }}</div>
        <div class="kpi-label">Active Guards</div>
    </div>
    <div class="kpi-card" onclick="location.href='#'">
        <div class="kpi-value" style="color: var(--critical-red);">{{ $stats['critical_alerts'] }}</div>
        <div class="kpi-label">Critical Alerts</div>
    </div>
    <div class="kpi-card" onclick="location.href='#'">
        <div class="kpi-value" style="color: var(--warning-amber);">{{ $stats['comms_interrupted'] }}</div>
        <div class="kpi-label">Comms Interrupted</div>
    </div>
    <div class="kpi-card" onclick="location.href='#'">
        <div class="kpi-value" style="color: var(--text-primary);">{{ $stats['pending_acks'] }}</div>
        <div class="kpi-label">Pending Acks</div>
    </div>
</div>

<!-- Critical/Warning Alerts -->
<div class="section-header">Critical / Warning Alerts — Unacknowledged</div>

@if(isset($alerts) && count($alerts) > 0)
    <div id="alerts-list">
    @foreach($alerts as $i => $alert)
        <div class="alert-row {{ strtolower($alert['severity']) }}" data-alert-index="{{ $i }}" onclick="viewAlert('{{ $alert['id'] }}')">
            <div class="severity-badge {{ strtolower($alert['severity']) }}">
                @if($alert['severity'] === 'CRITICAL')
                    ● {{ $alert['type'] }}
                @else
                    ▲ {{ $alert['type'] }}
                @endif
            </div>
            <div class="alert-content">
                <div class="alert-title">{{ $alert['title'] }}</div>
                <div class="alert-meta">{{ $alert['guard_name'] }} · {{ $alert['site_name'] }} · {{ $alert['age'] }}</div>
            </div>
            <div class="alert-actions">
                <button class="btn-sm btn-primary-sm" onclick="event.stopPropagation(); viewAlert('{{ $alert['id'] }}')">View</button>
                <button class="btn-sm btn-secondary-sm" onclick="event.stopPropagation(); ackAlert('{{ $alert['id'] }}')">Ack</button>
            </div>
        </div>
    @endforeach
    </div>
    <div id="alerts-pagination" class="alerts-pagination" style="display: none;">
        <button type="button" class="btn-sm btn-secondary-sm" id="alerts-prev">← Prev</button>
        <span id="alerts-page-indicator"></span>
        <button type="button" class="btn-sm btn-secondary-sm" id="alerts-next">Next →</button>
    </div>
@else
    <div style="text-align: center; padding: 24px; color: var(--text-muted); font-size: 12px;">
        🎉 No active alerts — All systems normal
    </div>
@endif

<!-- Live Map -->
<div class="section-header">Live Map — Active Guards</div>
<div id="live-map"></div>
<div class="map-updated-label">
    Map updates every 15 seconds · Last updated: <span id="map-last-updated">—</span>
</div>

<!-- Active Guards Table -->
<div class="section-header">Active Guards — Live (15s polling)</div>

<div class="guards-table-container">
    <table class="table">
        <thead>
            <tr>
                <th>Guard</th>
                <th>Site</th>
                <th>Zone Status</th>
                <th>Last GPS</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody id="live-guards-tbody">
            @if(isset($active_guards) && count($active_guards) > 0)
                @foreach($active_guards as $guard)
                    <tr>
                        <td>{{ $guard['name'] }}</td>
                        <td>{{ $guard['site_name'] ?? 'No Site' }}</td>
                        <td>
                            <div class="status-chip {{ $guard['zone_status_class'] }}">
                                {{ $guard['zone_status_text'] }}
                            </div>
                        </td>
                        <td style="font-size: 11px; color: var(--text-muted);">{{ $guard['last_gps'] }}</td>
                        <td>
                            <button class="btn-sm btn-secondary-sm" onclick="viewShift('{{ $guard['shift_id'] }}')">View Shift</button>
                        </td>
                    </tr>
                @endforeach
            @else
                <tr>
                    <td colspan="5" style="text-align: center; padding: 24px; color: var(--text-muted);">
                        No guards currently on shift
                    </td>
                </tr>
            @endif
        </tbody>
    </table>
</div>
@endsection

@section('scripts')
<script src="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.js"></script>
<script>
    // ── Unacknowledged alerts: page 4 at a time ─────────────────────────────
    // The list is server-rendered once (not part of the 15s live refresh), so
    // paging is purely client-side: hide all but the current window of 4 rows
    // and swap with Prev/Next. Controls stay hidden when there are ≤ 4 alerts.
    (function () {
        const PAGE_SIZE = 4;
        const rows = Array.from(document.querySelectorAll('#alerts-list .alert-row'));
        if (rows.length <= PAGE_SIZE) return; // all fit — leave them visible

        const pager = document.getElementById('alerts-pagination');
        const prevBtn = document.getElementById('alerts-prev');
        const nextBtn = document.getElementById('alerts-next');
        const indicator = document.getElementById('alerts-page-indicator');
        const totalPages = Math.ceil(rows.length / PAGE_SIZE);
        let page = 0;

        function render() {
            rows.forEach((row, i) => {
                row.style.display = (Math.floor(i / PAGE_SIZE) === page) ? '' : 'none';
            });
            indicator.textContent = `Page ${page + 1} of ${totalPages}`;
            prevBtn.disabled = page === 0;
            nextBtn.disabled = page === totalPages - 1;
        }

        prevBtn.addEventListener('click', () => { if (page > 0) { page--; render(); } });
        nextBtn.addEventListener('click', () => { if (page < totalPages - 1) { page++; render(); } });

        pager.style.display = 'flex';
        render();
    })();

    // The dashboard shows a preview of open alerts; the full detail + acknowledge
    // workflow lives on the Alert Feed page (D-03). Deep-link there.
    const alertsFeedUrl = '{{ route('admin.alerts.index') }}';

    function viewAlert(alertId) {
        window.location.href = alertsFeedUrl;
    }

    // Ack deep-links to the Alert Feed and auto-opens that alert's detail drawer
    // (focused on the outcome-note box) so the supervisor can acknowledge there.
    function ackAlert(alertId) {
        window.location.href = alertsFeedUrl + '?ack=' + encodeURIComponent(alertId);
    }

    // Deep-link to the shift timeline. The active-guards table (server-rendered
    // and the 15s live refresh) passes the shift id, not the guard id.
    const shiftTimelineUrlTemplate = '{{ route('admin.shifts.timeline', ['shift' => '__SHIFT_ID__']) }}';

    function viewShift(shiftId) {
        if (!shiftId) return;
        window.location.href = shiftTimelineUrlTemplate.replace('__SHIFT_ID__', shiftId);
    }

    // ── Live Map (MapLibre GL — CARTO Dark Matter vector style) ─────────────
    // Polls /admin/live-guards every 15s and moves guard pins in place. Pin
    // colour reflects zone status: green = inside, amber = outside (grace),
    // grey + dimmed = comms interrupted. Geofence polygons are drawn per site
    // into a single GeoJSON source, rebuilt each refresh.
    const liveGuardsUrl = '{{ route("admin.live-guards") }}';

    const map = new maplibregl.Map({
        container: 'live-map',
        style: 'https://basemaps.cartocdn.com/gl/dark-matter-gl-style/style.json',
        center: [-0.09, 51.5], // [lng, lat]
        zoom: 11,
    });
    map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');

    const guardMarkers = {};   // guard_id → maplibregl.Marker
    let mapReady = false;
    let pendingGuards = null;   // last payload received before the style loaded
    let hasFitBounds = false;

    function zoneColor(status) {
        if (status === 'INSIDE_ZONE')  return '#22C55E';
        if (status === 'OUTSIDE_ZONE') return '#F59E0B';
        return '#64748B'; // COMMS_INTERRUPTED / UNKNOWN
    }

    // Colored dot element for a guard marker (matches the old Leaflet divIcon).
    function makeGuardEl(status) {
        const color = zoneColor(status);
        const dimmed = status === 'COMMS_INTERRUPTED' ? 'opacity:.5;' : '';
        const el = document.createElement('div');
        el.style.cssText = `width:18px;height:18px;border-radius:50%;background:${color};`
            + `border:2px solid #fff;box-shadow:0 0 4px rgba(0,0,0,.5);cursor:pointer;${dimmed}`;
        return el;
    }

    function emptyFC() { return { type: 'FeatureCollection', features: [] }; }

    map.on('load', () => {
        // Single geofence source — one polygon feature per active shift. Fill +
        // line colour are data-driven from each feature's `color` property.
        map.addSource('geofences', { type: 'geojson', data: emptyFC() });
        map.addLayer({
            id: 'geofence-fill', type: 'fill', source: 'geofences',
            paint: { 'fill-color': ['get', 'color'], 'fill-opacity': 0.08 },
        });
        map.addLayer({
            id: 'geofence-line', type: 'line', source: 'geofences',
            paint: { 'line-color': ['get', 'color'], 'line-width': 2 },
        });
        mapReady = true;
        if (pendingGuards) { renderGuards(pendingGuards); pendingGuards = null; }
    });

    function renderGuards(guards) {
        const activeIds = new Set(guards.map(g => g.guard_id));

        // Drop markers for guards no longer on an active shift.
        Object.keys(guardMarkers).forEach(id => {
            if (!activeIds.has(id)) { guardMarkers[id].remove(); delete guardMarkers[id]; }
        });

        // Rebuild the geofence FeatureCollection from scratch each refresh.
        const fenceFeatures = [];

        guards.forEach(g => {
            // Geofence polygon — backend gives [lat, lng] pairs; GeoJSON needs [lng, lat].
            if (g.geofence_coordinates && g.geofence_coordinates.length > 2) {
                const ring = g.geofence_coordinates.map(p => [p[1], p[0]]);
                const f = ring[0], l = ring[ring.length - 1];
                if (f[0] !== l[0] || f[1] !== l[1]) ring.push(f); // close the ring
                fenceFeatures.push({
                    type: 'Feature',
                    properties: { color: (g.zone_status === 'INSIDE_ZONE') ? '#22C55E' : '#F59E0B' },
                    geometry: { type: 'Polygon', coordinates: [ring] },
                });
            }

            if (g.latitude === null || g.longitude === null) return; // no ping yet

            const lngLat = [g.longitude, g.latitude];
            const popupHtml = `<div class="guard-popup-name">${g.guard_name}</div>`
                + `<div class="guard-popup-meta">${g.site_name || 'No Site'}<br>`
                + `${g.zone_status} · ${g.last_seen_human}</div>`;

            if (!guardMarkers[g.guard_id]) {
                const popup = new maplibregl.Popup({ offset: 14, closeButton: false }).setHTML(popupHtml);
                guardMarkers[g.guard_id] = new maplibregl.Marker({ element: makeGuardEl(g.zone_status), anchor: 'center' })
                    .setLngLat(lngLat)
                    .setPopup(popup)
                    .addTo(map);
            } else {
                const m = guardMarkers[g.guard_id];
                m.setLngLat(lngLat);
                // Recolour the existing dot in place (don't swap the element —
                // MapLibre positions the original node it was created with).
                const el = m.getElement();
                el.style.background = zoneColor(g.zone_status);
                el.style.opacity = g.zone_status === 'COMMS_INTERRUPTED' ? '.5' : '1';
                m.getPopup().setHTML(popupHtml);
            }
        });

        const src = map.getSource('geofences');
        if (src) src.setData({ type: 'FeatureCollection', features: fenceFeatures });

        // Fit to all guards once on first successful load.
        if (!hasFitBounds) {
            const positions = guards
                .filter(g => g.latitude !== null && g.longitude !== null)
                .map(g => [g.longitude, g.latitude]);
            if (positions.length > 0) {
                try {
                    const bounds = positions.reduce(
                        (b, c) => b.extend(c),
                        new maplibregl.LngLatBounds(positions[0], positions[0])
                    );
                    map.fitBounds(bounds, { padding: 60, maxZoom: 16, duration: 600 });
                    hasFitBounds = true;
                } catch (e) {}
            }
        }
    }

    function refreshMap() {
        fetch(liveGuardsUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(json => {
                if (!json.success) return;
                const guards = json.data.guards;

                if (mapReady) renderGuards(guards);
                else pendingGuards = guards; // style not loaded yet — render on 'load'

                document.getElementById('map-last-updated').textContent = new Date().toLocaleTimeString();
                refreshGuardTable(guards);
            })
            .catch(err => console.warn('Live map refresh error:', err));
    }

    function refreshGuardTable(guards) {
        const tbody = document.getElementById('live-guards-tbody');
        if (!tbody) return;
        tbody.innerHTML = guards.length === 0
            ? '<tr><td colspan="5" style="text-align:center;padding:24px;color:var(--text-muted);">No guards currently on shift</td></tr>'
            : guards.map(g => {
                const cls = g.comms_interrupted ? 'interrupted'
                    : g.zone_status === 'OUTSIDE_ZONE' ? 'outside' : 'inside';
                const txt = g.comms_interrupted ? '⊘ Comms Interrupted'
                    : g.zone_status === 'OUTSIDE_ZONE' ? '⚠ Outside Zone' : '✓ Inside Zone';
                return `<tr>
                    <td>${g.guard_name}</td>
                    <td>${g.site_name || 'No Site'}</td>
                    <td><div class="status-chip ${cls}">${txt}</div></td>
                    <td style="font-size:11px;color:var(--text-muted);">${g.last_seen_human}</td>
                    <td><button class="btn-sm btn-secondary-sm" onclick="viewShift('${g.shift_id}')">View Shift</button></td>
                </tr>`;
            }).join('');
    }

    // Initial load, then poll every 15s.
    refreshMap();
    setInterval(refreshMap, 15000);
</script>
@endsection
