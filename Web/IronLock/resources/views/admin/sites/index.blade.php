@extends('admin.layouts.app')

@section('title', 'Site Management - IronLock')
@section('page-title', 'Sites')

@section('topbar-actions')
<button class="btn-sm btn-primary-sm" onclick="openSiteDrawer('add')">+ Add Site</button>
@endsection

@push('head')
<meta name="csrf-token" content="{{ csrf_token() }}">
@endpush

@section('styles')
<style>
    /* D-06 Site & Geofence Management - Exact Wireframe Implementation */
    .d06-layout {
        display: flex;
        min-height: calc(100vh - 64px);
        gap: 0;
        border: 1.5px solid var(--border-dark);
        border-radius: 8px;
        overflow: hidden;
        background: var(--surface-dark);
    }

    /* Left Panel - Sites List */
    .sites-list-panel {
        width: 300px;
        background: var(--surface-dark);
        border-right: 1px solid var(--border-dark);
        display: flex;
        flex-direction: column;
    }

    .sites-list-header {
        padding: 16px;
        border-bottom: 1px solid var(--border-dark);
        background: var(--bg-dark);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .sites-list-header h3 {
        font-size: 14px;
        font-weight: 600;
        color: var(--text-primary);
        margin: 0;
    }

    .sites-list-content {
        flex: 1;
        overflow-y: auto;
        padding: 0;
    }

    .site-list-item {
        padding: 12px 16px;
        border-bottom: 1px solid var(--border-dark);
        cursor: pointer;
        transition: background-color 0.2s ease;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .site-list-item:hover {
        background: rgba(212, 175, 55, 0.05);
    }

    .site-list-item.selected {
        background: rgba(212, 175, 55, 0.1);
        border-left: 3px solid var(--premium-gold);
    }

    .site-item-info {
        flex: 1;
    }

    .site-item-name {
        font-size: 12px;
        font-weight: 500;
        color: var(--text-primary);
        margin-bottom: 2px;
    }

    .site-item-address {
        font-size: 10px;
        color: var(--text-muted);
        line-height: 1.3;
    }

    .site-item-actions {
        margin-left: 8px;
    }

    /* Right Panel - Map Preview */
    .map-preview-panel {
        flex: 1;
        display: flex;
        flex-direction: column;
        position: relative;
    }

    .map-preview-header {
        padding: 16px;
        border-bottom: 1px solid var(--border-dark);
        background: var(--bg-dark);
    }

    .map-preview-header h3 {
        font-size: 14px;
        font-weight: 600;
        color: var(--text-primary);
        margin: 0;
    }

    .map-preview-content {
        flex: 1;
        position: relative;
        display: flex;
        flex-direction: column;
    }

    #siteMap {
        flex: 1;
        min-height: 400px;
        width: 100%;
        height: 100%;
        background: #f4f6f8;
    }

    .map-preview-content {
        flex: 1;
        position: relative;
        display: flex;
        flex-direction: column;
        min-height: 500px;
    }

    /* Geofence Drawing Tools - bottom-right so it doesn't cover the map */
    .geofence-tools {
        position: absolute;
        bottom: 16px;
        right: 16px;
        background: var(--surface-dark);
        border: 1px solid var(--border-dark);
        border-radius: 6px;
        padding: 12px;
        z-index: 1000;
        min-width: 200px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.35);
    }

    .geofence-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 4px;
    }

    .geofence-tools-title {
        font-size: 11px;
        font-weight: 600;
        color: var(--text-primary);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .geofence-help {
        cursor: pointer;
        color: var(--premium-gold);
        font-size: 15px;
        font-weight: 700;
        line-height: 1;
        user-select: none;
        transition: transform 0.15s ease;
    }

    .geofence-help:hover {
        transform: scale(1.15);
    }

    .geofence-site-name {
        font-size: 11px;
        font-weight: 500;
        color: var(--premium-gold);
        margin-bottom: 10px;
        padding-bottom: 8px;
        border-bottom: 1px solid var(--border-dark);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .geofence-tool-group {
        margin-bottom: 12px;
    }

    .geofence-tool-group:last-child {
        margin-bottom: 0;
    }

    .geofence-tool-label {
        font-size: 10px;
        color: var(--text-secondary);
        margin-bottom: 4px;
    }

    .geofence-tool-buttons {
        display: flex;
        gap: 6px;
        margin-bottom: 8px;
    }

    .geofence-tool-btn {
        padding: 6px 10px;
        font-size: 10px;
        border: 1px solid var(--border-dark);
        background: var(--bg-dark);
        color: var(--text-secondary);
        border-radius: 4px;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .geofence-tool-btn:hover {
        background: var(--surface-dark);
        color: var(--text-primary);
    }

    .geofence-tool-btn.active {
        background: var(--deep-security-blue);
        color: white;
        border-color: var(--deep-security-blue);
    }

    /* Green while a shape is actively being drawn */
    .geofence-tool-btn.drawing {
        background: var(--success-green);
        color: white;
        border-color: var(--success-green);
    }

    .radius-input-group {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .radius-input {
        flex: 1;
        padding: 4px 8px;
        font-size: 10px;
        border: 1px solid var(--border-dark);
        background: var(--bg-dark);
        color: var(--text-primary);
        border-radius: 4px;
    }

    .radius-input:focus {
        outline: none;
        border-color: var(--premium-gold);
    }

    .radius-unit {
        font-size: 10px;
        color: var(--text-secondary);
    }

    /* Geofence Status - banner across the top of the map */
    .geofence-status {
        padding: 10px 14px;
        background: rgba(212, 175, 55, 0.12);
        border-bottom: 1px solid var(--border-dark);
        border-left: 3px solid var(--premium-gold);
        font-size: 12px;
        font-weight: 500;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .geofence-status::before {
        content: 'ⓘ';
        color: var(--premium-gold);
        font-size: 13px;
        font-weight: 700;
    }

    /* Action buttons */
    .btn-sm {
        padding: 4px 8px;
        font-size: 10px;
        border-radius: 4px;
        border: none;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.2s ease;
    }

    .btn-primary-sm {
        background: var(--deep-security-blue);
        color: white;
    }

    .btn-primary-sm:hover {
        background: #1a4b7a;
    }

    .btn-secondary-sm {
        background: var(--bg-dark);
        color: var(--text-secondary);
        border: 1px solid var(--border-dark);
    }

    .btn-secondary-sm:hover {
        background: var(--surface-dark);
        color: var(--text-primary);
    }

    /* Empty State */
    .sites-empty-state {
        padding: 40px 20px;
        text-align: center;
        color: var(--text-muted);
    }

    .sites-empty-state p {
        margin-bottom: 16px;
        font-size: 12px;
    }

    /* Site Form Drawer */
    .site-drawer {
        width: 320px;
        background: var(--surface-dark);
        border-left: 1.5px solid var(--border-dark);
        padding: 20px;
        position: fixed;
        right: 0;
        top: 48px;
        bottom: 0;
        transform: translateX(100%);
        transition: transform 0.3s ease;
        z-index: 2000;
        overflow-y: auto;
    }

    .site-drawer.open {
        transform: translateX(0);
    }

    .drawer-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1999;
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.3s ease, visibility 0.3s ease;
    }

    .drawer-overlay.show {
        opacity: 1;
        visibility: visible;
    }

    .drawer-title {
        font-size: 16px;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 1px solid var(--border-dark);
    }

    .drawer-field {
        margin-bottom: 16px;
    }

    .drawer-label {
        display: block;
        font-size: 10px;
        font-weight: 500;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 6px;
    }

    .drawer-input {
        width: 100%;
        padding: 8px 12px;
        background: var(--bg-dark);
        border: 1px solid var(--border-dark);
        border-radius: 4px;
        color: var(--text-primary);
        font-size: 11px;
        transition: border-color 0.2s ease;
    }

    .drawer-input:focus {
        outline: none;
        border-color: var(--premium-gold);
    }

    .drawer-textarea {
        min-height: 60px;
        resize: vertical;
    }

    /* Success Toast */
    .toast {
        position: fixed;
        top: 80px;
        right: 20px;
        max-width: 320px;
        width: max-content;
        box-sizing: border-box;
        background: var(--success-green);
        color: white;
        padding: 12px 16px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 500;
        line-height: 1.4;
        word-wrap: break-word;
        z-index: 10000;
        /* Width-relative so the toast always tucks fully off-screen no matter how
           long the message is (a fixed px value left long toasts half-visible). */
        transform: translateX(calc(100% + 24px));
        transition: transform 0.3s ease;
    }

    .toast.show {
        transform: translateX(0);
    }
</style>

<!-- Leaflet CSS for mapping -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<!-- Leaflet Draw plugin -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.css" />
@endsection

@section('content')
<!-- D-06 Site & Geofence Management Layout -->
<div class="d06-layout">
    <!-- Left Panel - Sites List -->
    <div class="sites-list-panel">
        <div class="sites-list-header">
            <h3>SITES LIST</h3>
        </div>

        <div class="sites-list-content">
            @if($sites->count() > 0)
                @foreach ($sites as $site)
                    <div class="site-list-item" onclick="selectSite('{{ $site->id }}')" data-site-id="{{ $site->id }}">
                        <div class="site-item-info">
                            <div class="site-item-name">{{ $site->name }}</div>
                            <div class="site-item-address">{{ Str::limit($site->address, 35) }}</div>
                        </div>
                        <div class="site-item-actions">
                            <button class="btn-sm btn-secondary-sm" onclick="event.stopPropagation(); openSiteDrawer('edit', '{{ $site->id }}')">Edit</button>
                        </div>
                    </div>
                @endforeach
            @else
                <div class="sites-empty-state">
                    <p>No sites found.</p>
                    <button class="btn-sm btn-primary-sm" onclick="openSiteDrawer('add')">Add your first site</button>
                </div>
            @endif
        </div>
    </div>

    <!-- Right Panel - Map Preview with Geofence Tools -->
    <div class="map-preview-panel">
        <div class="map-preview-header">
            <h3>MAP PREVIEW</h3>
        </div>

        <div class="geofence-status" id="geofenceStatus">
            Select a site to view or edit its geofence
        </div>

        <div class="map-preview-content" id="mapContentWrap">
            <div id="siteMap"></div>

            <!-- Geofence Drawing Tools -->
            <div class="geofence-tools" id="geofenceTools">
                <div class="geofence-card-header">
                    <div class="geofence-tools-title">Draw Geofence</div>
                    <span class="geofence-help" id="geofenceHelp" title="How it works:&#10;1. Select a site on the left — its pin turns red.&#10;2. Click Polygon or Circle (button turns green while drawing).&#10;3. Polygon: click points, then click the first (red) point or double-click to close.&#10;4. Circle: click once to drop the centre, then adjust the Radius box.&#10;5. Clear removes the geofence for that site.&#10;&#10;The map updates automatically after you draw or clear — no page refresh needed.">ⓘ</span>
                </div>
                <div class="geofence-site-name" id="geofenceSiteName">No site selected</div>

                <div class="geofence-tool-group">
                    <div class="geofence-tool-label">Shape Type</div>
                    <div class="geofence-tool-buttons">
                        <button class="geofence-tool-btn" id="polygonTool" onclick="activatePolygonTool()">Polygon</button>
                        <button class="geofence-tool-btn" id="circleTool" onclick="activateCircleTool()">Circle</button>
                    </div>
                </div>

                <div class="geofence-tool-group" id="radiusGroup">
                    <div class="geofence-tool-label">Radius</div>
                    <div class="radius-input-group">
                        <input type="number" class="radius-input" id="radiusInput" value="200" min="10" max="1000" oninput="updateCircleRadius()">
                        <span class="radius-unit">m</span>
                    </div>
                </div>

                <div class="geofence-tool-group">
                    <div class="geofence-tool-buttons">
                        <button class="btn-sm btn-secondary-sm" id="clearBtn" onclick="clearGeofence()" style="width: 100%;">Clear</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Site Management Drawer -->
<div class="drawer-overlay" onclick="closeSiteDrawer()"></div>
<div class="site-drawer" id="siteDrawer">
    <div class="drawer-title" id="drawerTitle">Add Site</div>

    <!-- Site Form -->
    <form id="siteForm" onsubmit="saveSite(event)">
        @csrf
        <input type="hidden" id="siteId" name="site_id">

        <div class="drawer-field">
            <label class="drawer-label">Site Name</label>
            <input type="text" class="drawer-input" id="name" name="name" required>
        </div>

        <div class="drawer-field">
            <label class="drawer-label">Address</label>
            <textarea class="drawer-input drawer-textarea" id="address" name="address" required></textarea>
        </div>

        <div class="drawer-field">
            <label class="drawer-label">GPS Coordinates</label>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                <div>
                    <input type="text" inputmode="decimal" class="drawer-input" id="latitude" name="latitude" placeholder="Latitude" oninput="handleCoordInput(this)">
                </div>
                <div>
                    <input type="text" inputmode="decimal" class="drawer-input" id="longitude" name="longitude" placeholder="Longitude" oninput="handleCoordInput(this)">
                </div>
            </div>
            <div style="font-size: 10px; color: var(--text-muted); margin-top: 4px;">
                Click the map to set, or paste "lat, lng" into either field
            </div>
        </div>

        <div class="drawer-field">
            <label class="drawer-label">Grace Period (minutes)</label>
            <input type="number" class="drawer-input" id="grace_period_minutes" name="grace_period_minutes" min="1" max="30" value="5">
        </div>

        <div class="drawer-field">
            <label class="drawer-label">Contact Person</label>
            <input type="text" class="drawer-input" id="contact_person" name="contact_person">
        </div>

        <div class="drawer-field">
            <label class="drawer-label">Contact Phone</label>
            <input type="text" class="drawer-input" id="contact_phone" name="contact_phone">
        </div>

        <div class="drawer-field">
            <label class="drawer-label">Status</label>
            <select class="drawer-input" id="status" name="status">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
        </div>

        <!-- Action Buttons -->
        <div style="display: flex; gap: 12px; margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--border-dark);">
            <button type="button" class="btn-sm btn-secondary-sm" onclick="closeSiteDrawer()" style="flex: 1;">Cancel</button>
            <button type="submit" class="btn-sm btn-primary-sm" id="submitBtn" style="flex: 2;">Save Site</button>
        </div>
    </form>
</div>

<!-- Success Toast -->
<div class="toast" id="successToast">
    Site operation completed successfully!
</div>
@endsection

@section('scripts')
<!-- Leaflet JS for interactive mapping -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<!-- Leaflet Draw plugin -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.js"></script>

<script>
    // Global variables
    let map, sites = @json($sites->items());
    let currentSiteId = null;
    let currentMode = 'add'; // 'add', 'edit', 'view'
    let siteMarkers = {};
    let currentGeofence = null;
    let drawingTool = 'circle';
    let isDrawingMode = false;
    // When the current geofence is a circle we remember its centre + radius so the
    // radius box can resize it, even after it is re-fetched from the server (where
    // circles are stored as polygons and would otherwise lose their "circle" type).
    let currentCircle = null; // { center: [lat, lng], radius: <metres> }

    // Shared geofence visual style
    const GEO_STYLE = { color: '#D4AF37', fillColor: '#D4AF37', fillOpacity: 0.2, weight: 2 };
    const PIN_DEFAULT = '#12355B';
    const PIN_SELECTED = '#E63946';

    // Build a teardrop pin marker icon in the given colour
    function createPinIcon(color) {
        return L.divIcon({
            className: 'site-pin',
            html: `<svg width="26" height="38" viewBox="0 0 26 38" xmlns="http://www.w3.org/2000/svg">
                <path d="M13 0C5.8 0 0 5.8 0 13c0 9.5 13 25 13 25s13-15.5 13-25C26 5.8 20.2 0 13 0z" fill="${color}" stroke="#fff" stroke-width="2"/>
                <circle cx="13" cy="13" r="4.5" fill="#fff"/>
            </svg>`,
            iconSize: [26, 38],
            iconAnchor: [13, 38],
            popupAnchor: [0, -34]
        });
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DOM loaded, initializing...');

        // Small delay to ensure DOM is fully ready
        setTimeout(() => {
            initializeMap();

            // Force map to invalidate size after a short delay
            setTimeout(() => {
                if (map) {
                    map.invalidateSize();
                    loadSiteMarkers();
                }
            }, 500);

            setupEventListeners();
        }, 100);
    });

    // Initialize Leaflet map
    function initializeMap() {
        try {
            // Center map on UK (can be configured per deployment)
            map = L.map('siteMap').setView([51.5074, -0.1278], 6); // London coordinates

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                maxZoom: 19,
                subdomains: ['a', 'b', 'c']
            }).addTo(map);

            // Map click handler
            map.on('click', handleMapClick);

            console.log('Map initialized successfully');
        } catch (error) {
            console.error('Error initializing map:', error);
        }
    }

    // Load site markers on the map
    function loadSiteMarkers() {
        console.log('Loading site markers...', sites);

        if (!sites || sites.length === 0) {
            console.log('No sites to display');
            updateGeofenceStatus(null, 'No sites available. Add a site to get started.');
            return;
        }

        let markersAdded = 0;
        let validCoordinates = [];

        sites.forEach(site => {
            console.log('Processing site:', site.name, 'Coordinates:', site.latitude, site.longitude);

            if (site.latitude && site.longitude && !isNaN(site.latitude) && !isNaN(site.longitude)) {
                addSiteMarker(site);
                markersAdded++;
                validCoordinates.push([parseFloat(site.latitude), parseFloat(site.longitude)]);
            } else {
                console.log('Site has invalid coordinates:', site.name);
            }
        });

        console.log(`Added ${markersAdded} site markers`);

        // If we have valid coordinates, fit the map to show all sites
        if (validCoordinates.length > 0) {
            if (validCoordinates.length === 1) {
                map.setView(validCoordinates[0], 15);
            } else {
                map.fitBounds(validCoordinates, { padding: [20, 20] });
            }
        }
    }

    // Add a site marker to the map
    function addSiteMarker(site) {
        try {
            const lat = parseFloat(site.latitude);
            const lng = parseFloat(site.longitude);

            if (isNaN(lat) || isNaN(lng)) {
                console.error('Invalid coordinates for site:', site.name);
                return;
            }

            const marker = L.marker([lat, lng], {
                title: site.name,
                icon: createPinIcon(PIN_DEFAULT)
            }).bindPopup(`
                <div style="text-align: center; min-width: 150px;">
                    <strong style="color: #12355B; font-size: 14px;">${site.name}</strong><br>
                    <div style="color: #6B7280; font-size: 12px; margin: 4px 0;">${site.address}</div>
                    <div style="color: #D4AF37; font-size: 11px;">Grace: ${site.grace_period_minutes}min</div>
                </div>
            `);

            marker.on('click', function() {
                selectSite(site.id);
            });

            siteMarkers[site.id] = marker;
            marker.addTo(map);

            console.log('Added marker for site:', site.name);
        } catch (error) {
            console.error('Error adding marker for site:', site.name, error);
        }
    }

    // Handle map click events
    function handleMapClick(e) {
        if (isDrawingMode) return;

        // Only capture coordinates while the Add/Edit drawer is open
        const drawerOpen = document.getElementById('siteDrawer').classList.contains('open');
        if (drawerOpen && (currentMode === 'add' || currentMode === 'edit')) {
            setCoordinates(e.latlng.lat, e.latlng.lng);
            showToast('Coordinates set from map');
        }
    }

    // Set coordinates in the form
    function setCoordinates(lat, lng) {
        document.getElementById('latitude').value = lat.toFixed(6);
        document.getElementById('longitude').value = lng.toFixed(6);
    }

    // Allow pasting a combined "lat, lng" string into either coordinate field
    function handleCoordInput(input) {
        const v = input.value;
        if (v.indexOf(',') !== -1) {
            const parts = v.split(',').map(s => s.trim());
            if (parts.length === 2 && parts[0] !== '' && parts[1] !== '') {
                document.getElementById('latitude').value = parts[0];
                document.getElementById('longitude').value = parts[1];
            }
        }
    }

    // Select a site and show its geofence
    function selectSite(siteId) {
        console.log('Selecting site:', siteId);

        // Clear previous selection
        document.querySelectorAll('.site-list-item').forEach(item => {
            item.classList.remove('selected');
        });

        // Select current site
        const siteItem = document.querySelector(`[data-site-id="${siteId}"]`);
        if (siteItem) {
            siteItem.classList.add('selected');
        }

        currentSiteId = siteId;
        const site = sites.find(s => s.id == siteId);

        console.log('Found site:', site);

        // Reset every pin to the default colour, then mark the selected one red
        Object.keys(siteMarkers).forEach(id => siteMarkers[id].setIcon(createPinIcon(PIN_DEFAULT)));
        if (siteMarkers[siteId]) {
            siteMarkers[siteId].setIcon(createPinIcon(PIN_SELECTED));
        }

        // Show the selected site name on the geofence card
        if (site) {
            document.getElementById('geofenceSiteName').textContent = site.name;
        }

        if (site) {
            // Smoothly fly to the site and centre it in the map preview
            if (site.latitude && site.longitude) {
                const lat = parseFloat(site.latitude);
                const lng = parseFloat(site.longitude);
                console.log('Centering map on:', lat, lng);
                // Make sure Leaflet knows the current container size before centring,
                // otherwise the marker can land off-centre on first selection.
                map.invalidateSize();
                map.flyTo([lat, lng], 17, { animate: true, duration: 0.8 });
                // Open the popup once the fly animation settles so it stays centred
                map.once('moveend', () => {
                    if (siteMarkers[siteId]) siteMarkers[siteId].openPopup();
                });
            } else if (siteMarkers[siteId]) {
                siteMarkers[siteId].openPopup();
            }

            // Load site's existing geofence (display only — does not delete anything)
            loadSiteGeofence(site);

            updateGeofenceStatus(site, `Selected: ${site.name} — pick a tool above to draw its geofence`);
        } else {
            console.error('Site not found:', siteId);
        }
    }

    // Load geofence for selected site (display only)
    function loadSiteGeofence(site) {
        removeGeofenceLayer();

        // Reset the radius box to the default; displayGeofence() overrides it with
        // the real value when the site has a saved circle.
        document.getElementById('radiusInput').value = 200;

        if (site.geofences && site.geofences.length > 0) {
            const activeGeofence = site.geofences.find(g => g.is_active) || site.geofences[0];
            if (activeGeofence && activeGeofence.coordinates) {
                displayGeofence(activeGeofence);
            }
        }
    }

    // Normalise the shape type across the various shapes a geofence object can take:
    // freshly drawn ones carry `type`, server-loaded ones carry `shape_type`.
    function geofenceType(g) {
        return g.type || g.shape_type || 'polygon';
    }

    // Average a polygon's vertices to recover the centre of a circle that was
    // stored as a polygon approximation. Ignores the duplicated closing vertex.
    function polygonCentroid(points) {
        let pts = points;
        if (pts.length > 1) {
            const first = pts[0], last = pts[pts.length - 1];
            if (first[0] === last[0] && first[1] === last[1]) pts = pts.slice(0, -1);
        }
        let lat = 0, lng = 0;
        pts.forEach(p => { lat += p[0]; lng += p[1]; });
        return [lat / pts.length, lng / pts.length];
    }

    // Display a geofence on the map. coordinates may arrive as an array (from the
    // server), a {center, radius} object (a freshly-drawn circle) or a JSON string.
    function displayGeofence(geofence) {
        try {
            let coords = geofence.coordinates;
            if (typeof coords === 'string') {
                coords = JSON.parse(coords);
            }
            if (!coords) return;

            const radiusInput = document.getElementById('radiusInput');

            if (geofenceType(geofence) === 'circle') {
                // Two circle shapes: a freshly-drawn one ({center, radius}) and a
                // server-loaded one (polygon array + a separate `radius`). Recover a
                // real L.Circle from either so the radius box stays accurate and live.
                let center = null, radius = null;
                if (!Array.isArray(coords) && coords.center && coords.radius) {
                    center = coords.center;
                    radius = coords.radius;
                } else if (Array.isArray(coords) && coords.length > 0) {
                    center = polygonCentroid(coords);
                    radius = geofence.radius;
                }

                if (center && radius) {
                    currentGeofence = L.circle(center, { radius: radius, ...GEO_STYLE }).addTo(map);
                    currentCircle = { center: center, radius: radius };
                    radiusInput.value = radius;
                    drawingTool = 'circle';
                    document.getElementById('radiusGroup').style.display = 'block';
                    return;
                }
            }

            // Polygon (or a circle we couldn't reconstruct) — draw the raw shape.
            if (Array.isArray(coords) && coords.length > 0) {
                currentGeofence = L.polygon(coords, GEO_STYLE).addTo(map);
            }
        } catch (e) {
            console.error('Error displaying geofence:', e);
        }
    }

    // Remove the geofence layer + any drawing state from the map ONLY.
    // Does NOT touch the server. Used when re-drawing or switching tools.
    function removeGeofenceLayer() {
        if (currentGeofence) {
            map.removeLayer(currentGeofence);
            currentGeofence = null;
        }
        // Forget any tracked circle. Note: updateCircleRadius() deliberately does
        // NOT call this function, so resizing keeps the tracked circle alive.
        currentCircle = null;
        isDrawingMode = false;
        map.off('click');
        map.doubleClickZoom.enable();
        map.on('click', handleMapClick);
    }

    // Reset both tool buttons back to their normal colour (drawing finished)
    function resetToolButtons() {
        document.getElementById('polygonTool').classList.remove('drawing');
        document.getElementById('circleTool').classList.remove('drawing');
    }

    // Activate polygon drawing tool
    function activatePolygonTool() {
        if (!requireSelectedSite()) return;
        drawingTool = 'polygon';
        document.getElementById('radiusGroup').style.display = 'none';

        // Turn this button green while drawing is in progress
        resetToolButtons();
        document.getElementById('polygonTool').classList.add('drawing');

        removeGeofenceLayer();
        enablePolygonDrawing();
    }

    // Activate circle drawing tool
    function activateCircleTool() {
        if (!requireSelectedSite()) return;
        drawingTool = 'circle';
        document.getElementById('radiusGroup').style.display = 'block';

        // Turn this button green while drawing is in progress
        resetToolButtons();
        document.getElementById('circleTool').classList.add('drawing');

        removeGeofenceLayer();
        enableCircleDrawing();
    }

    // Guard: a geofence always belongs to a site, so one must be selected first.
    function requireSelectedSite() {
        if (!currentSiteId) {
            showToast('Select a site first, then draw its geofence', 'error');
            return false;
        }
        return true;
    }

    // Enable polygon drawing — click to drop points, click the first (red) point
    // or double-click to close the shape.
    function enablePolygonDrawing() {
        isDrawingMode = true;
        map.off('click', handleMapClick);
        map.doubleClickZoom.disable();

        let points = [];
        let tempMarkers = [];
        let previewLine = null;

        function redraw() {
            if (previewLine) { map.removeLayer(previewLine); previewLine = null; }
            if (currentGeofence) { map.removeLayer(currentGeofence); currentGeofence = null; }
            if (points.length >= 3) {
                currentGeofence = L.polygon(points, GEO_STYLE).addTo(map);
            } else if (points.length >= 2) {
                previewLine = L.polyline(points, { color: '#D4AF37', weight: 2, dashArray: '4' }).addTo(map);
            }
        }

        function finish() {
            if (points.length < 3) {
                showToast('Add at least 3 points to form a shape', 'error');
                return;
            }
            tempMarkers.forEach(m => map.removeLayer(m));
            if (previewLine) { map.removeLayer(previewLine); previewLine = null; }
            isDrawingMode = false;
            map.off('click', onClick);
            map.off('dblclick', finish);
            map.doubleClickZoom.enable();
            map.on('click', handleMapClick);
            resetToolButtons();
            saveGeofence('polygon', points);
        }

        function onClick(e) {
            points.push([e.latlng.lat, e.latlng.lng]);
            const isFirst = points.length === 1;
            const size = isFirst ? 14 : 10;
            const color = isFirst ? PIN_SELECTED : '#D4AF37';

            const marker = L.marker([e.latlng.lat, e.latlng.lng], {
                icon: L.divIcon({
                    className: 'polygon-point',
                    html: `<div style="width:${size}px;height:${size}px;background:${color};border-radius:50%;border:2px solid #fff;box-shadow:0 0 0 1px rgba(0,0,0,.35);"></div>`,
                    iconSize: [16, 16],
                    iconAnchor: [8, 8]
                })
            }).addTo(map);

            if (isFirst) {
                marker.bindTooltip('Click here to close the shape', { direction: 'top', offset: [0, -8] });
                marker.on('click', function(ev) {
                    L.DomEvent.stop(ev);
                    finish();
                });
            }

            tempMarkers.push(marker);
            redraw();
        }

        map.on('click', onClick);
        map.on('dblclick', finish);
        updateGeofenceStatus(null, 'Click to add points — click the first (red) point or double-click to finish.');
    }

    // Enable circle drawing — one click drops the centre, radius comes from the
    // input box and can be tweaked live afterwards.
    function enableCircleDrawing() {
        isDrawingMode = true;
        map.off('click', handleMapClick);

        function onClick(e) {
            const radius = parseInt(document.getElementById('radiusInput').value, 10) || 200;

            if (currentGeofence) { map.removeLayer(currentGeofence); }
            const center = [e.latlng.lat, e.latlng.lng];
            currentGeofence = L.circle(center, { radius: radius, ...GEO_STYLE }).addTo(map);
            currentCircle = { center: center, radius: radius };

            isDrawingMode = false;
            map.off('click', onClick);
            map.on('click', handleMapClick);
            resetToolButtons();

            saveGeofence('circle', { center: center, radius: radius }, true);
            updateGeofenceStatus(null, 'Circle placed. Adjust the radius box to resize.');
        }

        map.on('click', onClick);
        updateGeofenceStatus(null, 'Click once on the map to drop the circle centre.');
    }

    // Update circle radius live. Resizes an already-placed circle and re-saves it.
    // We rebuild from the tracked centre because after a save the layer is
    // re-fetched from the server as a polygon (circles are stored as polygons),
    // so currentGeofence is no longer an L.Circle instance.
    function updateCircleRadius() {
        const radius = parseInt(document.getElementById('radiusInput').value, 10) || 200;

        // Prefer a live L.Circle if we still have one (before the server refresh)
        let center = null;
        if (currentGeofence && currentGeofence instanceof L.Circle) {
            center = currentGeofence.getLatLng();
            center = [center.lat, center.lng];
        } else if (currentCircle) {
            center = currentCircle.center;
        }

        if (!center) return; // no circle placed yet — nothing to resize

        // Redraw the circle immediately at the new radius for instant feedback
        if (currentGeofence) { map.removeLayer(currentGeofence); }
        currentGeofence = L.circle(center, { radius: radius, ...GEO_STYLE }).addTo(map);
        currentCircle = { center: center, radius: radius };

        // Debounce the server save so typing/holding the spinner doesn't fire a
        // POST per keystroke — persist once the user pauses.
        clearTimeout(radiusSaveTimer);
        radiusSaveTimer = setTimeout(() => {
            saveGeofence('circle', { center: center, radius: radius }, true);
        }, 400);
    }
    let radiusSaveTimer = null;

    // Clear button — asks the server to delete the geofence. The visual is only
    // removed once the server confirms; if the geofence is locked (e.g. an ongoing
    // shift uses it) the server refuses and we surface why, leaving it on the map.
    function clearGeofence() {
        if (!currentSiteId) {
            updateGeofenceStatus(null, 'Select a site first, then clear its geofence');
            return;
        }

        const site = sites.find(s => s.id == currentSiteId);
        const hasSaved = site && site.geofences && site.geofences.length > 0;
        if (!hasSaved && !currentGeofence) {
            updateGeofenceStatus(null, 'No geofence to clear for this site');
            return;
        }

        updateGeofenceStatus(null, 'Clearing geofence…');
        deleteGeofence();
    }

    // Re-fetch this site's geofence from the server and redraw ONLY the map layer
    // (no full page reload). Also keeps the in-memory `sites` array in sync so
    // re-selecting the site later shows the correct, server-stored geometry.
    function refreshSiteGeofence(siteId) {
        if (!siteId) return;

        fetch(`/admin/sites/${siteId}/geofences`, {
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') }
        })
        .then(r => r.json())
        .then(data => {
            const geofences = (data && data.success && data.geofences) ? data.geofences : [];

            // Sync in-memory data
            const site = sites.find(s => s.id == siteId);
            if (site) site.geofences = geofences;

            // Only redraw if this site is still the selected one
            if (currentSiteId !== siteId) return;

            removeGeofenceLayer();
            if (geofences.length > 0) {
                const active = geofences.find(g => g.is_active) || geofences[0];
                if (active && active.coordinates) {
                    displayGeofence(active);
                }
            }
            updateGeofenceStatus(site);
        })
        .catch(err => console.error('Error refreshing geofence:', err));
    }

    // Save geofence to server.
    // skipRefresh: when true we keep the current map layer instead of re-fetching
    // the server geometry. Used for circles so the live L.circle (and its radius)
    // isn't replaced by the stored polygon approximation, which would break live
    // radius resizing.
    function saveGeofence(type, coordinates, skipRefresh = false) {
        if (!currentSiteId) return;

        const data = {
            site_id: currentSiteId,
            type: type,
            coordinates: coordinates,
            is_active: true
        };

        fetch('/admin/geofences', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateGeofenceStatus(null, `${type} geofence saved`);
                // Keep in-memory data fresh, but only re-fetch/redraw the geometry
                // when we're not preserving a live layer (e.g. an active circle).
                if (skipRefresh) {
                    const site = sites.find(s => s.id == currentSiteId);
                    if (site) site.geofences = [{ is_active: true, type: type, coordinates: coordinates }];
                } else {
                    refreshSiteGeofence(currentSiteId);
                }
            } else {
                showToast('Error saving geofence', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Error saving geofence', 'error');
        });
    }

    // Delete geofence from server. Only mutates the map/state on a confirmed
    // success; on failure (e.g. 422 — geofence locked by an ongoing shift) it keeps
    // the geofence visible and shows the server's reason.
    function deleteGeofence() {
        if (!currentSiteId) return;

        const siteId = currentSiteId;
        const clearBtn = document.getElementById('clearBtn');

        fetch(`/admin/geofences/site/${siteId}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        })
        .then(response => response.json().then(data => ({ ok: response.ok, data })))
        .then(({ ok, data }) => {
            if (ok && data.success) {
                // Now it's safe to wipe the visual + in-memory state (no reload)
                removeGeofenceLayer();
                resetToolButtons();
                const site = sites.find(s => s.id == siteId);
                if (site) site.geofences = [];
                updateGeofenceStatus(null, 'Geofence cleared');

                if (clearBtn) {
                    clearBtn.textContent = '✓ Cleared';
                    clearBtn.classList.add('drawing'); // reuse green confirmation colour
                    setTimeout(() => {
                        clearBtn.textContent = 'Clear';
                        clearBtn.classList.remove('drawing');
                    }, 2000);
                }
            } else {
                // Geofence stays on the map — surface why it couldn't be cleared.
                const msg = (data && data.error) || "Can't clear the geofence right now. Please try again.";
                updateGeofenceStatus(null, msg);
                showToast(msg, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            const msg = "Can't clear the geofence right now. Please try again.";
            updateGeofenceStatus(null, msg);
            showToast(msg, 'error');
        });
    }

    // Update geofence status display
    function updateGeofenceStatus(site, customMessage = null) {
        const statusElement = document.getElementById('geofenceStatus');

        if (customMessage) {
            statusElement.textContent = customMessage;
            return;
        }

        if (!site) {
            statusElement.textContent = 'Select a site to view or edit its geofence';
            return;
        }

        if (site.geofences && site.geofences.length > 0) {
            const activeGeofence = site.geofences.find(g => g.is_active);
            if (activeGeofence) {
                statusElement.textContent = `Active geofence: ${activeGeofence.type} (${activeGeofence.name || 'Default'})`;
            } else {
                statusElement.textContent = `${site.geofences.length} geofence(s) - none active`;
            }
        } else {
            statusElement.textContent = 'No geofence defined - click above to draw one';
        }
    }

    // Site drawer management
    function openSiteDrawer(mode, siteId = null) {
        currentMode = mode;
        currentSiteId = siteId;

        const drawer = document.getElementById('siteDrawer');
        const overlay = document.querySelector('.drawer-overlay');
        const title = document.getElementById('drawerTitle');

        // Set title based on mode
        if (mode === 'add') {
            title.textContent = 'Add Site';
            clearSiteForm();
        } else if (mode === 'edit') {
            title.textContent = 'Edit Site';
            loadSiteData(siteId);
        }

        drawer.classList.add('open');
        overlay.classList.add('show');
    }

    function closeSiteDrawer() {
        const drawer = document.getElementById('siteDrawer');
        const overlay = document.querySelector('.drawer-overlay');

        drawer.classList.remove('open');
        overlay.classList.remove('show');

        clearSiteForm();
        currentMode = 'add';
        currentSiteId = null;
    }

    // Clear site form
    function clearSiteForm() {
        document.getElementById('siteForm').reset();
        document.getElementById('siteId').value = '';
        document.getElementById('grace_period_minutes').value = '5';
    }

    // Load site data into form
    function loadSiteData(siteId) {
        const site = sites.find(s => s.id == siteId);
        if (!site) return;

        document.getElementById('siteId').value = site.id;
        document.getElementById('name').value = site.name;
        document.getElementById('address').value = site.address;
        document.getElementById('latitude').value = site.latitude || '';
        document.getElementById('longitude').value = site.longitude || '';
        document.getElementById('grace_period_minutes').value = site.grace_period_minutes;
        document.getElementById('contact_person').value = site.contact_person || '';
        document.getElementById('contact_phone').value = site.contact_phone || '';
        document.getElementById('status').value = site.status;
    }

    // Save site
    function saveSite(event) {
        event.preventDefault();

        const formData = new FormData(document.getElementById('siteForm'));
        const url = currentMode === 'edit' ? `/admin/sites/${currentSiteId}` : '/admin/sites';
        const method = currentMode === 'edit' ? 'PUT' : 'POST';

        // Convert FormData to JSON
        const data = {};
        for (let [key, value] of formData.entries()) {
            data[key] = value;
        }

        fetch(url, {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(`Site ${currentMode === 'edit' ? 'updated' : 'created'} successfully!`);
                closeSiteDrawer();
                // Reload page to update site list
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast('Error saving site', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Error saving site', 'error');
        });
    }

    // Show toast notification
    function showToast(message, type = 'success') {
        const toast = document.getElementById('successToast');
        toast.textContent = message;
        toast.style.background = type === 'error' ? 'var(--critical-red)' : 'var(--success-green)';
        toast.classList.add('show');

        setTimeout(() => {
            toast.classList.remove('show');
        }, 3000);
    }

    // Setup event listeners
    function setupEventListeners() {
        // Default UI — no tool highlighted and no draw mode until a site is
        // selected and a tool is clicked (buttons only turn green while drawing).
        drawingTool = 'circle';
        resetToolButtons();
        document.getElementById('radiusGroup').style.display = 'block';
    }

    // Expose functions globally
    window.selectSite = selectSite;
    window.openSiteDrawer = openSiteDrawer;
    window.closeSiteDrawer = closeSiteDrawer;
    window.activatePolygonTool = activatePolygonTool;
    window.activateCircleTool = activateCircleTool;
    window.updateCircleRadius = updateCircleRadius;
    window.clearGeofence = clearGeofence;
    window.saveSite = saveSite;
    window.handleCoordInput = handleCoordInput;
</script>
@endsection
