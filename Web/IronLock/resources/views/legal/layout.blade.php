<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="{{ asset('ironlock-favicon.png') }}">
    <title>{{ $title }} — IronLock</title>
    <style>
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --deep-security-blue: #12355B;
            --premium-gold: #D4AF37;
            --soft-gold: #E8C76A;
            --bg-dark: #0F1419;
            --surface-dark: #1A1F26;
            --border-dark: #2A3441;
            --text-primary: #FFFFFF;
            --text-secondary: #B3BCC7;
            --text-muted: #6B7280;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-dark);
            color: var(--text-primary);
            min-height: 100vh;
            line-height: 1.65;
        }

        /* Top bar — logo (back to login) on the left. */
        .legal-topbar {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 24px;
            border-bottom: 1.5px solid var(--border-dark);
            background: var(--surface-dark);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .legal-topbar img {
            height: 34px;
            width: auto;
            display: block;
        }

        .legal-topbar .back-link {
            margin-left: auto;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-secondary);
            text-decoration: none;
            border: 1.5px solid var(--border-dark);
            border-radius: 4px;
            padding: 7px 14px;
            transition: all 0.2s ease;
        }
        .legal-topbar .back-link:hover {
            border-color: var(--premium-gold);
            color: var(--premium-gold);
        }

        /* Document container. */
        .legal-wrap {
            max-width: 860px;
            margin: 0 auto;
            padding: 40px 24px 24px;
        }

        .legal-header {
            border-bottom: 1.5px solid var(--border-dark);
            padding-bottom: 20px;
            margin-bottom: 28px;
        }

        .legal-header h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .legal-meta {
            font-size: 12px;
            color: var(--text-muted);
        }

        .legal-intro {
            font-size: 15px;
            color: var(--text-secondary);
            margin-bottom: 28px;
        }

        .legal-content h2 {
            font-size: 18px;
            font-weight: 600;
            color: var(--premium-gold);
            margin: 32px 0 12px;
            padding-top: 8px;
        }

        .legal-content h3 {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-primary);
            margin: 20px 0 8px;
        }

        .legal-content p {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 14px;
        }

        .legal-content ul {
            margin: 0 0 16px 20px;
        }

        .legal-content li {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }

        .legal-content strong {
            color: var(--text-primary);
        }

        .legal-content a {
            color: var(--soft-gold);
            text-decoration: none;
        }
        .legal-content a:hover {
            text-decoration: underline;
        }

        .legal-content table {
            width: 100%;
            border-collapse: collapse;
            margin: 8px 0 20px;
            font-size: 13px;
        }
        .legal-content th,
        .legal-content td {
            border: 1px solid var(--border-dark);
            padding: 9px 12px;
            text-align: left;
            vertical-align: top;
            color: var(--text-secondary);
        }
        .legal-content th {
            background: var(--surface-dark);
            color: var(--text-primary);
            font-weight: 600;
        }

        .legal-note {
            background: var(--surface-dark);
            border: 1px solid var(--border-dark);
            border-left: 3px solid var(--premium-gold);
            border-radius: 4px;
            padding: 14px 16px;
            font-size: 13px;
            color: var(--text-secondary);
            margin: 8px 0 20px;
        }

        /* Footer. */
        .legal-footer {
            border-top: 1.5px solid var(--border-dark);
            margin-top: 40px;
            padding: 20px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            font-size: 11px;
            color: var(--text-muted);
        }
        .legal-footer a {
            color: var(--text-secondary);
            text-decoration: none;
            transition: color 0.2s ease;
        }
        .legal-footer a:hover {
            color: var(--premium-gold);
        }
        .legal-footer .footer-links {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .legal-footer .footer-sep {
            color: var(--border-dark);
        }

        @media (max-width: 640px) {
            .legal-wrap { padding: 28px 16px 16px; }
            .legal-header h1 { font-size: 23px; }
            .legal-footer { flex-direction: column; text-align: center; }
        }
    </style>
</head>
<body>
    <header class="legal-topbar">
        <a href="{{ route('admin.login') }}" aria-label="IronLock">
            <img src="{{ asset('Images/logo/logo.png') }}" alt="IronLock">
        </a>
        <a href="{{ route('admin.login') }}" class="back-link">Back to Login</a>
    </header>

    <div class="legal-wrap">
        <div class="legal-header">
            <h1>{{ $title }}</h1>
            <div class="legal-meta">Effective date: {{ $effective }} &nbsp;·&nbsp; Last updated: {{ $updated }}</div>
        </div>

        <div class="legal-content">
            @yield('body')
        </div>
    </div>

    <footer class="legal-footer">
        <div>&copy; {{ date('Y') }} IronLock. All rights reserved.</div>
        <div class="footer-links">
            <a href="{{ route('legal.privacy') }}">Privacy Policy</a>
            <span class="footer-sep">&middot;</span>
            <a href="{{ route('legal.terms') }}">Terms &amp; Conditions</a>
            <span class="footer-sep">&middot;</span>
            <a href="{{ route('admin.login') }}">Login</a>
        </div>
    </footer>
</body>
</html>
