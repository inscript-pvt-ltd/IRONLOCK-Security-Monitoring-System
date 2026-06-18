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
    }

    /* ── MapLibre popup ─────────────────────────────────────────────────── */
    /* !important required — MapLibre injects its own stylesheet at high specificity */
    .maplibregl-popup-content {
        background: #0f1929 !important;
        border: none !important;
        border-radius: 10px !important;
        box-shadow: 0 16px 48px rgba(0,0,0,.85), 0 0 0 1px rgba(212,175,55,0.18) !important;
        padding: 0 !important;
        overflow: hidden !important;
        min-width: 200px !important;
        font-family: inherit !important;
    }
    /* tip arrow — CSS triangles need 3 transparent borders and 1 coloured one.
       Target each anchor position so only the correct border gets the colour. */
    .maplibregl-popup-anchor-bottom .maplibregl-popup-tip,
    .maplibregl-popup-anchor-bottom-left .maplibregl-popup-tip,
    .maplibregl-popup-anchor-bottom-right .maplibregl-popup-tip {
        border-top-color: #0f1929 !important;
        border-bottom-color: transparent !important;
        border-left-color:   transparent !important;
        border-right-color:  transparent !important;
    }
    .maplibregl-popup-anchor-top .maplibregl-popup-tip,
    .maplibregl-popup-anchor-top-left .maplibregl-popup-tip,
    .maplibregl-popup-anchor-top-right .maplibregl-popup-tip {
        border-top-color:    transparent !important;
        border-bottom-color: #162040 !important;
        border-left-color:   transparent !important;
        border-right-color:  transparent !important;
    }
    .maplibregl-popup-anchor-left .maplibregl-popup-tip {
        border-top-color:    transparent !important;
        border-bottom-color: transparent !important;
        border-left-color:   transparent !important;
        border-right-color:  #162040 !important;
    }
    .maplibregl-popup-anchor-right .maplibregl-popup-tip {
        border-top-color:    transparent !important;
        border-bottom-color: transparent !important;
        border-left-color:   #162040 !important;
        border-right-color:  transparent !important;
    }
    /* card layout */
    .site-popup-header {
        background: linear-gradient(135deg, #162040 0%, #0f1929 100%);
        border-bottom: 2px solid #D4AF37;
        padding: 10px 14px 9px;
    }
    .site-popup-name {
        font-size: 13px; font-weight: 700; color: #D4AF37;
        letter-spacing: 0.3px; line-height: 1.2;
    }
    .site-popup-body   { padding: 10px 14px 12px; }
    .site-popup-address {
        font-size: 10px; color: #94a3b8; line-height: 1.5; margin-bottom: 9px;
    }
    .site-popup-footer { display: flex; align-items: center; }
    .site-popup-badge  {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 3px 9px;
        background: rgba(212,175,55,0.1); border: 1px solid rgba(212,175,55,0.3);
        border-radius: 20px; font-size: 9px; font-weight: 600;
        color: #D4AF37; letter-spacing: 0.4px;
    }
    /* ── Pick-from-map button (in form) ────────────────────────────────── */
    .btn-pick-map {
        width: 100%;
        margin-top: 8px;
        padding: 9px 12px;
        background: transparent;
        border: 1.5px dashed rgba(212, 175, 55, 0.35);
        border-radius: 6px;
        color: var(--text-muted);
        font-size: 10px;
        font-weight: 500;
        letter-spacing: 0.4px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        transition: all 0.2s ease;
    }
    .btn-pick-map:hover {
        border-color: #D4AF37;
        border-style: solid;
        color: #D4AF37;
        background: rgba(212, 175, 55, 0.06);
    }
    .btn-pick-map.active {
        border-color: #D4AF37;
        border-style: solid;
        color: #D4AF37;
        background: rgba(212, 175, 55, 0.08);
    }

    /* ── Map pick-mode banner (overlaid on the map, contains geocoder) ─── */
    #mapPickBanner {
        display: none;
        position: absolute;
        top: 16px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 1001;
        background: rgba(11, 18, 32, 0.96);
        border: 1px solid rgba(212, 175, 55, 0.38);
        border-radius: 10px;
        width: 360px;
        box-shadow: 0 10px 32px rgba(0,0,0,.6);
        flex-direction: column;
        cursor: default;
        overflow: visible; /* dropdown escapes the banner */
    }
    #mapPickBanner.visible { display: flex; }

    .pick-banner-row {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 14px 8px;
        border-bottom: 1px solid rgba(255,255,255,0.05);
    }
    .pick-banner-dot {
        width: 9px; height: 9px; flex-shrink: 0;
        background: #D4AF37; border-radius: 50%;
        box-shadow: 0 0 0 3px rgba(212,175,55,0.22);
        animation: pickPulse 1.2s ease-in-out infinite;
    }
    @keyframes pickPulse {
        0%, 100% { box-shadow: 0 0 0 3px rgba(212,175,55,0.22); }
        50%       { box-shadow: 0 0 0 7px rgba(212,175,55,0.07); }
    }
    .pick-banner-label {
        flex: 1; font-size: 10px; font-weight: 500;
        color: var(--text-secondary); letter-spacing: 0.2px;
    }
    .pick-banner-cancel {
        padding: 3px 9px;
        background: rgba(255,255,255,0.05);
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 4px;
        color: var(--text-muted);
        font-size: 10px; cursor: pointer;
        transition: all 0.15s ease; white-space: nowrap;
    }
    .pick-banner-cancel:hover { background: rgba(255,255,255,0.1); color: var(--text-primary); }

    /* geocoder search inside the banner */
    .pick-search-wrap  { padding: 10px 12px; }
    .pick-search-field { position: relative; } /* icon anchors to this, not the whole wrap */
    .pick-search-input {
        width: 100%; box-sizing: border-box;
        padding: 8px 10px 8px 32px;
        background: rgba(255,255,255,0.06);
        border: 1px solid rgba(212,175,55,0.22);
        border-radius: 6px;
        color: var(--text-primary);
        font-size: 11px; outline: none;
        transition: border-color 0.2s;
    }
    .pick-search-input:focus { border-color: rgba(212,175,55,0.55); }
    .pick-search-input::placeholder { color: rgba(255,255,255,0.28); }
    .pick-search-icon {
        position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
        width: 14px; height: 14px; color: rgba(212,175,55,0.55);
        pointer-events: none;
    }
    .pick-search-results {
        background: #0d1929;
        border: 1px solid rgba(212,175,55,0.25);
        border-top: none;
        border-radius: 0 0 8px 8px;
        z-index: 1002;
        max-height: 210px; overflow-y: auto;
        box-shadow: 0 8px 20px rgba(0,0,0,.5);
    }
    .pick-search-results:empty { display: none; }
    .pick-result-item {
        padding: 8px 12px; cursor: pointer;
        border-bottom: 1px solid rgba(255,255,255,0.04);
        transition: background 0.15s;
    }
    .pick-result-item:last-child { border-bottom: none; }
    .pick-result-item:hover { background: rgba(212,175,55,0.08); }
    .pick-result-name {
        display: block; font-size: 11px; font-weight: 500;
        color: var(--text-primary); margin-bottom: 2px;
    }
    .pick-result-detail { display: block; font-size: 9px; color: var(--text-muted); }
    .pick-result-empty  {
        padding: 10px 12px; font-size: 10px;
        color: var(--text-muted); text-align: center; cursor: default;
    }

    /* attribution */
    .maplibregl-ctrl-attrib { font-size: 9px; }

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

