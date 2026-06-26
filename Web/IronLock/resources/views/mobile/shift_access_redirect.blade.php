<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Open Guard Monitor</title>
    <style>
        :root {
            --gold: #D4AF37; --navy: #0A1931; --bg: #0F1419;
            --surface: #0F172A; --border: #2A3441; --text: #FFFFFF; --muted: #8b95a3;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg); color: var(--text);
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 24px; text-align: center;
        }
        .card {
            background: var(--surface); border: 1.5px solid var(--border);
            border-radius: 12px; padding: 32px 26px; max-width: 380px; width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        }
        h1 { font-size: 18px; margin-bottom: 8px; }
        p { font-size: 13px; color: var(--muted); line-height: 1.6; margin-bottom: 18px; }
        .btn {
            display: inline-block; width: 100%; padding: 13px 18px; border-radius: 8px;
            background: var(--gold); color: #1a1407; font-weight: bold; font-size: 14px;
            text-decoration: none; border: none; cursor: pointer;
        }
        .btn:active { opacity: 0.85; }
        .hint { margin-top: 16px; font-size: 11px; color: var(--muted); }
    </style>
</head>
<body>
    <div class="card">
        <h1>Opening Guard Monitor…</h1>
        <p>If the app doesn’t open automatically, tap the button below.</p>
        {{-- $scheme already includes the token; $token is hex-constrained by the route. --}}
        <a class="btn" id="open-app" href="{{ $scheme }}">Open in Guard Monitor</a>
        <div class="hint">Don’t have the app installed? Install Guard Monitor first, then tap the link again.</div>
    </div>

    <script>
        // Bounce straight into the app's custom scheme. The token is the same hex
        // segment from this page's URL; the app reads it and calls the redeem
        // endpoint. We don't touch the token here — this is just the doorway.
        (function () {
            var target = @json($scheme);
            // Defer a tick so the page paints first (some in-app browsers need it).
            setTimeout(function () { window.location.href = target; }, 50);
        })();
    </script>
</body>
</html>
