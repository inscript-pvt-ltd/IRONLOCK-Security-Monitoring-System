{{--
    Mobile-phone block. The admin dashboard is built for tablet and desktop
    only; phones get a full-screen notice instead of the (unusable) UI.

    Detection is orientation-independent and errs on the side of letting real
    tablets through:
      • Known tablet UAs (iPad, iPadOS-in-desktop-mode, Android-without-"Mobile")
        are always allowed.
      • Otherwise a device is treated as a phone if the UA looks like a phone
        OR its shortest screen edge is under 600 CSS px (phones ≈360–430,
        tablets ≥600).

    Included by both admin/layouts/app.blade.php and admin/auth/login.blade.php,
    so the block covers every admin page including the login screen.
--}}
<style>
    .mobile-block {
        position: fixed;
        inset: 0;
        z-index: 100000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 32px;
        text-align: center;
        background: var(--bg-dark, #0F1419);
        color: var(--text-primary, #FFFFFF);
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }
    .mobile-block.show { display: flex; }

    .mobile-block-inner { max-width: 340px; }

    .mobile-block-icon {
        width: 56px;
        height: 56px;
        margin: 0 auto 20px;
        color: var(--premium-gold, #D4AF37);
    }
    .mobile-block-icon svg { width: 100%; height: 100%; display: block; }

    .mobile-block h1 {
        font-size: 19px;
        font-weight: bold;
        margin: 0 0 10px;
        color: var(--text-primary, #FFFFFF);
    }
    .mobile-block p {
        font-size: 13px;
        line-height: 1.6;
        color: var(--text-secondary, #B3BCC7);
        margin: 0;
    }
</style>

<div class="mobile-block" id="mobile-block" role="alertdialog" aria-label="Desktop or tablet required">
    <div class="mobile-block-inner">
        <div class="mobile-block-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                <line x1="8" y1="21" x2="16" y2="21"></line>
                <line x1="12" y1="17" x2="12" y2="21"></line>
                <line x1="3" y1="3" x2="21" y2="20"></line>
            </svg>
        </div>
        <h1>Desktop or tablet required</h1>
        <p>The IronLock admin dashboard isn't available on mobile phones. Please open it on a tablet or desktop computer to continue.</p>
    </div>
</div>

<script>
    // Show the block on phones only; tablets and desktops are unaffected.
    (function () {
        function isPhone() {
            var ua = navigator.userAgent || '';

            // Let genuine tablets through first.
            var isTablet =
                /iPad/i.test(ua) ||
                (/Macintosh/i.test(ua) && navigator.maxTouchPoints > 1) ||   // iPadOS 13+ desktop-mode UA
                (/Android/i.test(ua) && !/Mobile/i.test(ua)) ||              // Android tablets omit "Mobile"
                /Tablet|PlayBook|Kindle|Silk|Nexus (7|9|10)/i.test(ua);
            if (isTablet) return false;

            var uaPhone = /Mobi|iPhone|iPod|Android.*Mobile|Windows Phone|BlackBerry|BB10|IEMobile|Opera Mini/i.test(ua);

            // Orientation-independent: a phone's shorter edge stays small even
            // in landscape, so this doesn't flip when the device is rotated.
            var shortSide = Math.min(screen.width || 0, screen.height || 0);
            var smallScreen = shortSide > 0 && shortSide < 600;

            return uaPhone || smallScreen;
        }

        function apply() {
            var el = document.getElementById('mobile-block');
            if (!el) return;
            el.classList.toggle('show', isPhone());
        }

        apply();
        window.addEventListener('resize', apply);
        window.addEventListener('orientationchange', apply);
    })();
</script>
