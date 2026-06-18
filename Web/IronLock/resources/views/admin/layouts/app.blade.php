<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
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
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-dark);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
        }

        /* Sidebar Navigation */
        .sidebar {
            width: 180px;
            flex-shrink: 0;
            background: var(--surface-dark);
            border-right: 1.5px solid var(--border-dark);
            display: flex;
            flex-direction: column;
        }

        .sidebar-logo {
            padding: 16px;
            border-bottom: 1.5px solid var(--border-dark);
            margin-bottom: 8px;
        }

        .logo img {
            width: 70%;
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
            width: 14px;
            height: 14px;
            background: var(--border-dark);
            border-radius: 2px;
            flex-shrink: 0;
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
            height: 48px;
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

        .user-menu {
            width: 32px;
            height: 32px;
            background: var(--deep-security-blue);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: bold;
            color: white;
            cursor: pointer;
            position: relative;
        }

        /* Content Area */
        .content {
            padding: 16px 18px;
            flex: 1;
            overflow-y: auto;
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

        /* Flash Messages */
        .flash-message {
            padding: 12px;
            margin-bottom: 16px;
            border-radius: 4px;
            font-size: 12px;
            display: none;
        }

        .flash-message.success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid var(--success-green);
            color: var(--success-green);
        }

        .flash-message.error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid var(--error-red);
            color: var(--error-red);
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
            <div class="nav-icon"></div>
            <span>Dashboard</span>
        </a>

        <a href="#" class="nav-item">
            <div class="nav-icon"></div>
            <span>Live Map</span>
        </a>

        <a href="#" class="nav-item">
            <div class="nav-icon"></div>
            <span>Alerts</span>
            <span class="nav-badge">4</span>
        </a>

        <a href="{{ route('admin.shifts.index') }}" class="nav-item {{ request()->routeIs('admin.shifts.*') ? 'active' : '' }}">
            <div class="nav-icon"></div>
            <span>Shifts</span>
        </a>

        <a href="{{ route('admin.guards.index') }}" class="nav-item {{ request()->routeIs('admin.guards.*') ? 'active' : '' }}">
            <div class="nav-icon"></div>
            <span>Guards</span>
        </a>

        <a href="{{ route('admin.sites.index') }}" class="nav-item {{ request()->routeIs('admin.sites.*') || request()->routeIs('admin.geofences.*') ? 'active' : '' }}">
            <div class="nav-icon"></div>
            <span>Sites</span>
        </a>

        <a href="#" class="nav-item">
            <div class="nav-icon"></div>
            <span>Reports</span>
        </a>

        <div class="nav-spacer"></div>

        <a href="#" class="nav-item">
            <div class="nav-icon"></div>
            <span>Settings</span>
        </a>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Bar -->
        <header class="topbar">
            <div class="topbar-title">@yield('page-title', 'Dashboard')</div>
            @yield('topbar-actions')
            <div class="topbar-filter">Site: All ▼</div>
            <div class="topbar-filter">Date: Today ▼</div>

            <!-- User Menu -->
            <div class="user-menu" onclick="showUserMenu()">
                {{ substr(session('admin_name', 'Admin'), 0, 1) }}
            </div>
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

    <script>
        // Auto-hide flash messages
        document.addEventListener('DOMContentLoaded', function() {
            const flashMessages = document.querySelectorAll('.flash-message.show');
            flashMessages.forEach(message => {
                setTimeout(() => {
                    message.classList.remove('show');
                }, 5000);
            });
        });

        // User menu functionality
        function showUserMenu() {
            if (confirm('Do you want to logout?')) {
                document.getElementById('logout-form').submit();
            }
        }
    </script>
    @yield('scripts')
</body>
</html>
