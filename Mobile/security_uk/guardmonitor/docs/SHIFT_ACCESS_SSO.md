# Shift Access Link (SSO) — passwordless deep-link sign-in

**Date:** 2026-06-26 · **Status:** app side implemented (custom scheme). Universal/App
Links deferred to the Apple-account / association-file step.

A supervisor generates a one-time link and sends it to the guard (WhatsApp/SMS/email).
Tapping it opens the app and signs the guard into one specific shift **without a password**.
Redeem returns the **same payload as `/auth/login`**, so the guard lands `checked_in`,
identical to a password login.

```
https://dashboard.ironlock.co.uk/m/shift-access/<token>
```
`<token>` = last path segment, a 64-char hex string.

---

## ✅ What's implemented in the app

| Piece | Where |
|---|---|
| Endpoint | `ApiConfig.shiftAccess` = `/auth/shift-access` |
| Redeem call | `AuthService.redeemShiftAccess(token)` → same `AuthTokenModel` as login |
| Sign-in | `AuthNotifier.signInWithShiftAccess(token)` → shares `_persistSession` with password login (tokens + `hmac_secret` + guard, then `GET /shifts/current`) |
| Token parse + error map | `services/shift_access_link.dart` (`extractShiftAccessToken`, `ShiftAccessException`) — pure, unit-tested |
| Deep-link listener | `services/deep_link_service.dart` (`app_links`: cold-start `getInitialLink()` + warm `uriLinkStream`); started in `main.dart` |
| Redeem UI state | `providers/shift_access_provider.dart` — login screen shows the "Signing in…" loader while redeeming and the mapped error on failure |
| iOS scheme | `ios/Runner/Info.plist` → `CFBundleURLTypes` scheme `ironlock` |
| Android scheme | `AndroidManifest.xml` MainActivity → `VIEW`/`BROWSABLE` intent-filter `ironlock://shift-access` |

Single-use is respected: failures route back to login with copy ("ask your supervisor for a
new one"); **no auto-retry** of a token. Error codes handled: `SHIFT_ACCESS_INVALID`,
`_EXPIRED`, `_USED`, `_SHIFT_INVALID`, `_UNAUTHORIZED`, plus `ACCOUNT_LOCKED`,
`LOGIN_WINDOW_CLOSED` (reuses the login `details.reason` → `too_early`/`expired`),
`VALIDATION_ERROR`, `RATE_LIMITED`.

---

## 🔴 Backend dependency (REQUIRED for the https link to open the app)

We registered the **custom scheme `ironlock://`** (no Apple Developer account needed). But a
custom scheme is **not** auto-tappable from an https link in WhatsApp/SMS. For the
`https://…/m/shift-access/<token>` link to open the app, the **server's landing page at that
URL must redirect / offer an "Open in app" button to**:

```
ironlock://shift-access/<token>
```

➡️ **Action for the backend dev:** make `/m/shift-access/<token>` serve a tiny page that
(a) attempts `window.location = "ironlock://shift-access/<token>"`, and (b) shows an
"Open in Guard Monitor" button as fallback. Without this, tapping the link just opens a
browser. (Once Universal/App Links below are wired, the https link opens the app directly and
this redirect becomes a fallback for older setups.)

---

## 🧪 Testing without sending a real link

Install a build, then fire the scheme directly:

**iOS simulator:**
```
xcrun simctl openurl booted "ironlock://shift-access/<64-char-hex-token>"
```
**Android (device/emulator):**
```
adb shell am start -a android.intent.action.VIEW -d "ironlock://shift-access/<64-char-hex-token>"
```
Use a **real, unused** token the backend generated (single-use). The app should show
"Signing in…", redeem, and land on the home screen `checked_in`. A bad/used token shows the
mapped error on the login screen.

> Run a **debug** build attached to see the `[deeplink]` logs.

---

## ⏭️ Later — Universal Links (iOS) / App Links (Android) for the raw https link

Best UX (tap the https link → app opens directly, no redirect page). Deferred because iOS
needs the Apple Developer account; do it alongside the APNs work in `IOS_PARITY_TODO.md`.

1. **iOS:** add the **Associated Domains** entitlement (`applinks:dashboard.ironlock.co.uk`)
   — needs the Apple Developer account + provisioning. Server must serve
   `/.well-known/apple-app-site-association` (JSON, no extension, `application/json`) listing
   the app's `appID` (`<TeamID>.com.ironlock.guardmonitor`) with path `/m/shift-access/*`.
2. **Android:** add an `autoVerify="true"` https intent-filter for the host, and serve
   `/.well-known/assetlinks.json` with the app's package + signing-cert SHA-256.
3. Both can coexist with the custom scheme already in place — the scheme stays as a fallback.

---

## ⚠️ Known minor edge cases (acceptable for now)

- **Tapping a link while already signed in** replaces the session (new tokens/profile/shift
  fetch) but does **not** tear down the previous shift's GPS/FCM. Uncommon; revisit if needed.
- The deep-link redeem ignores any URL that isn't a valid 64-hex shift-access token, so other
  `ironlock://` URLs can't trigger a sign-in.
