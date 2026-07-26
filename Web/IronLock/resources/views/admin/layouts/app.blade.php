<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('ironlock-favicon.png') }}">
    <title>@yield('title', 'Smart Guard Monitor - Admin Dashboard')</title>

    <style>
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            /* IronLock Color Palette - from PROJECT_MASTER_SPEC.md */
            --deep-security-blue: #12355B;
            --premium-gold: #D4AF37;
            --soft-gold: #E8C76A;
            --bg-dark: #0F1419;    /* Slightly lighter than spec for readability */
            --surface-dark: #0F172A; /* Matches spec card surface */
            --navy: #0A1931;
            --border-dark: #2A3441;
            --text-primary: #FFFFFF;
            --text-secondary: #B3BCC7;
            --text-muted: #6B7280;
            --success-green: #22c55e;
            --warning-amber: #F59E0B;
            --error-red: #EF4444;
            --critical-red: #DC2626;

            /* Shared height for the topbar and the sidebar logo block, so their
               bottom borders line up across the top of the dashboard. */
            --header-h: 56px;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-dark);
            color: var(--text-primary);
            /* Pin the whole shell to the viewport so the sidebar and topbar stay
               put — only the content area scrolls (see .content). */
            height: 100vh;
            overflow: hidden;
            display: flex;
        }

        /* Sidebar Navigation — full viewport height; the nav-spacer keeps the
           logo pinned to the top and Settings to the bottom of the screen. */
        .sidebar {
            width: 180px;
            flex-shrink: 0;
            background: var(--surface-dark);
            border-right: 1.5px solid var(--border-dark);
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow-y: auto;
        }

        .sidebar-logo {
            height: var(--header-h);
            flex-shrink: 0;
            padding: 0 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-bottom: 1.5px solid var(--border-dark);
            margin-bottom: 8px;
        }

        .logo img {
            max-width: 100%;
            max-height: 32px;
            width: auto;
            height: auto;
            display: block;
            margin-inline: auto;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            font-size: 12px;
            color: var(--text-secondary);
            text-decoration: none;
            border-left: 3px solid transparent;
            transition: all 0.2s ease;
        }

        .nav-item:hover {
            background: var(--border-dark);
            color: var(--text-primary);
        }

        .nav-item.active {
            background: var(--border-dark);
            color: var(--text-primary);
            border-left-color: var(--premium-gold);
            font-weight: bold;
        }

        .nav-icon {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
            display: block;
            color: inherit;
            transform-origin: center;
        }

        /* Bounce the icon in place on hover/click. translateY-only so it returns
           to its exact spot and never nudges the label or neighbours. */
        @keyframes nav-icon-bounce {
            0%, 100% { transform: translateY(0); }
            30%      { transform: translateY(-5px); }
            55%      { transform: translateY(0); }
            75%      { transform: translateY(-2px); }
        }

        /* A sharper one-shot pop for the click. */
        @keyframes nav-icon-pop {
            0%   { transform: translateY(0) scale(1); }
            35%  { transform: translateY(-6px) scale(1.18); }
            100% { transform: translateY(0) scale(1); }
        }

        /* A punchier pop on the actual press (hover no longer animates). */
        .nav-item:active .nav-icon {
            animation: nav-icon-pop 0.4s ease;
        }

        .nav-badge {
            background: var(--critical-red);
            color: white;
            font-size: 10px;
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: auto;
        }

        .nav-spacer {
            flex: 1;
        }

        /* Main Content Area */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        /* Top Bar */
        .topbar {
            background: var(--surface-dark);
            border-bottom: 1.5px solid var(--border-dark);
            height: var(--header-h);
            display: flex;
            align-items: center;
            padding: 0 18px;
            gap: 12px;
            flex-shrink: 0;
        }

        .topbar-title {
            font-weight: bold;
            font-size: 13px;
            flex: 1;
        }

        .topbar-filter {
            background: var(--bg-dark);
            border: 1.5px solid var(--border-dark);
            padding: 4px 10px;
            font-size: 11px;
            color: var(--text-secondary);
            border-radius: 4px;
            cursor: pointer;
        }

        /* Notification bell (topbar, left of the profile avatar) */
        .notif-wrap { position: relative; display: flex; align-items: center; }

        .notif-bell {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            border: 1.5px solid var(--border-dark);
            border-radius: 50%;
            color: var(--text-secondary);
            cursor: pointer;
            position: relative;
            transition: border-color 0.2s ease, color 0.2s ease;
        }
        .notif-bell:hover { border-color: var(--premium-gold); color: var(--premium-gold); }
        .notif-bell.has-items { color: var(--premium-gold); border-color: var(--premium-gold); }

        /* Ring the bell when a NEW alert arrives (triggered from JS, see below).
           Swings from the top like a real bell and settles back in place. */
        @keyframes notif-bell-ring {
            0%   { transform: rotate(0); }
            10%  { transform: rotate(18deg); }
            20%  { transform: rotate(-15deg); }
            30%  { transform: rotate(12deg); }
            40%  { transform: rotate(-9deg); }
            50%  { transform: rotate(6deg); }
            60%  { transform: rotate(-4deg); }
            70%  { transform: rotate(2deg); }
            100% { transform: rotate(0); }
        }
        .notif-bell.ringing svg {
            transform-origin: top center;
            animation: notif-bell-ring 0.9s ease;
        }

        /* The badge pops in alongside the ring. */
        @keyframes notif-badge-pop {
            0%   { transform: scale(0.5); }
            55%  { transform: scale(1.3); }
            100% { transform: scale(1); }
        }
        .notif-badge.pop { animation: notif-badge-pop 0.45s ease; }

        .notif-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            min-width: 16px;
            height: 16px;
            padding: 0 4px;
            background: var(--critical-red);
            color: #fff;
            font-size: 10px;
            font-weight: bold;
            line-height: 16px;
            text-align: center;
            border-radius: 8px;
            border: 1.5px solid var(--surface-dark);
        }

        .notif-panel {
            position: absolute;
            top: 40px;
            right: 0;
            width: 320px;
            max-height: 420px;
            overflow-y: auto;
            background: var(--surface-dark);
            border: 1.5px solid var(--border-dark);
            border-radius: 6px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            display: none;
            z-index: 200;
        }
        .notif-panel.open { display: block; }

        .notif-panel-head {
            padding: 10px 14px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border-dark);
        }

        .notif-item {
            padding: 11px 14px;
            border-bottom: 1px solid var(--border-dark);
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .notif-item:last-child { border-bottom: none; }
        .notif-item--early-end { border-left: 3px solid var(--warning-amber); }
        .notif-item--resolve { border-left: 3px solid var(--critical-red); }

        .notif-item-title { font-size: 12px; font-weight: bold; color: var(--text-primary); }
        .notif-item-meta { font-size: 11px; color: var(--text-secondary); }
        .notif-item-reason { font-size: 11px; color: var(--text-muted); }
        .notif-item-foot { display: flex; align-items: center; justify-content: space-between; margin-top: 4px; }
        .notif-item-time { font-size: 10px; color: var(--text-muted); }

        .notif-view-btn {
            font-size: 10px;
            font-weight: bold;
            text-decoration: none;
            padding: 4px 12px;
            border-radius: 4px;
            background: var(--deep-security-blue);
            color: #fff;
            border: 1px solid var(--deep-security-blue);
        }
        .notif-view-btn:hover { background: transparent; color: var(--soft-gold); border-color: var(--premium-gold); }

        .notif-empty { padding: 18px 14px; font-size: 11px; color: var(--text-muted); text-align: center; }

        /* Content Area — the only scrolling region; min-height:0 lets it shrink
           inside the flex column so its own scrollbar engages (not the window). */
        .content {
            padding: 16px 18px;
            flex: 1;
            min-height: 0;
            overflow-y: auto;
        }

        /* Custom scrollbar — same blue/gold treatment used by the schedule
           table (.cal-container) and the drawers, applied to the app shell's
           two scroll regions (main content + sidebar). */
        .content,
        .sidebar {
            scrollbar-width: thin;
            scrollbar-color: var(--deep-security-blue) var(--bg-dark);
        }
        .content::-webkit-scrollbar,
        .sidebar::-webkit-scrollbar { width: 10px; }
        .content::-webkit-scrollbar-track,
        .sidebar::-webkit-scrollbar-track {
            background: var(--bg-dark);
            border-radius: 4px;
        }
        .content::-webkit-scrollbar-thumb,
        .sidebar::-webkit-scrollbar-thumb {
            background: var(--deep-security-blue);
            border-radius: 6px;
            border: 2px solid var(--bg-dark);
        }
        .content::-webkit-scrollbar-thumb:hover,
        .sidebar::-webkit-scrollbar-thumb:hover {
            background: var(--premium-gold);
        }

        /* KPI Cards */
        .kpi-row {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
        }

        .kpi-card {
            flex: 1;
            background: var(--surface-dark);
            border: 1.5px solid var(--border-dark);
            padding: 16px 14px;
            border-radius: 4px;
            cursor: pointer;
            transition: border-color 0.2s ease;
        }

        .kpi-card:hover {
            border-color: var(--premium-gold);
        }

        .kpi-value {
            font-size: 32px;
            font-weight: bold;
            line-height: 1;
        }

        .kpi-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
            margin-top: 4px;
        }

        /* Alert Cards */
        .alerts-section {
            margin-bottom: 24px;
        }

        .section-title {
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
            margin-bottom: 12px;
        }

        .alert-card {
            background: var(--surface-dark);
            border: 1.5px solid var(--border-dark);
            padding: 12px;
            margin-bottom: 8px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-card.critical {
            border-left: 3px solid var(--critical-red);
        }

        .alert-card.warning {
            border-left: 3px solid var(--warning-amber);
        }

        .severity-badge {
            padding: 2px 8px;
            font-size: 10px;
            font-weight: bold;
            border-radius: 4px;
            text-transform: uppercase;
        }

        .severity-badge.critical {
            background: var(--critical-red);
            color: white;
        }

        .severity-badge.warning {
            background: var(--warning-amber);
            color: white;
        }

        .alert-content {
            flex: 1;
        }

        .alert-title {
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 2px;
        }

        .alert-meta {
            font-size: 11px;
            color: var(--text-muted);
        }

        .alert-actions {
            display: flex;
            gap: 6px;
        }

        .btn-sm {
            padding: 4px 10px;
            font-size: 10px;
            font-weight: bold;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-primary-sm {
            background: var(--deep-security-blue);
            color: white;
            border: 1px solid var(--deep-security-blue);
        }

        .btn-primary-sm:hover {
            background: transparent;
            color: var(--deep-security-blue);
        }

        .btn-secondary-sm {
            background: transparent;
            color: var(--text-secondary);
            border: 1px solid var(--border-dark);
        }

        .btn-secondary-sm:hover {
            border-color: var(--premium-gold);
            color: var(--premium-gold);
        }

        /* Tables */
        .table-container {
            background: var(--surface-dark);
            border: 1.5px solid var(--border-dark);
            border-radius: 4px;
            overflow: hidden;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }

        .table th {
            background: var(--border-dark);
            padding: 8px;
            text-align: left;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-secondary);
        }

        .table td {
            padding: 10px 8px;
            border-bottom: 1px solid var(--border-dark);
            vertical-align: middle;
        }

        .table tr:last-child td {
            border-bottom: none;
        }

        /* Status Chips */
        .status-chip {
            padding: 3px 9px;
            font-size: 10px;
            font-weight: bold;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .status-chip.inside {
            background: rgba(34, 197, 94, 0.2);
            color: var(--success-green);
            border: 1px solid var(--success-green);
        }

        .status-chip.outside {
            background: rgba(245, 158, 11, 0.2);
            color: var(--warning-amber);
            border: 1px solid var(--warning-amber);
        }

        .status-chip.unresponsive,
        .status-chip.interrupted {
            background: rgba(239, 68, 68, 0.2);
            color: var(--error-red);
            border: 1px solid var(--error-red);
        }

        /* Flash Messages — floating toast (matches the app-wide default success
           toast: solid, top-right, white text, auto-hides). Fixed-position so it
           overlays rather than pushing page content down. */
        .flash-message {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            max-width: 520px;
            padding: 12px 16px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            color: white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            display: none;
        }

        .flash-message.success {
            background: var(--success-green);
        }

        .flash-message.error {
            background: var(--error-red);
        }

        .flash-message.show {
            display: block;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                width: 60px;
            }

            .nav-item span {
                display: none;
            }

            .nav-badge {
                position: absolute;
                top: -2px;
                right: -2px;
            }
        }
    </style>
    @yield('styles')
</head>
<body>
    <!-- Sidebar Navigation -->
    <nav class="sidebar">
        <div class="sidebar-logo">
            <div class="logo">
                <img src="{{ asset('Images/logo/logo.png') }}" alt="IronLock">
            </div>
        </div>

        <a href="{{ route('admin.dashboard') }}" class="nav-item {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
            {{-- Dashboard — grid of panels --}}
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="7" height="7"></rect>
                <rect x="14" y="3" width="7" height="7"></rect>
                <rect x="14" y="14" width="7" height="7"></rect>
                <rect x="3" y="14" width="7" height="7"></rect>
            </svg>
            <span>Dashboard</span>
        </a>

        <a href="{{ route('admin.live-map.index') }}" class="nav-item {{ request()->routeIs('admin.live-map.*') ? 'active' : '' }}">
            {{-- Live Map — map pin --}}
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                <circle cx="12" cy="10" r="3"></circle>
            </svg>
            <span>Live Map</span>
        </a>

        <a href="{{ route('admin.alerts.index') }}" class="nav-item {{ request()->routeIs('admin.alerts.*') ? 'active' : '' }}">
            {{-- Alerts — warning triangle --}}
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                <line x1="12" y1="9" x2="12" y2="13"></line>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
            <span>Alerts</span>
            <span class="nav-badge" id="nav-alert-badge" style="display:none;">0</span>
        </a>

        <a href="{{ route('admin.shifts.index') }}" class="nav-item {{ request()->routeIs('admin.shifts.*') ? 'active' : '' }}">
            {{-- Shifts — calendar --}}
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="16" y1="2" x2="16" y2="6"></line>
                <line x1="8" y1="2" x2="8" y2="6"></line>
                <line x1="3" y1="10" x2="21" y2="10"></line>
            </svg>
            <span>Shifts</span>
        </a>

        <a href="{{ route('admin.guards.index') }}" class="nav-item {{ request()->routeIs('admin.guards.*') ? 'active' : '' }}">
            {{-- Guards — security personnel (people) --}}
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                <circle cx="9" cy="7" r="4"></circle>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
            </svg>
            <span>Guards</span>
        </a>

        <a href="{{ route('admin.sites.index') }}" class="nav-item {{ request()->routeIs('admin.sites.*') || request()->routeIs('admin.geofences.*') ? 'active' : '' }}">
            {{-- Sites — building --}}
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="4" y="2" width="16" height="20" rx="2" ry="2"></rect>
                <path d="M9 22v-4h6v4"></path>
                <line x1="8" y1="6" x2="8.01" y2="6"></line>
                <line x1="12" y1="6" x2="12.01" y2="6"></line>
                <line x1="16" y1="6" x2="16.01" y2="6"></line>
                <line x1="8" y1="10" x2="8.01" y2="10"></line>
                <line x1="12" y1="10" x2="12.01" y2="10"></line>
                <line x1="16" y1="10" x2="16.01" y2="10"></line>
                <line x1="8" y1="14" x2="8.01" y2="14"></line>
                <line x1="16" y1="14" x2="16.01" y2="14"></line>
            </svg>
            <span>Sites</span>
        </a>

        <a href="{{ route('admin.reports.index') }}" class="nav-item {{ request()->routeIs('admin.reports.*') ? 'active' : '' }}">
            {{-- Reports — bar chart --}}
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="20" x2="18" y2="10"></line>
                <line x1="12" y1="20" x2="12" y2="4"></line>
                <line x1="6" y1="20" x2="6" y2="14"></line>
            </svg>
            <span>Reports</span>
        </a>

        <div class="nav-spacer"></div>

        <a href="#" class="nav-item" id="backup-nav-item" onclick="openBackupModal(event)">
            {{-- Backup — archive box --}}
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="21 8 21 21 3 21 3 8"></polyline>
                <rect x="1" y="3" width="22" height="5"></rect>
                <line x1="10" y1="12" x2="14" y2="12"></line>
            </svg>
            <span>Backup</span>
        </a>

        <a href="{{ route('admin.settings.index') }}" class="nav-item {{ request()->routeIs('admin.settings.*') ? 'active' : '' }}">
            {{-- Settings — gear --}}
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="3"></circle>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
            </svg>
            <span>Settings</span>
        </a>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Bar -->
        <header class="topbar">
            <div class="topbar-title">@yield('page-title', 'Dashboard')</div>
            @yield('topbar-actions')

            <!-- Critical-alert sound toggle -->
            <button type="button" class="notif-bell" id="alert-mute-btn" onclick="toggleAlertMute()" title="Critical alert sound" aria-label="Toggle critical alert sound" style="margin-right:4px;">
                <svg id="alert-sound-on" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon>
                    <path d="M15.54 8.46a5 5 0 0 1 0 7.07"></path>
                    <path d="M19.07 4.93a10 10 0 0 1 0 14.14"></path>
                </svg>
                <svg id="alert-sound-off" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;">
                    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon>
                    <line x1="23" y1="9" x2="17" y2="15"></line>
                    <line x1="17" y1="9" x2="23" y2="15"></line>
                </svg>
            </button>

            <!-- Notifications -->
            <div class="notif-wrap">
                <button type="button" class="notif-bell" id="notif-bell" onclick="toggleNotifPanel(event)" aria-label="Notifications" title="Notifications">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                    </svg>
                    <span class="notif-badge" id="notif-badge" style="display:none;">0</span>
                </button>
                <div class="notif-panel" id="notif-panel">
                    <div class="notif-panel-head">Notifications</div>
                    <div id="notif-list">
                        <div class="notif-empty">Nothing needs your attention.</div>
                    </div>
                </div>
            </div>

            <!-- Log out (topbar icon). Tooltip carries the signed-in admin's name. -->
            <button type="button" class="notif-bell" onclick="confirmLogout()"
                    title="Log out ({{ session('admin_name', 'Admin') }})" aria-label="Log out">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                    <polyline points="16 17 21 12 16 7"></polyline>
                    <line x1="21" y1="12" x2="9" y2="12"></line>
                </svg>
            </button>
        </header>

        <!-- Content Area -->
        <main class="content">
            <!-- Flash Messages -->
            @if (session('success'))
                <div class="flash-message success show">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('error'))
                <div class="flash-message error show">
                    {{ session('error') }}
                </div>
            @endif

            @yield('content')
        </main>
    </div>

    <!-- Hidden Logout Form -->
    <form id="logout-form" method="POST" action="{{ route('admin.logout') }}" style="display: none;">
        @csrf
    </form>

    <!-- Backup Management modal (admin-only) -->
    <style>
        .backup-overlay {
            position: fixed; inset: 0; z-index: 2000; display: none;
            align-items: center; justify-content: center; padding: 24px;
            background: rgba(0,0,0,0.6); backdrop-filter: blur(2px);
        }
        .backup-overlay.open { display: flex; }
        .backup-modal {
            background: var(--surface-dark, #0F172A); border: 1.5px solid var(--border-dark, #2A3441);
            border-radius: 12px; width: 100%; max-width: 540px; max-height: 80vh;
            display: flex; flex-direction: column; box-shadow: 0 24px 70px rgba(0,0,0,0.55);
        }
        .backup-modal-head {
            display: flex; align-items: center; gap: 10px;
            padding: 16px 20px; border-bottom: 1px solid var(--border-dark, #2A3441);
        }
        .backup-modal-head h3 { margin: 0; font-size: 15px; color: var(--text-primary, #fff); flex: 1; }
        .backup-modal-close {
            background: none; border: none; color: var(--text-muted, #8b95a3);
            font-size: 22px; line-height: 1; cursor: pointer; padding: 0 4px;
        }
        .backup-modal-close:hover { color: var(--text-primary, #fff); }
        .backup-modal-body { padding: 16px 20px; overflow-y: auto; }
        .backup-card {
            background: var(--bg-dark, #0F1419); border: 1px solid var(--border-dark, #2A3441);
            border-radius: 8px; padding: 14px 16px; margin-bottom: 12px;
        }
        .backup-card:last-child { margin-bottom: 0; }
        .backup-card-top { display: flex; align-items: baseline; gap: 10px; }
        .backup-card-title { font-size: 14px; font-weight: bold; color: var(--text-primary, #fff); flex: 1; }
        .backup-status {
            font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.04em;
            padding: 2px 8px; border-radius: 9px; border: 1px solid;
        }
        .backup-status.current { color: var(--success-green, #22c55e); border-color: var(--success-green, #22c55e); }
        .backup-status.countdown { color: var(--premium-gold, #D4AF37); border-color: var(--premium-gold, #D4AF37); }
        .backup-sub { font-size: 11px; color: var(--text-muted, #8b95a3); margin: 8px 0 6px; }
        .backup-bar-track {
            height: 8px; border-radius: 5px; background: var(--surface-dark, #1f2733);
            border: 1px solid var(--border-dark, #2A3441); overflow: hidden;
        }
        .backup-bar-fill { height: 100%; background: var(--premium-gold, #D4AF37); transition: width 0.3s ease; }
        .backup-bar-fill.current { background: var(--success-green, #22c55e); }
        .backup-card-foot { display: flex; align-items: center; gap: 10px; margin-top: 12px; }
        .backup-count { font-size: 11px; color: var(--text-muted, #8b95a3); flex: 1; }
        .backup-dl-btn {
            font-size: 11px; font-weight: bold; padding: 7px 14px; border-radius: 5px; cursor: pointer;
            background: var(--premium-gold, #D4AF37); color: #1a1407; border: none;
        }
        .backup-dl-btn:hover { opacity: 0.9; }
        .backup-dl-btn:disabled { opacity: 0.4; cursor: default; }
        .backup-empty, .backup-loading { color: var(--text-muted, #8b95a3); font-size: 12px; text-align: center; padding: 24px 0; }
    </style>
    <div class="backup-overlay" id="backup-overlay" onclick="if(event.target===this)closeBackupModal()">
        <div class="backup-modal" role="dialog" aria-modal="true" aria-label="Backup Management">
            <div class="backup-modal-head">
                <h3>Backup — Monthly Evidence Archive</h3>
                <button type="button" class="backup-modal-close" onclick="closeBackupModal()" aria-label="Close">&times;</button>
            </div>
            <div class="backup-modal-body" id="backup-modal-body">
                <div class="backup-loading">Loading backups…</div>
            </div>
        </div>
    </div>

    <script>
        // Auto-hide flash messages
        document.addEventListener('DOMContentLoaded', function() {
            const flashMessages = document.querySelectorAll('.flash-message.show');
            flashMessages.forEach(message => {
                setTimeout(() => {
                    message.classList.remove('show');
                }, 3000);
            });
        });

        // Log out (topbar icon). Confirms, then submits the hidden POST form.
        function confirmLogout() {
            if (confirm('Do you want to logout?')) {
                document.getElementById('logout-form').submit();
            }
        }

        // ── Backup Management modal ────────────────────────────────────────
        // Admin-only. Lists monthly evidence archives (current month + completed
        // months still within their 30-day retention) and downloads a month's ZIP.
        function openBackupModal(e) {
            if (e) e.preventDefault();
            var overlay = document.getElementById('backup-overlay');
            var body = document.getElementById('backup-modal-body');
            overlay.classList.add('open');
            body.innerHTML = '<div class="backup-loading">Loading backups…</div>';

            fetch('{{ route('admin.backups.index') }}', { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) { renderBackups(data.backups || []); })
                .catch(function () {
                    body.innerHTML = '<div class="backup-empty">Unable to load backups. Please try again.</div>';
                });
        }

        function closeBackupModal() {
            document.getElementById('backup-overlay').classList.remove('open');
        }

        function downloadBackup(monthKey) {
            // GET download carries the admin session cookie; streams the ZIP.
            window.location.href = '/admin/backups/' + encodeURIComponent(monthKey) + '/download';
        }

        function renderBackups(list) {
            var body = document.getElementById('backup-modal-body');
            function esc(s) {
                return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            }
            if (!list.length) {
                body.innerHTML = '<div class="backup-empty">No evidence has been archived yet.</div>';
                return;
            }
            var html = '';
            list.forEach(function (b) {
                var pct = Math.max(0, Math.min(100, b.progress_percent || 0));
                var statusClass = b.is_current ? 'current' : 'countdown';
                var barClass = b.is_current ? 'backup-bar-fill current' : 'backup-bar-fill';
                var imgs = (b.image_count || 0) + ' image' + ((b.image_count === 1) ? '' : 's');
                html += '<div class="backup-card">'
                    + '<div class="backup-card-top">'
                    +   '<span class="backup-card-title">' + esc(b.label) + '</span>'
                    +   '<span class="backup-status ' + statusClass + '">' + esc(b.status) + '</span>'
                    + '</div>'
                    + '<div class="backup-sub">' + esc(b.sublabel) + '</div>'
                    + '<div class="backup-bar-track"><div class="' + barClass + '" style="width:' + pct + '%"></div></div>'
                    + '<div class="backup-card-foot">'
                    +   '<span class="backup-count">' + esc(imgs) + '</span>'
                    +   '<button type="button" class="backup-dl-btn" ' + (b.downloadable ? '' : 'disabled') + ' '
                    +     'onclick="downloadBackup(\'' + esc(b.month_key) + '\')">Download Backup</button>'
                    + '</div>'
                    + '</div>';
            });
            body.innerHTML = html;
        }

        // Close the backup modal on Escape.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeBackupModal();
        });

        // ── Notification bell ──────────────────────────────────────────────
        // Polls the admin notification feed (pending early-finish requests) and
        // keeps the badge + dropdown in sync on every admin page. Each item has a
        // View button that opens the shift timeline, where the request can be
        // approved or rejected.
        (function () {
            var wrap   = document.querySelector('.notif-wrap');
            var bell   = document.getElementById('notif-bell');
            var panel  = document.getElementById('notif-panel');
            var badge  = document.getElementById('notif-badge');
            var list   = document.getElementById('notif-list');
            if (!wrap || !bell || !panel || !badge || !list) return;

            function escapeHtml(s) {
                return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            }

            // Identity for an alert, so we can tell a genuinely new one from a
            // still-pending one across polls (no explicit id in the feed).
            function keyFor(it) {
                return (it.type || '') + ':' + (it.shift_id || it.reference || it.title || '');
            }

            // Remembered keys from the previous poll. null = nothing loaded yet,
            // so the very first batch of alerts counts as "new" and rings once.
            var seenKeys = null;

            // Play the ring (+ badge pop). Re-trigger safely by clearing the
            // class and forcing a reflow before re-adding it.
            function ring() {
                bell.classList.remove('ringing');
                badge.classList.remove('pop');
                void bell.offsetWidth;
                bell.classList.add('ringing');
                badge.classList.add('pop');
            }
            bell.addEventListener('animationend', function () {
                bell.classList.remove('ringing');
                badge.classList.remove('pop');
            });

            function timeAgo(iso) {
                if (!iso) return '';
                var then = new Date(iso).getTime();
                if (isNaN(then)) return '';
                var secs = Math.max(0, Math.floor((Date.now() - then) / 1000));
                if (secs < 60) return 'just now';
                var mins = Math.floor(secs / 60);
                if (mins < 60) return mins + ' min ago';
                var hrs = Math.floor(mins / 60);
                if (hrs < 24) return hrs + 'h ago';
                return Math.floor(hrs / 24) + 'd ago';
            }

            function render(items) {
                var count = items.length;
                if (count > 0) {
                    badge.textContent = count > 99 ? '99+' : count;
                    badge.style.display = '';
                    bell.classList.add('has-items');
                } else {
                    badge.style.display = 'none';
                    bell.classList.remove('has-items');
                }

                // Ring only when something NEW shows up — the first batch of
                // alerts, or a key we hadn't seen on the previous poll. Items
                // that simply persist across polls don't re-trigger it.
                var keys = items.map(keyFor);
                if (seenKeys === null) {
                    if (count > 0) ring();
                } else if (keys.some(function (k) { return seenKeys.indexOf(k) === -1; })) {
                    ring();
                }
                seenKeys = keys;

                if (count === 0) {
                    list.innerHTML = '<div class="notif-empty">Nothing needs your attention.</div>';
                    return;
                }

                list.innerHTML = items.map(function (it) {
                    var detail = it.detail ? '<div class="notif-item-reason">' + escapeHtml(it.detail) + '</div>' : '';
                    var typeClass = it.type === 'resolve' ? 'notif-item--resolve' : 'notif-item--early-end';
                    return '' +
                        '<div class="notif-item ' + typeClass + '">' +
                            '<div class="notif-item-title">' + escapeHtml(it.title) + '</div>' +
                            '<div class="notif-item-meta">' + escapeHtml(it.site_name) +
                                (it.reference ? ' · #' + escapeHtml(it.reference) : '') + '</div>' +
                            detail +
                            '<div class="notif-item-foot">' +
                                '<span class="notif-item-time">' + escapeHtml(timeAgo(it.at)) + '</span>' +
                                '<a class="notif-view-btn" href="' + escapeHtml(it.action_url) + '">' +
                                    escapeHtml(it.action_label || 'View') + '</a>' +
                            '</div>' +
                        '</div>';
                }).join('');
            }

            function load() {
                fetch('{{ route('admin.notifications') }}', { headers: { 'Accept': 'application/json' } })
                    .then(function (res) { return res.ok ? res.json() : null; })
                    .then(function (data) {
                        if (data && data.success) render(data.notifications || []);
                    })
                    .catch(function () { /* transient — keep last good state */ });
            }

            window.toggleNotifPanel = function (e) {
                if (e) e.stopPropagation();
                panel.classList.toggle('open');
            };

            document.addEventListener('click', function (e) {
                if (!wrap.contains(e.target)) panel.classList.remove('open');
            });

            load();
            setInterval(load, 30000);
        })();

        // ── Sidebar "Alerts" badge + global critical-alert sound ───────────
        // Keeps the open-alert count on the nav in sync on every admin page, and
        // sounds an audible cue when a *new* CRITICAL alert appears between polls
        // (roadmap §6.2 "Auditory alerts for critical events"). The Alert Feed
        // page also calls window.setNavAlertBadge() right after an acknowledge so
        // the badge drops without waiting for the next poll.
        (function () {
            var badge = document.getElementById('nav-alert-badge');
            var muteBtn = document.getElementById('alert-mute-btn');
            var iconOn = document.getElementById('alert-sound-on');
            var iconOff = document.getElementById('alert-sound-off');

            window.setNavAlertBadge = function (count) {
                if (!badge) return;
                if (count > 0) {
                    badge.textContent = count > 99 ? '99+' : count;
                    badge.style.display = '';
                } else {
                    badge.style.display = 'none';
                }
            };

            // Mute state persists per browser.
            var muted = localStorage.getItem('ironlock_alert_muted') === '1';
            function paintMute() {
                if (!iconOn || !iconOff) return;
                iconOn.style.display = muted ? 'none' : '';
                iconOff.style.display = muted ? '' : 'none';
                if (muteBtn) muteBtn.title = muted ? 'Critical alert sound: OFF' : 'Critical alert sound: ON';
            }
            paintMute();
            window.toggleAlertMute = function () {
                muted = !muted;
                localStorage.setItem('ironlock_alert_muted', muted ? '1' : '0');
                paintMute();
                if (!muted) { ensureAudio(); beep(); } // confirm it's audible
            };

            // Web Audio — synthesised two-tone cue, no asset. Autoplay policy
            // means the context must be unlocked by a user gesture first.
            var audioCtx = null;
            function ensureAudio() {
                try {
                    if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                    if (audioCtx.state === 'suspended') audioCtx.resume();
                } catch (e) { /* no audio available */ }
            }
            document.addEventListener('click', ensureAudio);

            function tone(freq, start, dur) {
                var o = audioCtx.createOscillator(), g = audioCtx.createGain();
                o.connect(g); g.connect(audioCtx.destination);
                o.type = 'sine'; o.frequency.value = freq;
                var t = audioCtx.currentTime + start;
                g.gain.setValueAtTime(0.0001, t);
                g.gain.exponentialRampToValueAtTime(0.32, t + 0.02);
                g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
                o.start(t); o.stop(t + dur + 0.02);
            }
            function beep() {
                if (muted || !audioCtx) return;
                tone(880, 0, 0.18);
                tone(660, 0.22, 0.26);
            }

            // null = baseline not set yet → don't sound for alerts already open
            // when the page loaded; only ring for ones that appear afterwards.
            var seenCritical = null;

            function loadCount() {
                fetch('{{ route('admin.alerts.count') }}', { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (d) {
                        if (!d || !d.success) return;
                        window.setNavAlertBadge(d.count);

                        var ids = d.critical_ids || [];
                        if (seenCritical !== null &&
                            ids.some(function (id) { return seenCritical.indexOf(id) === -1; })) {
                            beep();
                        }
                        seenCritical = ids;
                    })
                    .catch(function () { /* transient — keep last good state */ });
            }

            loadCount();
            setInterval(loadCount, 15000);
        })();
    </script>
    @yield('scripts')

    @include('admin.partials.mobile-block')
</body>
</html>
