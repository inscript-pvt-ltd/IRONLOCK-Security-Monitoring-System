# Shift Access Link (SSO) — what the mobile app needs from the backend

**For:** backend developer · **Date:** 2026-06-26
**Context:** the app side of passwordless deep-link sign-in is **done**. For it to work
end-to-end, we need the items below. Item 1 is the **blocker**; the rest are confirmations.

---

## 1. 🔴 BLOCKER — make the link open the app (https → custom scheme redirect)

The app currently opens via the **custom scheme**:

```
ironlock://shift-access/<token>
```

But the link a supervisor sends is an **https** link:

```
https://dashboard.ironlock.co.uk/m/shift-access/<token>
```

A custom scheme is **not** auto-tappable from an https link in WhatsApp/SMS/email — tapping it
just opens a **browser**, not the app. So the page you serve at `/m/shift-access/<token>` must
**bounce the browser into the app's scheme.**

**What to do:** at `GET /m/shift-access/{token}`, return a tiny HTML page that immediately
redirects to the scheme and shows a fallback button:

```html
<!doctype html>
<html>
  <head><meta charset="utf-8"><title>Open Guard Monitor</title></head>
  <body>
    <script>
      // {token} is the same 64-char hex from the URL path.
      window.location.href = "ironlock://shift-access/{token}";
    </script>
    <a href="ironlock://shift-access/{token}">Open in Guard Monitor</a>
    <p>If nothing happens, tap the button above. Don’t have the app? Install it first.</p>
  </body>
</html>
```

- The `{token}` in the scheme must be the **exact same** last path segment from the https URL.
- Don’t consume/expire the token when serving this page — redemption happens only when the app
  calls `POST /auth/shift-access`. The page is just a doorway.
- (Optional polish) detect platform and link to the App Store / Play Store when the app isn’t
  installed.

> Once we add Universal/App Links later (needs our Apple Developer account), the https link will
> open the app **directly** and this page becomes a fallback. For now it’s required.

---

## 2. ✅ Confirm — `POST /auth/shift-access` response shape

The app calls this (public, no auth header):

```json
POST /api/mobile/v1/auth/shift-access
{ "token": "<64-char hex>", "device": { "device_id": "...", "device_name": "..." } }
```

We need the **success** response to be **identical to `/auth/login`** (the app reuses the same
parser): `token_type`, `access_token`, `refresh_token`, `expires_at`, `hmac_secret`, and the
`guard` object — and the guard already moved to `checked_in`. Please confirm `refresh_token`
and `hmac_secret` **are** included here (the app stores both; without `hmac_secret`, photo
uploads can’t be signed).

**Failure codes** the app already handles — confirm these are the exact `error.code` values
returned (same `{success:false,error:{code,message,details?}}` envelope as login):

`SHIFT_ACCESS_INVALID`, `SHIFT_ACCESS_EXPIRED`, `SHIFT_ACCESS_USED`,
`SHIFT_ACCESS_SHIFT_INVALID`, `SHIFT_ACCESS_UNAUTHORIZED`, `ACCOUNT_LOCKED`,
`LOGIN_WINDOW_CLOSED` (with `details.reason` = `too_early` | `expired`), `VALIDATION_ERROR`,
`RATE_LIMITED`.

---

## 3. ✅ Give us a real test token

To test before/while item 1 is built, please generate **one real, unused** shift-access token
(64-char hex) for a test guard + shift. Single-use, expires in 1 hour, per the guide. We fire it
straight at the scheme:

```
xcrun simctl openurl booted "ironlock://shift-access/<token>"      # iOS sim
adb shell am start -a android.intent.action.VIEW -d "ironlock://shift-access/<token>"   # Android
```

---

## 4. ⏭️ Later (only when we have the Apple Developer account) — Universal/App Links

So the **https** link opens the app directly (best UX). Not needed for launch.

- **iOS Universal Links** — serve `/.well-known/apple-app-site-association` (JSON, **no file
  extension**, `Content-Type: application/json`, over HTTPS, no redirects):
  ```json
  {
    "applinks": {
      "details": [
        { "appID": "<TEAM_ID>.com.ironlock.guardmonitor", "paths": [ "/m/shift-access/*" ] }
      ]
    }
  }
  ```
  We’ll give you `<TEAM_ID>` once the Apple account exists.

- **Android App Links** — serve `/.well-known/assetlinks.json`:
  ```json
  [{
    "relation": ["delegate_permission/common.handle_all_urls"],
    "target": {
      "namespace": "android_app",
      "package_name": "com.ironlock.guardmonitor",
      "sha256_cert_fingerprints": ["<APP_SIGNING_SHA256>"]
    }
  }]
  ```
  We’ll give you the release signing SHA-256 when we cut a signed build.

> Both must be reachable over **HTTPS** at the same domain as the link. The custom scheme stays
> as a fallback alongside these.

---

## TL;DR

| # | Item | Needed for launch? |
|---|------|---|
| 1 | `/m/shift-access/{token}` page redirects to `ironlock://shift-access/{token}` | **YES — blocker** |
| 2 | Confirm `/auth/shift-access` returns the login-shaped payload (incl. `refresh_token` + `hmac_secret`) | YES (confirm) |
| 3 | One real test token | YES (to test) |
| 4 | `apple-app-site-association` + `assetlinks.json` (Universal/App Links) | No — later |