<!-- MapLibre GL JS -->
<link rel="stylesheet" href="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.css" />
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

            <!-- Pick-from-map banner with geocoder search -->
            <div id="mapPickBanner">
                <div class="pick-banner-row">
                    <span class="pick-banner-dot"></span>
                    <span class="pick-banner-label">Search a location or click the map to pin it</span>
                    <button type="button" class="pick-banner-cancel" onclick="cancelMapPick()">✕ Cancel</button>
                </div>
                <div class="pick-search-wrap">
                    <div class="pick-search-field">
                        <svg class="pick-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                        <input type="text" id="pickSearchInput" class="pick-search-input"
                               placeholder="Search address, city, postcode…"
                               autocomplete="off" spellcheck="false"
                               oninput="handlePickSearch(this.value)">
                    </div>
                    <div id="pickSearchResults"></div>
                </div>
            </div>

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
            <!-- Pick from map -->
            <button type="button" class="btn-pick-map" id="pickMapBtn" onclick="startMapPick()">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="10" r="3"/><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/>
                </svg>
                Pick Location on Map
            </button>
            <div style="font-size: 10px; color: var(--text-muted); margin-top: 5px; text-align:center;">
                or paste "lat, lng" into either field above
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
<!-- MapLibre GL JS -->
<script src="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.js"></script>

<script>
    // ── Globals ────────────────────────────────────────────────────────────────
    let map;
    let sites = @json($sites->items());
    let currentSiteId = null;
    let currentMode   = 'add';
    let siteMarkers   = {};   // id -> maplibregl.Marker
    let currentCircle = null; // { center:[lat,lng], radius }
    let drawingTool   = 'circle';
    let isDrawingMode   = false;
    let radiusSaveTimer = null;
    let pickingLocation = false; // true while user is in pick-from-map mode
    let pickMarker      = null;  // draggable gold dot placed after picking
    let searchTimer     = null;  // debounce handle for Nominatim geocoder
    let highlightTimer  = null;  // auto-clear handle for bbox highlight

    const GEO_COLOR   = '#D4AF37';
    const PIN_DEFAULT = '#12355B';
    const PIN_SELECTED = '#E63946';

    // ── Helpers ────────────────────────────────────────────────────────────────

    // Create a teardrop SVG pin as an HTML element (used by maplibregl.Marker)
    function makePinEl(color) {
        const el = document.createElement('div');
        el.style.cssText = 'width:26px;height:38px;overflow:visible;cursor:pointer;';
        el.innerHTML = `<svg width="26" height="38" viewBox="0 0 26 38" xmlns="http://www.w3.org/2000/svg" style="display:block;overflow:visible;">
            <path d="M13 0C5.8 0 0 5.8 0 13c0 9.5 13 25 13 25s13-15.5 13-25C26 5.8 20.2 0 13 0z"
                  fill="${color}" stroke="#fff" stroke-width="2"/>
            <circle cx="13" cy="13" r="4.5" fill="#fff"/>
        </svg>`;
        return el;
    }

    // Approximate a geographic circle as a GeoJSON polygon ring.
    // center = [lat, lng] (backend format); returns [[lng,lat], …] (GeoJSON format).
    function circleRing(center, radiusM, n = 64) {
        const R  = 6371000;
        const φ1 = center[0] * Math.PI / 180;
        const λ1 = center[1] * Math.PI / 180;
        const d  = radiusM / R;
        const ring = [];
        for (let i = 0; i <= n; i++) {
            const θ = (i * 2 * Math.PI) / n;
            const φ2 = Math.asin(Math.sin(φ1)*Math.cos(d) + Math.cos(φ1)*Math.sin(d)*Math.cos(θ));
            const λ2 = λ1 + Math.atan2(Math.sin(θ)*Math.sin(d)*Math.cos(φ1), Math.cos(d)-Math.sin(φ1)*Math.sin(φ2));
            ring.push([λ2*180/Math.PI, φ2*180/Math.PI]); // [lng, lat]
        }
        return ring;
    }

    function emptyFC() {
        return { type: 'FeatureCollection', features: [] };
    }

    // Push data into a named GeoJSON source (no-op if source not ready)
    function setSource(id, geojson) {
        const src = map && map.getSource(id);
        if (src) src.setData(geojson);
    }

    // ── Map init ───────────────────────────────────────────────────────────────

    function initializeMap() {
        map = new maplibregl.Map({
            container: 'siteMap',
            // CARTO Dark Matter GL vector style — same visual as the raster tiles
            // but rendered via WebGL so pitch + rotate work natively.
            style: 'https://basemaps.cartocdn.com/gl/dark-matter-gl-style/style.json',
            center: [-0.1278, 51.5074], // [lng, lat]
            zoom: 6,
            pitchWithRotate: true,
            dragRotate: true
        });

        map.on('load', () => {
            // Saved geofence layer
            map.addSource('geofence', { type: 'geojson', data: emptyFC() });
            map.addLayer({ id: 'geofence-fill', type: 'fill', source: 'geofence',
                paint: { 'fill-color': GEO_COLOR, 'fill-opacity': 0.2 } });
            map.addLayer({ id: 'geofence-line', type: 'line', source: 'geofence',
                paint: { 'line-color': GEO_COLOR, 'line-width': 2 } });

            // Live drawing preview layer (dashed outline only)
            map.addSource('preview', { type: 'geojson', data: emptyFC() });
            map.addLayer({ id: 'preview-fill', type: 'fill', source: 'preview',
                paint: { 'fill-color': GEO_COLOR, 'fill-opacity': 0.08 } });
            map.addLayer({ id: 'preview-line', type: 'line', source: 'preview',
                paint: { 'line-color': GEO_COLOR, 'line-width': 1.5, 'line-dasharray': [4, 3] } });

            // Geocoder search-result bounding-box highlight (auto-fades)
            map.addSource('search-highlight', { type: 'geojson', data: emptyFC() });
            map.addLayer({ id: 'search-highlight-fill', type: 'fill', source: 'search-highlight',
                paint: { 'fill-color': GEO_COLOR, 'fill-opacity': 0.12 } });
            map.addLayer({ id: 'search-highlight-line', type: 'line', source: 'search-highlight',
                paint: { 'line-color': GEO_COLOR, 'line-width': 1.5, 'line-dasharray': [3, 2] } });

            map.on('click', handleMapClick);
            loadSiteMarkers();
        });
    }

    // ── Markers ────────────────────────────────────────────────────────────────

    function loadSiteMarkers() {
        if (!sites || !sites.length) {
            updateGeofenceStatus(null, 'No sites available. Add a site to get started.');
            return;
        }
        const lnglats = [];
        sites.forEach(site => {
            const lat = parseFloat(site.latitude), lng = parseFloat(site.longitude);
            if (!isNaN(lat) && !isNaN(lng)) {
                addSiteMarker(site);
                lnglats.push([lng, lat]);
            }
        });
        if (lnglats.length === 1) {
            map.flyTo({ center: lnglats[0], zoom: 15 });
        } else if (lnglats.length > 1) {
            const bounds = lnglats.reduce(
                (b, c) => b.extend(c),
                new maplibregl.LngLatBounds(lnglats[0], lnglats[0])
            );
            map.fitBounds(bounds, { padding: 40 });
        }
    }

    function addSiteMarker(site) {
        const lat = parseFloat(site.latitude), lng = parseFloat(site.longitude);
        if (isNaN(lat) || isNaN(lng)) return;

        const el = makePinEl(PIN_DEFAULT);
        const popup = new maplibregl.Popup({ offset: 34, closeButton: false, maxWidth: '260px' })
            .setHTML(`
                <div class="site-popup-header">
                    <div class="site-popup-name">${site.name}</div>
                </div>
                <div class="site-popup-body">
                    <div class="site-popup-address">${site.address}</div>
                    <div class="site-popup-footer">
                        <span class="site-popup-badge">&#9711; ${site.grace_period_minutes} min grace period</span>
                    </div>
                </div>
            `);

        const marker = new maplibregl.Marker({ element: el, anchor: 'bottom' })
            .setLngLat([lng, lat])
            .setPopup(popup)
            .addTo(map);

        el.addEventListener('click', e => { e.stopPropagation(); selectSite(site.id); });
        siteMarkers[site.id] = marker;
    }

    // ── Map click (coord capture for drawer) ──────────────────────────────────

    function handleMapClick(e) {
        if (isDrawingMode) return;

        // Pick-from-map mode takes priority
        if (pickingLocation) {
            if (e.originalEvent.button !== 0) return;
            endMapPick(e.lngLat.lat, e.lngLat.lng);
            return;
        }

        // Fallback: auto-set coords when the drawer is already open
        const drawerOpen = document.getElementById('siteDrawer').classList.contains('open');
        if (drawerOpen && (currentMode === 'add' || currentMode === 'edit')) {
            setCoordinates(e.lngLat.lat, e.lngLat.lng);
            showToast('Coordinates set from map');
        }
    }

    function setCoordinates(lat, lng) {
        document.getElementById('latitude').value  = lat.toFixed(6);
        document.getElementById('longitude').value = lng.toFixed(6);
    }

    // ── Pick-from-map ─────────────────────────────────────────────────────────

    // Slide the drawer out and enter crosshair pick mode
    function startMapPick() {
        if (isDrawingMode) return;
        pickingLocation = true;
        document.getElementById('siteDrawer').classList.remove('open');
        document.querySelector('.drawer-overlay').classList.remove('show');
        document.getElementById('mapPickBanner').classList.add('visible');
        map.getCanvas().style.cursor = 'crosshair';
        updateGeofenceStatus(null, 'Click anywhere on the map to set the site location');
    }

    // Called on map click while picking — sets coords and reopens drawer
    function endMapPick(lat, lng) {
        pickingLocation = false;
        map.getCanvas().style.cursor = '';
        document.getElementById('mapPickBanner').classList.remove('visible');
        clearPickSearch();
        setCoordinates(lat, lng);
        placeTempPickMarker(lat, lng);
        document.getElementById('siteDrawer').classList.add('open');
        document.querySelector('.drawer-overlay').classList.add('show');
        // Mark the button as "active" (location set) with an update label
        const btn = document.getElementById('pickMapBtn');
        if (btn) {
            btn.querySelector('svg + text, :last-child') // update just text
            btn.innerHTML = btn.innerHTML.replace('Pick Location on Map', 'Change Location on Map');
            btn.classList.add('active');
        }
        updateGeofenceStatus(null, 'Location pinned — drag the marker to fine-tune, then save');
        showToast('Location set from map');
    }

    // Esc / cancel button — abort without changing coords
    function cancelMapPick() {
        pickingLocation = false;
        map.getCanvas().style.cursor = '';
        document.getElementById('mapPickBanner').classList.remove('visible');
        clearPickSearch();
        document.getElementById('siteDrawer').classList.add('open');
        document.querySelector('.drawer-overlay').classList.add('show');
        updateGeofenceStatus(null, 'Pick cancelled — select a site or open the form to continue');
    }

    // Drop a draggable gold dot at the picked location.
    // Dragging it live-updates the lat/lng inputs.
    function placeTempPickMarker(lat, lng) {
        if (pickMarker) pickMarker.remove();
        const el = document.createElement('div');
        el.style.cssText = [
            'width:18px', 'height:18px',
            'background:#D4AF37',
            'border:3px solid #fff',
            'border-radius:50%',
            'box-shadow:0 0 0 2px rgba(212,175,55,.35), 0 4px 12px rgba(0,0,0,.55)',
            'cursor:grab'
        ].join(';');
        pickMarker = new maplibregl.Marker({ element: el, anchor: 'center', draggable: true })
            .setLngLat([lng, lat])
            .addTo(map);
        pickMarker.on('dragstart', () => { el.style.cursor = 'grabbing'; });
        pickMarker.on('drag', () => {
            const p = pickMarker.getLngLat();
            setCoordinates(p.lat, p.lng);
        });
        pickMarker.on('dragend', () => {
            el.style.cursor = 'grab';
            const p = pickMarker.getLngLat();
            setCoordinates(p.lat, p.lng);
        });
    }

    // ── Geocoder (Nominatim) ──────────────────────────────────────────────────

    // Debounced search — fires after the user stops typing for 350 ms
    function handlePickSearch(query) {
        clearTimeout(searchTimer);
        const resultsEl = document.getElementById('pickSearchResults');
        if (!query || query.length < 3) { resultsEl.innerHTML = ''; return; }
        resultsEl.innerHTML = '<div class="pick-result-empty">Searching…</div>';
        searchTimer = setTimeout(() => {
            fetch(
                `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&limit=6&addressdetails=0`,
                { headers: { 'Accept-Language': 'en' } }
            )
            .then(r => r.json())
            .then(results => {
                window._geoResults = results;
                if (!results.length) {
                    resultsEl.innerHTML = '<div class="pick-result-empty">No results found</div>';
                    return;
                }
                resultsEl.innerHTML = results.map((r, i) => {
                    const parts  = r.display_name.split(',');
                    const name   = parts.slice(0, 2).join(',').trim();
                    const detail = parts.slice(2, 5).join(',').trim();
                    return `<div class="pick-result-item" onclick="selectPickResult(${i})">
                        <span class="pick-result-name">${name}</span>
                        <span class="pick-result-detail">${detail}</span>
                    </div>`;
                }).join('');
            })
            .catch(() => {
                resultsEl.innerHTML = '<div class="pick-result-empty">Search failed — check your connection</div>';
            });
        }, 350);
    }

    // User clicked a search result — fly to area, highlight bbox, pin centroid
    function selectPickResult(index) {
        const result = window._geoResults && window._geoResults[index];
        if (!result) return;

        const lat = parseFloat(result.lat);
        const lng = parseFloat(result.lon);

        // Clear dropdown, update input to the selected name
        document.getElementById('pickSearchResults').innerHTML = '';
        document.getElementById('pickSearchInput').value =
            result.display_name.split(',').slice(0, 2).join(',').trim();

        // Fly to bounding box (or centroid for points)
        if (result.boundingbox) {
            const [s, n, w, e] = result.boundingbox.map(parseFloat);
            map.fitBounds([[w, s], [e, n]], { padding: 80, maxZoom: 17, duration: 800 });
            highlightSearchBBox(s, n, w, e);
        } else {
            map.flyTo({ center: [lng, lat], zoom: 16, duration: 800 });
        }

        // Set coords + drop draggable pin at the centroid
        setCoordinates(lat, lng);
        placeTempPickMarker(lat, lng);

        // Close pick mode and reopen drawer
        pickingLocation = false;
        map.getCanvas().style.cursor = '';
        document.getElementById('mapPickBanner').classList.remove('visible');
        document.getElementById('siteDrawer').classList.add('open');
        document.querySelector('.drawer-overlay').classList.add('show');

        const btn = document.getElementById('pickMapBtn');
        if (btn) {
            btn.innerHTML = btn.innerHTML.replace('Pick Location on Map', 'Change Location on Map');
            btn.classList.add('active');
        }
        showToast('Location set — drag the pin to fine-tune');
        updateGeofenceStatus(null, 'Location pinned — drag the marker on the map to fine-tune');
    }

    // Flash the search result bounding box as a gold highlight, then fade it out
    function highlightSearchBBox(s, n, w, e) {
        const src = map.getSource('search-highlight');
        if (!src) return;
        src.setData({
            type: 'Feature',
            geometry: { type: 'Polygon', coordinates: [[[w,s],[e,s],[e,n],[w,n],[w,s]]] }
        });
        clearTimeout(highlightTimer);
        highlightTimer = setTimeout(() => {
            if (map.getSource('search-highlight')) map.getSource('search-highlight').setData(emptyFC());
        }, 2500);
    }

    function clearPickSearch() {
        clearTimeout(searchTimer);
        const inp = document.getElementById('pickSearchInput');
        const res = document.getElementById('pickSearchResults');
        if (inp) inp.value = '';
        if (res) res.innerHTML = '';
        if (map.getSource('search-highlight')) {
            clearTimeout(highlightTimer);
            map.getSource('search-highlight').setData(emptyFC());
        }
    }

    function handleCoordInput(input) {
        const v = input.value;
        if (v.includes(',')) {
            const parts = v.split(',').map(s => s.trim());
            if (parts.length === 2 && parts[0] && parts[1]) {
                document.getElementById('latitude').value  = parts[0];
                document.getElementById('longitude').value = parts[1];
            }
        }
    }

    // ── Site selection ────────────────────────────────────────────────────────

    function selectSite(siteId) {
        document.querySelectorAll('.site-list-item').forEach(el => el.classList.remove('selected'));
        const listEl = document.querySelector(`[data-site-id="${siteId}"]`);
        if (listEl) listEl.classList.add('selected');

        currentSiteId = siteId;
        const site = sites.find(s => s.id == siteId);

        // Recolour all pins; highlight the selected one
        Object.keys(siteMarkers).forEach(id => {
            siteMarkers[id].getElement().innerHTML = makePinEl(PIN_DEFAULT).innerHTML;
        });
        if (siteMarkers[siteId]) {
            siteMarkers[siteId].getElement().innerHTML = makePinEl(PIN_SELECTED).innerHTML;
        }

        if (!site) return;

        document.getElementById('geofenceSiteName').textContent = site.name;

        if (site.latitude && site.longitude) {
            const lat = parseFloat(site.latitude), lng = parseFloat(site.longitude);
            map.flyTo({ center: [lng, lat], zoom: 17, duration: 800 });
            map.once('moveend', () => {
                if (siteMarkers[siteId]) siteMarkers[siteId].togglePopup();
            });
        }

        loadSiteGeofence(site);
        updateGeofenceStatus(site, `Selected: ${site.name} — pick a tool above to draw its geofence`);
    }

    // ── Geofence display ──────────────────────────────────────────────────────

    function loadSiteGeofence(site) {
        clearGeofenceLayer();
        document.getElementById('radiusInput').value = 200;
        if (site.geofences && site.geofences.length > 0) {
            const active = site.geofences.find(g => g.is_active) || site.geofences[0];
            if (active && active.coordinates) displayGeofence(active);
        }
    }

    function geofenceType(g) { return g.type || g.shape_type || 'polygon'; }

    function polygonCentroid(pts) {
        if (pts.length > 1) {
            const f = pts[0], l = pts[pts.length-1];
            if (f[0]===l[0] && f[1]===l[1]) pts = pts.slice(0, -1);
        }
        let lat = 0, lng = 0;
        pts.forEach(p => { lat += p[0]; lng += p[1]; });
        return [lat/pts.length, lng/pts.length];
    }

    // coords from server: [lat,lng] arrays. MapLibre/GeoJSON needs [lng,lat].
    function displayGeofence(geofence) {
        try {
            let coords = geofence.coordinates;
            if (typeof coords === 'string') coords = JSON.parse(coords);
            if (!coords) return;

            const radiusInput = document.getElementById('radiusInput');

            if (geofenceType(geofence) === 'circle') {
                let center = null, radius = null;
                if (!Array.isArray(coords) && coords.center && coords.radius) {
                    center = coords.center; radius = coords.radius;
                } else if (Array.isArray(coords) && coords.length > 0) {
                    center = polygonCentroid(coords); radius = geofence.radius;
                }
                if (center && radius) {
                    setSource('geofence', {
                        type: 'Feature',
                        geometry: { type: 'Polygon', coordinates: [circleRing(center, radius)] }
                    });
                    currentCircle = { center, radius };
                    radiusInput.value = radius;
                    drawingTool = 'circle';
                    document.getElementById('radiusGroup').style.display = 'block';
                    return;
                }
            }

            // Polygon — convert [lat,lng] → [lng,lat] for GeoJSON
            if (Array.isArray(coords) && coords.length > 0) {
                const ring = coords.map(p => [p[1], p[0]]);
                if (ring[0][0] !== ring[ring.length-1][0] || ring[0][1] !== ring[ring.length-1][1]) {
                    ring.push(ring[0]);
                }
                setSource('geofence', {
                    type: 'Feature',
                    geometry: { type: 'Polygon', coordinates: [ring] }
                });
            }
        } catch (e) { console.error('displayGeofence:', e); }
    }

    function clearGeofenceLayer() {
        setSource('geofence', emptyFC());
        setSource('preview', emptyFC());
        currentCircle = null;
        isDrawingMode = false;
    }

    // ── Drawing tools ─────────────────────────────────────────────────────────

    function resetToolButtons() {
        document.getElementById('polygonTool').classList.remove('drawing');
        document.getElementById('circleTool').classList.remove('drawing');
    }

    function requireSelectedSite() {
        if (!currentSiteId) { showToast('Select a site first, then draw its geofence', 'error'); return false; }
        return true;
    }

    function activatePolygonTool() {
        if (!requireSelectedSite()) return;
        drawingTool = 'polygon';
        document.getElementById('radiusGroup').style.display = 'none';
        resetToolButtons();
        document.getElementById('polygonTool').classList.add('drawing');
        clearGeofenceLayer();
        enablePolygonDrawing();
    }

    function activateCircleTool() {
        if (!requireSelectedSite()) return;
        drawingTool = 'circle';
        document.getElementById('radiusGroup').style.display = 'block';
        resetToolButtons();
        document.getElementById('circleTool').classList.add('drawing');
        clearGeofenceLayer();
        enableCircleDrawing();
    }

    // Polygon: click to place points, click first (red) dot or dblclick to close.
    function enablePolygonDrawing() {
        isDrawingMode = true;
        map.doubleClickZoom.disable();

        let points = []; // [lat,lng] — backend format throughout
        let dotMarkers = [];

        function updatePreview() {
            if (points.length < 2) { setSource('preview', emptyFC()); return; }
            const ring = points.map(p => [p[1], p[0]]); // [lng,lat] for GeoJSON
            setSource('preview', {
                type: 'Feature',
                geometry: points.length >= 3
                    ? { type: 'Polygon',    coordinates: [[...ring, ring[0]]] }
                    : { type: 'LineString', coordinates: ring }
            });
        }

        function finish() {
            if (points.length < 3) { showToast('Add at least 3 points to form a shape', 'error'); return; }
            dotMarkers.forEach(m => m.remove());
            dotMarkers = [];
            setSource('preview', emptyFC());
            isDrawingMode = false;
            map.doubleClickZoom.enable();
            map.off('click', onClick);
            map.off('dblclick', onDblClick);
            resetToolButtons();
            saveGeofence('polygon', points);
        }

        function onClick(e) {
            if (e.originalEvent.button !== 0) return; // left-click only
            const lat = e.lngLat.lat, lng = e.lngLat.lng;
            points.push([lat, lng]);
            const isFirst = points.length === 1;
            const el = document.createElement('div');
            el.style.cssText = `width:${isFirst?14:10}px;height:${isFirst?14:10}px;
                background:${isFirst ? PIN_SELECTED : GEO_COLOR};border-radius:50%;
                border:2px solid #fff;box-shadow:0 0 0 1px rgba(0,0,0,.4);cursor:pointer;`;
            if (isFirst) {
                el.title = 'Click to close shape';
                el.addEventListener('click', ev => { ev.stopPropagation(); finish(); });
            }
            const m = new maplibregl.Marker({ element: el, anchor: 'center' })
                .setLngLat([lng, lat]).addTo(map);
            dotMarkers.push(m);
            updatePreview();
        }

        function onDblClick(e) {
            e.originalEvent.preventDefault();
            if (points.length > 0) points.pop(); // remove the duplicate from dblclick first-click
            finish();
        }

        map.on('click', onClick);
        map.on('dblclick', onDblClick);
        updateGeofenceStatus(null, 'Click to add points — click the first (red) point or double-click to finish.');
    }

    // Circle: one click drops the centre; radius box resizes live.
    function enableCircleDrawing() {
        isDrawingMode = true;

        function onClick(e) {
            if (e.originalEvent.button !== 0) return;
            const lat = e.lngLat.lat, lng = e.lngLat.lng;
            const radius = parseInt(document.getElementById('radiusInput').value, 10) || 200;
            const center = [lat, lng];
            setSource('geofence', {
                type: 'Feature',
                geometry: { type: 'Polygon', coordinates: [circleRing(center, radius)] }
            });
            currentCircle = { center, radius };
            isDrawingMode = false;
            map.off('click', onClick);
            resetToolButtons();
            saveGeofence('circle', { center, radius }, true);
            updateGeofenceStatus(null, 'Circle placed. Adjust the radius box to resize.');
        }

        map.on('click', onClick);
        updateGeofenceStatus(null, 'Click once on the map to drop the circle centre.');
    }

    // Live radius resize — redraws the circle polygon and debounces the server save.
    function updateCircleRadius() {
        if (!currentCircle) return;
        const radius = parseInt(document.getElementById('radiusInput').value, 10) || 200;
        const { center } = currentCircle;
        setSource('geofence', {
            type: 'Feature',
            geometry: { type: 'Polygon', coordinates: [circleRing(center, radius)] }
        });
        currentCircle = { center, radius };
        clearTimeout(radiusSaveTimer);
        radiusSaveTimer = setTimeout(() => saveGeofence('circle', { center, radius }, true), 400);
    }

    // ── Clear / Delete ────────────────────────────────────────────────────────

    function clearGeofence() {
        if (!currentSiteId) { updateGeofenceStatus(null, 'Select a site first, then clear its geofence'); return; }
        const site = sites.find(s => s.id == currentSiteId);
        const hasSaved = site && site.geofences && site.geofences.length > 0;
        if (!hasSaved && !currentCircle) { updateGeofenceStatus(null, 'No geofence to clear for this site'); return; }
        updateGeofenceStatus(null, 'Clearing geofence…');
        deleteGeofence();
    }

    function deleteGeofence() {
        if (!currentSiteId) return;
        const siteId = currentSiteId;
        const clearBtn = document.getElementById('clearBtn');
        fetch(`/admin/geofences/site/${siteId}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') }
        })
        .then(r => r.json().then(data => ({ ok: r.ok, data })))
        .then(({ ok, data }) => {
            if (ok && data.success) {
                clearGeofenceLayer();
                resetToolButtons();
                const site = sites.find(s => s.id == siteId);
                if (site) site.geofences = [];
                updateGeofenceStatus(null, 'Geofence cleared');
                if (clearBtn) {
                    clearBtn.textContent = '✓ Cleared';
                    clearBtn.classList.add('drawing');
                    setTimeout(() => { clearBtn.textContent = 'Clear'; clearBtn.classList.remove('drawing'); }, 2000);
                }
            } else {
                const msg = (data && data.error) || "Can't clear the geofence right now. Please try again.";
                updateGeofenceStatus(null, msg); showToast(msg, 'error');
            }
        })
        .catch(() => {
            const msg = "Can't clear the geofence right now. Please try again.";
            updateGeofenceStatus(null, msg); showToast(msg, 'error');
        });
    }

    // ── Save / Refresh ────────────────────────────────────────────────────────

    function saveGeofence(type, coordinates, skipRefresh = false) {
        if (!currentSiteId) return;
        fetch('/admin/geofences', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({ site_id: currentSiteId, type, coordinates, is_active: true })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                updateGeofenceStatus(null, `${type} geofence saved`);
                if (skipRefresh) {
                    const site = sites.find(s => s.id == currentSiteId);
                    if (site) site.geofences = [{ is_active: true, type, coordinates }];
                } else {
                    refreshSiteGeofence(currentSiteId);
                }
            } else {
                showToast('Error saving geofence', 'error');
            }
        })
        .catch(() => showToast('Error saving geofence', 'error'));
    }

    function refreshSiteGeofence(siteId) {
        if (!siteId) return;
        fetch(`/admin/sites/${siteId}/geofences`, {
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') }
        })
        .then(r => r.json())
        .then(data => {
            const geofences = (data && data.success && data.geofences) ? data.geofences : [];
            const site = sites.find(s => s.id == siteId);
            if (site) site.geofences = geofences;
            if (currentSiteId !== siteId) return;
            clearGeofenceLayer();
            if (geofences.length > 0) {
                const active = geofences.find(g => g.is_active) || geofences[0];
                if (active && active.coordinates) displayGeofence(active);
            }
            updateGeofenceStatus(site);
        })
        .catch(err => console.error('refreshSiteGeofence:', err));
    }

    // ── Status bar ────────────────────────────────────────────────────────────

    function updateGeofenceStatus(site, customMessage = null) {
        const el = document.getElementById('geofenceStatus');
        if (customMessage) { el.textContent = customMessage; return; }
        if (!site)         { el.textContent = 'Select a site to view or edit its geofence'; return; }
        if (site.geofences && site.geofences.length > 0) {
            const active = site.geofences.find(g => g.is_active);
            el.textContent = active
                ? `Active geofence: ${active.type} (${active.name || 'Default'})`
                : `${site.geofences.length} geofence(s) - none active`;
        } else {
            el.textContent = 'No geofence defined - click above to draw one';
        }
    }

    // ── Site drawer ───────────────────────────────────────────────────────────

    function openSiteDrawer(mode, siteId = null) {
        currentMode = mode;
        currentSiteId = siteId;
        document.getElementById('drawerTitle').textContent = mode === 'add' ? 'Add Site' : 'Edit Site';
        if (mode === 'add') clearSiteForm(); else loadSiteData(siteId);
        document.getElementById('siteDrawer').classList.add('open');
        document.querySelector('.drawer-overlay').classList.add('show');
    }

    function closeSiteDrawer() {
        // Abort any active pick
        if (pickingLocation) {
            pickingLocation = false;
            map.getCanvas().style.cursor = '';
            document.getElementById('mapPickBanner').classList.remove('visible');
        }
        clearPickSearch();
        // Remove temp pick marker
        if (pickMarker) { pickMarker.remove(); pickMarker = null; }

        document.getElementById('siteDrawer').classList.remove('open');
        document.querySelector('.drawer-overlay').classList.remove('show');
        clearSiteForm();
        currentMode = 'add';
        currentSiteId = null;
    }

    function clearSiteForm() {
        document.getElementById('siteForm').reset();
        document.getElementById('siteId').value = '';
        document.getElementById('grace_period_minutes').value = '5';
    }

    function loadSiteData(siteId) {
        const site = sites.find(s => s.id == siteId);
        if (!site) return;
        document.getElementById('siteId').value              = site.id;
        document.getElementById('name').value                = site.name;
        document.getElementById('address').value             = site.address;
        document.getElementById('latitude').value            = site.latitude  || '';
        document.getElementById('longitude').value           = site.longitude || '';
        document.getElementById('grace_period_minutes').value = site.grace_period_minutes;
        document.getElementById('contact_person').value      = site.contact_person || '';
        document.getElementById('contact_phone').value       = site.contact_phone  || '';
        document.getElementById('status').value              = site.status;
    }

    function saveSite(event) {
        event.preventDefault();
        const formData = new FormData(document.getElementById('siteForm'));
        const url    = currentMode === 'edit' ? `/admin/sites/${currentSiteId}` : '/admin/sites';
        const method = currentMode === 'edit' ? 'PUT' : 'POST';
        const data   = {};
        for (let [k, v] of formData.entries()) data[k] = v;
        fetch(url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify(data)
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(`Site ${currentMode === 'edit' ? 'updated' : 'created'} successfully!`);
                closeSiteDrawer();
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast('Error saving site', 'error');
            }
        })
        .catch(() => showToast('Error saving site', 'error'));
    }

    // ── Toast ─────────────────────────────────────────────────────────────────

    function showToast(message, type = 'success') {
        const toast = document.getElementById('successToast');
        toast.textContent = message;
        toast.style.background = type === 'error' ? 'var(--critical-red)' : 'var(--success-green)';
        toast.classList.add('show');
        setTimeout(() => toast.classList.remove('show'), 3000);
    }

    // ── Boot ──────────────────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', () => {
        resetToolButtons();
        document.getElementById('radiusGroup').style.display = 'block';
        initializeMap();
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && pickingLocation) cancelMapPick();
    });

    // Expose to inline HTML handlers
    window.selectSite          = selectSite;
    window.openSiteDrawer      = openSiteDrawer;
    window.closeSiteDrawer     = closeSiteDrawer;
    window.activatePolygonTool = activatePolygonTool;
    window.activateCircleTool  = activateCircleTool;
    window.updateCircleRadius  = updateCircleRadius;
    window.clearGeofence       = clearGeofence;
    window.saveSite            = saveSite;
    window.handleCoordInput    = handleCoordInput;
    window.startMapPick        = startMapPick;
    window.cancelMapPick       = cancelMapPick;
    window.handlePickSearch    = handlePickSearch;
    window.selectPickResult    = selectPickResult;
</script>
@endsection
