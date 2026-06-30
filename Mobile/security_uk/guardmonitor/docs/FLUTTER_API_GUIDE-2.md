# IronLock Mobile API - Flutter Integration Guide

**Status:** ✅ **READY FOR INTEGRATION** - All endpoints tested and verified

This guide covers the complete mobile API backend that's ready for your Flutter app integration.

## 🚀 Quick Start

**Base URL:** `http://your-domain.com/api/mobile/v1`  
**Authentication:** Bearer JWT tokens  
**Content-Type:** `application/json`

## 📱 Authentication Flow

### 1. Login
**POST** `/auth/login`

**Request:**
```json
{
  "identifier": "GRD6583",  // employee_code OR email
  "password": "password123",
  "device": {               // Optional - for audit logs
    "device_id": "unique-device-id",
    "device_name": "iPhone 15 Pro"
  }
}
```

**Success Response (200):**
```json
{
  "success": true,
  "data": {
    "token_type": "Bearer",
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGci...",
    "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGci...",
    "expires_at": "2026-06-15T14:30:00.000000Z",
    "hmac_secret": "f3a9...c1",
    "guard": {
      "id": "123e4567-e89b-12d3-a456-426614174000",
      "employee_code": "GRD6583",
      "first_name": "John",
      "last_name": "Smith",
      "username": "john.smith",
      "email": "john.smith@company.com",
      "phone": "+1234567890",
      "sia_licence_number": "SIA123456",
      "sia_licence_expiry": "2027-12-31",
      "sia_licence_type": "Door Supervision",
      "employment_status": "active"
    }
  },
  "meta": {
    "timestamp": "2026-06-15T12:30:00.000000Z"
  }
}
```

> **`hmac_secret` (Phase 4).** 64-char hex shared key for signing photo uploads. Returned on every login — store it in **secure storage** (never plain prefs/logs). See **Photo Verification** below.

**⚠️ IMPORTANT: Login Window Rules (updated 2026-06-17)**

A guard can sign in only:
- **During an active shift**, OR
- **Within ±15 minutes of a scheduled shift's start** — i.e. from `scheduled_start − 15 min` to `scheduled_start + 15 min`, OR
- Any time a supervisor has **authorized a late check-in** (see *Missed shifts & recovery* below).

> The **15-minute** window is a server config value (`check_in_window_minutes`) and may change. Don't hard-code 15 in the app — read the time from `details.window_opens_at` when present, and show the server `message` text as-is.

**Login = check-in (not start).** A successful login during the window automatically moves the matched shift to **`checked_in`**. The guard still has to press **Start** to make it `active`. So the flow is:

```
scheduled ──(login within window)──▶ checked_in ──(press Start)──▶ active ──(press End)──▶ completed
```

**Error Response - Wrong Credentials (401):**
```json
{
  "success": false,
  "error": {
    "code": "INVALID_CREDENTIALS",
    "message": "Invalid credentials."
  }
}
```

**Error Response - Outside Login Window (403).** The `code` is always `LOGIN_WINDOW_CLOSED`; use `details.reason` to decide what to show:

`reason: "too_early"` — the window hasn't opened yet. Show a countdown to `window_opens_at`:
```json
{
  "success": false,
  "error": {
    "code": "LOGIN_WINDOW_CLOSED",
    "message": "You can sign in from 11:21 — 15 minutes before your 11:36 shift.",
    "details": {
      "reason": "too_early",
      "window_opens_at": "2026-06-15T11:21:38.000000Z",
      "next_shift_start": "2026-06-15T11:36:38.000000Z"
    }
  }
}
```

`reason: "expired"` — the window has passed (guard is late). Tell them to contact their supervisor; there is no countdown:
```json
{
  "success": false,
  "error": {
    "code": "LOGIN_WINDOW_CLOSED",
    "message": "The allowed check-in period for this shift has expired. Please contact your supervisor for assistance.",
    "details": {
      "reason": "expired",
      "window_opens_at": null,
      "next_shift_start": null
    }
  }
}
```

`reason: "no_shift"` — the guard has no upcoming shift at all:
```json
{
  "success": false,
  "error": {
    "code": "LOGIN_WINDOW_CLOSED",
    "message": "You have no upcoming shift. You can sign in 15 minutes before your next scheduled shift.",
    "details": {
      "reason": "no_shift",
      "window_opens_at": null,
      "next_shift_start": null
    }
  }
}
```

> **All datetimes are UTC** (ISO-8601 with a trailing `Z`). Parse them as UTC and convert to the guard's local timezone for display — never assume the device or server timezone. The human-readable times inside `message` are already localized to the company's configured timezone for convenience.

**Error Response - Account Locked (423):**
```json
{
  "success": false,
  "error": {
    "code": "ACCOUNT_LOCKED", 
    "message": "Account locked. Contact your supervisor."
  }
}
```

### 2. Token Refresh
**POST** `/auth/refresh`

**Request:**
```json
{
  "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGci..."
}
```

**Success Response (200):**
```json
{
  "success": true,
  "data": {
    "token_type": "Bearer",
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGci...", // New access token
    "expires_at": "2026-06-15T16:30:00.000000Z"     // 2 hours from now
  }
}
```

### 3. Logout
**POST** `/auth/logout`
**Headers:** `Authorization: Bearer {access_token}`

**Success Response (200):**
```json
{
  "success": true,
  "data": {
    "message": "Logged out."
  }
}
```

### 4. Shift Access Link (SSO — passwordless sign-in)

Some guards sign in with a **one-time link** instead of typing credentials. A supervisor generates it from the dashboard and sends it to the guard (WhatsApp / SMS / email). The link signs the guard in to **one specific shift** without a password — but it still passes **every other login rule**: employment status, account lock, and the **same shift login window** as a normal login. It is **single-use** and **auto-expires 1 hour** after it is generated (used or not).

> **It removes the password step only — nothing else.** So a valid link tapped outside the shift window still returns `LOGIN_WINDOW_CLOSED` with the exact same shape as a password login. Reuse your existing login handlers; don't fork the logic.

**The link looks like:**
```
https://<your-domain>/m/shift-access/<token>
```
`<token>` is the **last path segment** — a 64-char hex string. That's the only part you need.

#### How the link opens the app (backend bridge — LIVE)

The supervisor sends an **https** link. The backend serves a tiny page at `GET /m/shift-access/{token}` that **auto-redirects the browser into the app's custom scheme** and shows an "Open in Guard Monitor" fallback button:

```
https://<domain>/m/shift-access/<token>   →   ironlock://shift-access/<token>
```

So the app only needs to handle the **custom scheme** `ironlock://shift-access/<token>`. The scheme prefix is configurable server-side (`IRONLOCK_SHIFT_ACCESS_APP_SCHEME`, default `ironlock://shift-access/`) — keep it in sync with the app. The page never consumes or validates the token; redemption happens only when the app calls `POST /auth/shift-access`.

> Universal Links / App Links (so the https link opens the app **directly**, no bounce page) are a later add — they need the Apple Team ID and the Android signing SHA-256. Until then, the bounce page is the path and the custom scheme is what the app registers.

#### What you must build (deep linking)

1. Register the app for the custom scheme `ironlock://shift-access/<token>` (the backend bounce page above sends the browser there).
2. When the app opens from the link, **extract the token** (the last path segment).
3. **Redeem it** (below). On success, store the tokens + `hmac_secret` **exactly as you do for `/auth/login`**, then run your normal post-login flow (`GET /shifts/current`, …). The guard is already moved to `checked_in` server-side — identical to a password login.
4. On any failure, show the error message and **route back to your login screen**.

#### Redeem — POST `/auth/shift-access`

Public endpoint, **no auth header** (it mints the session). Throttled 10/min per IP, like login.

**Request:**
```json
{
  "token": "eea5367f3261798777ad69d8aa651e6731f663d7810ca60edf358700f9fc150f",
  "device": {                 // Optional — same as login, for audit logs
    "device_id": "unique-device-id",
    "device_name": "iPhone 15 Pro"
  }
}
```

**Success Response (200)** — **identical shape to `/auth/login`.** Store it the same way; the guard is now `checked_in`, so go straight to `GET /shifts/current`:
```json
{
  "success": true,
  "data": {
    "token_type": "Bearer",
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGci...",
    "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGci...",
    "expires_at": "2026-06-26T14:30:00.000000Z",
    "hmac_secret": "f3a9...c1",
    "guard": { "id": "...", "employee_code": "GRD6583", "first_name": "John", "...": "..." }
  },
  "meta": { "timestamp": "2026-06-26T12:30:00.000000Z" }
}
```
> No shift object is returned here — exactly like login. Call `GET /shifts/current` right after to get the shift `id`/status, same flow as a password login.

**Failure responses** — show the message and send the guard to the login screen. Branch on `error.code`:

| `code` | Status | Why | What to show |
|---|---|---|---|
| `SHIFT_ACCESS_INVALID` | 401 | Unknown/bad token, **or** it was superseded by a newer link the supervisor generated | "This access link is invalid. Ask your supervisor for a new one." |
| `SHIFT_ACCESS_EXPIRED` | 401 | Older than 1 hour | "This link has expired. Ask your supervisor for a new one." |
| `SHIFT_ACCESS_USED` | 401 | Already used once (single-use) | "This link has already been used." |
| `SHIFT_ACCESS_SHIFT_INVALID` | 401 | The shift was completed or cancelled | "This shift is no longer available." |
| `SHIFT_ACCESS_UNAUTHORIZED` | 403 | The link isn't for this guard (e.g. shift reassigned) or the account isn't active | server `message` |
| `ACCOUNT_LOCKED` | 423 | Account locked | server `message` ("contact your supervisor") |
| `LOGIN_WINDOW_CLOSED` | 403 | Valid link, but outside the shift window — **same payload as login**; branch on `details.reason` (`too_early` → countdown to `window_opens_at`; `expired` → contact supervisor) | reuse your existing login-window handling |
| `VALIDATION_ERROR` | 422 | No `token` in the body | — |
| `RATE_LIMITED` | 429 | Too many attempts | "Please try again in a moment." |

> **Single-use means single-use.** Never auto-retry a token. If the guard taps the same link twice or reopens it later, you'll get `SHIFT_ACCESS_USED` (or `SHIFT_ACCESS_EXPIRED`) — treat that as "ask your supervisor for a fresh link," not a transient error to retry.

**Dart sketch:**
```dart
// Called from your deep-link handler (uni_links / app_links / GoRouter, …)
Future<void> handleShiftAccessLink(Uri uri) async {
  final token = uri.pathSegments.isNotEmpty ? uri.pathSegments.last : '';
  if (token.isEmpty) { goToLogin(); return; }

  final res = await http.post(
    Uri.parse('$baseUrl/auth/shift-access'),
    headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
    body: jsonEncode({'token': token, 'device': deviceInfo}),
  );
  final body = jsonDecode(res.body);

  if (res.statusCode == 200 && body['success'] == true) {
    await saveSession(body['data']);   // SAME as login: access/refresh tokens + hmac_secret
    await loadCurrentShift();          // GET /shifts/current
    goToHome();
  } else {
    final err = body['error'] ?? {};
    // Route straight into your existing login error handler — incl.
    // LOGIN_WINDOW_CLOSED with details.reason (too_early / expired).
    showLoginError(err['code'], err['message'], err['details']);
    goToLogin();
  }
}
```

## 👤 Guard Profile

### Get Current Guard Profile
**GET** `/me`
**Headers:** `Authorization: Bearer {access_token}`

**Success Response (200):**
```json
{
  "success": true,
  "data": {
    "guard": {
      "id": "123e4567-e89b-12d3-a456-426614174000",
      "employee_code": "GRD6583",
      "first_name": "John",
      "last_name": "Smith",
      "username": "john.smith", 
      "email": "john.smith@company.com",
      "phone": "+1234567890",
      "sia_licence_number": "SIA123456",
      "sia_licence_expiry": "2027-12-31", // null if no expiry
      "sia_licence_type": "Door Supervision",
      "employment_status": "active"
    }
  }
}
```

## 🕐 Shift Management

### Get Current Shift
**GET** `/shifts/current`
**Headers:** `Authorization: Bearer {access_token}`

Returns the guard's active shift, or the next relevant `scheduled`/`checked_in` shift that hasn't ended, or `null` if none. **Missed shifts are never returned here** — once a shift is `missed` it requires supervisor recovery (see below), so the app should treat "no current shift" plus a `LOGIN_WINDOW_CLOSED`/`expired` login as the "you missed it, contact supervisor" state.

**Success Response (200):**
```json
{
  "success": true,
  "data": {
    "shift": {
      "id": "shift-uuid-123",
      "reference": "SH-2847",  // human-readable display code; the UUID id is still the key
      "status": "checked_in",  // see Shift Statuses table below
      "scheduled_start": "2026-06-15T12:00:00.000000Z",
      "scheduled_end": "2026-06-15T20:00:00.000000Z",
      "actual_start": null,   // Set when shift starts
      "actual_end": null,     // Set when shift ends
      "can_start": true,      // Server-computed: can start now?
      "can_end": false,       // Server-computed: normal end allowed? (only true once scheduled_end has passed)
      "can_request_early_end": true,  // Server-computed: active & before scheduled_end → may request to leave early
      "early_end_request": null,      // Populated while an early-end request is outstanding (see below)
      "site": {
        "id": "site-uuid-456",
        "name": "Shopping Mall West",
        "grace_period_minutes": 15
      },
      "geofence": {
        "id": "geofence-uuid-789",
        "name": "Mall Perimeter",
        "coordinates": [        // Polygon coordinates for map display
          [-1.2345, 51.5678],  // [longitude, latitude] pairs
          [-1.2346, 51.5679],
          [-1.2347, 51.5680],
          [-1.2345, 51.5678]   // Closed polygon
        ]
      }
    }
  }
}
```

**No Current Shift:**
```json
{
  "success": true,
  "data": {
    "shift": null
  }
}
```

### Shift Statuses

| `status` | Meaning | Dashboard colour | Guard app cue |
|----------|---------|------------------|----------------|
| `scheduled` | Assigned, guard not yet checked in | grey/blue | Show shift card; Start disabled until window opens |
| `checked_in` | Guard signed in within the window (login ≠ start) | amber | Show **Start** button (within window) |
| `active` | Shift started — guard on duty | green | Show **End** button |
| `completed` | Shift ended (carries `end_type`: `guard`=normal, `early`=approved early end, `auto`=server-closed) | blue | Done |
| `cancelled` | Cancelled by admin (incl. an **excused** miss) | red/dashed | Not actionable |
| `missed` | Window expired without check-in/start | red | Not returned by `/shifts/current`; guard sees the "contact supervisor" path |

> The app only ever **drives** `scheduled → checked_in` (via login) and `checked_in → active → completed` (via Start/End). `missed` and `cancelled` are produced by the server / supervisor, never by the app.

### How the app gets the shift ID (the key step)

**After login**, call `GET /shifts/current`. The response includes the guard's current shift object with an `id` field. **Store that `id` — it's what you pass to the Start and End URLs.** The server picks the right shift automatically based on the guard's JWT token + their schedule; the app just shows the shift card and calls Start/End using that ID.

> **`reference` vs `id`.** Each shift also has a `reference` like `SH-2847` — a friendly code you can **show** to the guard (e.g. on the shift card). It is **display-only**: never put it in a URL or use it as a key. The Start/End endpoints always take the UUID `id`.

```
1. POST /auth/login  →  success (guard is checked_in on their shift)
2. GET  /shifts/current  →  { shift: { id: "abc-123", status: "checked_in", can_start: true, ... } }
3. Guard taps Start button  →  POST /shifts/abc-123/start  (no body, just the auth header)
4. GET  /shifts/current  →  { shift: { id: "abc-123", status: "active", can_end: true, ... } }
5. Guard taps End button   →  POST /shifts/abc-123/end    (body: { "ended_early": false })
```

> **Start takes no body.** You don't send start timestamps — the server stamps `actual_start` from its own clock (UTC). **End takes a small JSON body** (`ended_early` plus, for an early end, a reason/note) — see the End Shift section below.

> **Ending early needs supervisor approval.** A guard may only end normally **once `scheduled_end` has passed** (`can_end` is `true`). To leave before then, the app POSTs `/early-end-request`, the shift stays `active`, and the guard waits for a supervisor to approve from the dashboard. The decision arrives via the `early_end_request` object on `/shifts/current` (poll it). Only after approval does `POST /end` with `ended_early:true` succeed.

Always use the `can_start` / `can_end` / `can_request_early_end` flags + the `early_end_request` object from `/shifts/current` to drive the buttons — never compute the time window or self-approve client-side.

---

### Start Shift
**POST** `/shifts/{shift-id}/start`  
**Headers:** `Authorization: Bearer {access_token}`  
**Request body:** *(none — no body required)*

Allowed from `scheduled` **or** `checked_in`, any time up to `scheduled_start + 15 min` (the same configurable window), or while a supervisor late-check-in authorization is open. Gate on the server-computed `can_start` flag from `/shifts/current` rather than computing the window yourself.

**Success Response (200):**
```json
{
  "success": true,
  "data": {
    "shift": {
      "id": "shift-uuid-123",
      "reference": "SH-2847",
      "status": "active",
      "actual_start": "2026-06-15T12:05:00.000000Z",
      "can_end": true
    }
  }
}
```

**Error - Start Window Expired (409).** The guard was checked in / scheduled but the start window passed. Show the "contact your supervisor" message — the shift will be (or already is) `missed`:
```json
{
  "success": false,
  "error": {
    "code": "START_WINDOW_EXPIRED",
    "message": "Your shift start window has expired. Please contact your supervisor to initiate the shift."
  }
}
```

**Error - Cannot Start (409).** Generic not-startable state (e.g. wrong status, too early):
```json
{
  "success": false,
  "error": {
    "code": "SHIFT_NOT_STARTABLE",
    "message": "This shift cannot be started right now."
  }
}
```

### Request Early End
**POST** `/shifts/{shift-id}/early-end-request`
**Headers:** `Authorization: Bearer {access_token}`
**Request body:**
```json
{
  "reason": "Illness",
  "note": "Felt unwell, need to leave at 14:30."
}
```
| Field | Type | Notes |
|-------|------|-------|
| `reason` | string | Required. One of: `Incident / Emergency`, `Illness`, `Relieved early`, `Site closed`, `Other`. |
| `note` | string | Optional free text (≤ 500 chars). |

Use this when the guard taps End **before** `scheduled_end`. It does **not** end the shift — it records a pending request and the shift stays `active`. Lock the End button and poll `/shifts/current`; the `early_end_request.status` will move to `approved` or `rejected`. Returns the shift payload (same shape as `/shifts/current`) with `early_end_request` populated.

**Error - Not Applicable (409).** The shift isn't active, or `scheduled_end` has already passed (just call `/end` normally instead):
```json
{ "success": false, "error": { "code": "EARLY_END_NOT_APPLICABLE", "message": "An early-end request can only be made during an active shift before its scheduled end." } }
```

### End Shift
**POST** `/shifts/{shift-id}/end`
**Headers:** `Authorization: Bearer {access_token}`
**Request body:**
```json
{
  "ended_early": false
}
```
| Field | Type | Notes |
|-------|------|-------|
| `ended_early` | bool | Always send. `false` = normal end (only allowed once `scheduled_end` has passed). `true` = early end (only allowed after a supervisor **approved** the request). |
| `reason` | string | Send on an early end. The server uses the approved request's reason on file; this is a fallback. |
| `note` | string | Optional, same as above. |

The server stamps `actual_end` from its own clock and records an `end_type` (`guard` = normal, `early` = approved early end). The `{shift-id}` is the same `id` you got from `/shifts/current`.

**Success Response (200):**
```json
{
  "success": true,
  "data": {
    "shift": {
      "id": "shift-uuid-123",
      "reference": "SH-2847",
      "status": "completed",
      "end_type": "guard",
      "actual_start": "2026-06-15T12:05:00.000000Z",
      "actual_end": "2026-06-15T20:10:00.000000Z",
      "duration_hours": 8.08  // Rounded to 2 decimal places
    }
  }
}
```

**Error - Cannot End (409).** Shift is not currently active (already completed, or not yet started):
```json
{
  "success": false,
  "error": {
    "code": "SHIFT_NOT_ENDABLE",
    "message": "This shift is not active."
  }
}
```

**Error - Too Early (409).** `ended_early:false` but `scheduled_end` hasn't passed yet. Use the early-end request flow instead:
```json
{
  "success": false,
  "error": {
    "code": "END_BEFORE_SCHEDULED",
    "message": "You cannot end this shift before its scheduled end time. Request an early end if you need to leave."
  }
}
```

**Error - Early End Not Approved (409).** `ended_early:true` but no supervisor has approved the request. Keep the End button locked and keep polling `/shifts/current`:
```json
{
  "success": false,
  "error": {
    "code": "EARLY_END_NOT_APPROVED",
    "message": "Your early-end request has not been approved by a supervisor."
  }
}
```

> **Auto-close (server safety net).** If a guard never ends a shift (dead/offline phone), the server force-closes it a configurable grace period after `scheduled_end` (`end_type: "auto"`). The app does nothing here — it just won't see the shift on `/shifts/current` anymore.

### `early_end_request` on `/shifts/current`

While a request is outstanding, `/shifts/current` includes an `early_end_request` object (omitted/`null` when there's none). Drive the UI from `status`:

```json
"early_end_request": {
  "status": "pending",          // pending | approved | rejected
  "reason": "Illness",
  "note": "Felt unwell…",
  "requested_at": "2026-06-22T14:05:00.000000Z",
  "decided_at": null,
  "decided_by": null
}
```

| `status` | App behaviour |
|----------|---------------|
| `pending` | End locked — "waiting for supervisor approval"; keep working. |
| `approved` | End unlocks — tap End → `POST /end` with `ended_early:true`. |
| `rejected` | Tell the guard; allow a fresh request, or work to `scheduled_end`. |
| absent / `null` | No outstanding request — normal behaviour. |

### Missed shifts & recovery (what the app should do)

If a guard misses the check-in/start window, the server marks the shift `missed` and the guard is locked out with either `LOGIN_WINDOW_CLOSED` (`reason: "expired"`) at login or `START_WINDOW_EXPIRED` at start. In both cases show **"Please contact your supervisor."**

Recovery is **pull-based — no push notification yet** (that's a later phase). The supervisor resolves the miss from the admin dashboard. Depending on what they choose:

- **Authorize late check-in** → the shift reopens and a time-boxed grace window starts. The guard simply **retries Sign In** — the login window check now passes, the shift goes `checked_in`, and they can Start. So after showing "contact supervisor", let the guard **try logging in again** (e.g. a "Try again" button); no special endpoint is needed.
- **Excuse** → shift becomes `cancelled`; nothing for the guard to do.
- **Reassign** → shift moves to another guard; the original guard won't see it on `/shifts/current`.
- **Confirm no-show** → shift stays `missed`; the guard stays locked out for it.

There is **no mobile endpoint** for any of this — the app's only job is to surface the message and allow a re-login attempt. There's nothing to poll.

## 📍 GPS Location Tracking

### Send Location Pings
**POST** `/shifts/{shift-id}/locations`
**Headers:** `Authorization: Bearer {access_token}`

Send the guard's GPS position while a shift is **active**. Steady state is **one ping every 15 seconds**; the endpoint also accepts a **batch** so an app that briefly buffered fixes (e.g. a short connectivity blip) can flush them in a single call. Each ping is geofence-checked server-side and updates the guard's single live position on the dashboard map — there is **no location history**, only the latest fix per guard.

> Works only for an **active** shift owned by the authenticated guard. Before the shift is `active` (or once it ends) the endpoint returns `409 SHIFT_NOT_ACTIVE`. Use the same UUID `id` you got from `/shifts/current`.

**Request body:**
```json
{
  "pings": [
    {
      "latitude": 51.5074,
      "longitude": -0.1278,
      "accuracy": 8.5,
      "battery": 0.87,
      "recorded_at": "2026-06-24T14:05:00.000Z"
    }
  ]
}
```

| Field | Type | Notes |
|-------|------|-------|
| `pings` | array | Required, non-empty. One or more ping objects, **in chronological order**. |
| `latitude` | float | Required. Decimal degrees (WGS-84). |
| `longitude` | float | Required. Decimal degrees (WGS-84). |
| `accuracy` | float | Optional. Horizontal accuracy in metres. |
| `battery` | float | Optional. Battery as a **0.0–1.0 fraction** (e.g. `0.87` = 87%) — **not** a percentage. |
| `recorded_at` | string | Optional. Device capture time (ISO-8601 UTC). Audit only — the **server** stamps the authoritative "last seen" from its own clock. |

**Success Response (200):**
```json
{
  "success": true,
  "data": {
    "results": [
      {
        "recorded_at": "2026-06-24T14:05:00.000Z",
        "zone_status": "INSIDE_ZONE",
        "requires_alert": false
      }
    ]
  },
  "meta": { "timestamp": "2026-06-24T14:05:01.000000Z" }
}
```

`results` maps one-to-one to `pings`, in the same order:

| Field | Type | Notes |
|-------|------|-------|
| `recorded_at` | string | Echoes the ping's `recorded_at` (or server time if omitted). |
| `zone_status` | string | `INSIDE_ZONE` or `OUTSIDE_ZONE` — the server geofence result for that fix. `null` if the ping was rejected. |
| `requires_alert` | bool | `true` when the guard is currently **outside** the geofence. Informational only — the grace-period **zone-exit alert is raised server-side**; the app doesn't manage or send it. The app may show a local "return to zone" hint. |
| `error` | string | Present only on a **rejected** ping (e.g. `"Invalid coordinates"`). |

> **A bad ping doesn't fail the batch.** A ping with non-numeric `latitude`/`longitude` is skipped with an `error` in its result slot — HTTP is still `200`, and the valid pings around it are recorded normally.

**Error - No Active Shift (409):**
```json
{
  "success": false,
  "error": {
    "code": "SHIFT_NOT_ACTIVE",
    "message": "No active shift found with this ID."
  }
}
```
Returned when the shift isn't `active`, doesn't exist, or belongs to another guard. Refresh `/shifts/current` and only ping while `status` is `active`.

**Error - Empty Payload (422):**
```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "pings must be a non-empty array."
  }
}
```

### Comms-interrupted (server-side)
If the server stops receiving pings for **more than ~30 s** (two missed 15 s intervals), the guard is shown as **⊘ Comms Interrupted** on the dashboard — distinct from a zone exit, and it never raises a zone-exit alert on its own. Keep the 15 s cadence while a shift is active so the guard stays "live". The app calls nothing special here; resuming pings clears it automatically.

> **Offline GPS queue (Phase 7 — now live server-side).** The batch field is the
> **offline-flush path**: buffer pings on-device while disconnected and POST the backlog
> in one call on reconnect. The server records the gap (`COMMS_GAP_START/END` +
> `SYNC_FLUSH`) and applies the backlog **without raising retroactive alerts** — a
> zone-exit you already returned from in the gap does **not** page anyone; only a breach
> still true *now* alerts. Re-sending the same pings is safe (idempotent UPSERT). The
> on-device encrypted queue + flush/retry is the **app's** job — see
> `PHASE_7_FLUTTER_OFFLINE_SYNC.md` for the full client contract.

## 📸 Photo Verification (Phase 4 — LIVE)

Proves the guard is physically present *right now*. The server decides liveness from **its own clock + a nonce** — your device clock is never trusted. Full contract: `MOBILE_API_INTEGRATION.md` §6.3–6.6.

### The two flows

**Online (server-initiated):** an admin or the scheduler raises a request; you receive it by push **or** by polling `GET /shifts/{id}/photos/pending`. The request carries `request_id` + `nonce_value` + `issued_at` + `response_seconds` (default **90 s**). The nonce and the timeout are the **same** window now, so anchor your countdown to `issued_at` and you have exactly `response_seconds` to capture **and** upload before it both fails (`NONCE_EXPIRED`) and raises a CRITICAL alert against the guard.

**Offline (self-initiated):** while online, keep a **pool of nonces** topped up (`POST /shifts/{id}/nonces/prefetch`). Each pool nonce is single-use and valid **15 min**. When offline, draw one, capture, queue, and upload when connectivity returns — **omit `request_id`**, just send `nonce_value`. The server reconstructs the capture time and accepts if it's within the nonce's 15-min window.

### `hmac_secret`
Returned in the **login response** (see Authentication). It's a 64-char hex shared key. **Store it in secure storage** (`flutter_secure_storage`), never in plain prefs/logs. Every photo upload is signed with it.

### Signing a photo (critical — must match byte-for-byte)
HMAC-SHA256 over six fields joined by `\n`, in this exact order, lowercase hex:
```
nonce_value \n request_id \n captured_at \n latitude \n longitude \n sha256_hex(image_bytes)
```
`request_id` is `""` for offline checks; `latitude`/`longitude` are `""` if omitted. Send the **same strings** you signed.
```dart
final imageBytes = await file.readAsBytes();
final message = [
  nonceValue, requestId ?? '', capturedAtIso,
  lat?.toString() ?? '', lng?.toString() ?? '',
  sha256.convert(imageBytes).toString(),
].join('\n');
final signature = Hmac(sha256, utf8.encode(hmacSecret)).convert(utf8.encode(message)).toString();
```

### Upload — `POST /shifts/{id}/photos` (`multipart/form-data`)
Fields: `photo` (file, jpeg/png ≤10 MB), `nonce_value`, `signature`, `captured_at`, `latitude`, `longitude`, optional `request_id` (online only), `exif_timestamp`, `ntp_timestamp`, or offline `ntp_reference` + `elapsed_seconds`.

**Multiple images (1–5) per request.** A guard may answer one verification request with up to **5 photos** in a single upload. Send arrays instead of the single fields:
- `photos[]` — 1 to 5 image files (each jpeg/png ≤10 MB).
- `signatures[]` — one signature per image, in the **same order** as `photos[]`. Each signature is the normal HMAC over **that image's** bytes — only the `sha256_hex(image_bytes)` field differs per image; `nonce_value` / `request_id` / `captured_at` / `latitude` / `longitude` are identical across them. One nonce covers the whole submission and is consumed once.
- All other fields (`nonce_value`, `request_id`, GPS, NTP/EXIF, …) are shared and sent once.

The single `photo` + `signature` form **still works unchanged** (it's just the 1-image case). **All-or-nothing:** if any image's signature is invalid, or the nonce/timeline check fails, nothing is stored (`PHOTO_REJECTED`) — exactly as today. Min 1, max 5.

**Success `200`:** `{ "data": { "result": "VALIDATED" | "FLAGGED", "flags": [...], "count": 3 } }` — both are accepted/stored. `FLAGGED` means stored-with-anomalies for admin review. `count` is how many images were stored (`1` for a legacy single upload).

**Rejected `422` `PHOTO_REJECTED`** (not stored): branch on `error.details.reason` — `NONCE_NOT_FOUND`, `NONCE_ALREADY_USED`, `NONCE_EXPIRED`, `TIMELINE_ANOMALY`, `HMAC_INVALID`, `REQUEST_NOT_FOUND`. On `HMAC_INVALID`, re-login to refresh `hmac_secret` then retry.

### Register for push — `POST /devices/push-token`
`{ "push_token": "...", "platform": "ios" | "android" }`. Best-effort: requests are created server-side even if push fails, so the `pending` poll is your reliable fallback.

### Review outcomes — `GET /shifts/{id}/photos/reviews`
After upload, a supervisor **approves or rejects** each photo. Poll this to learn the result (also pushed as `PHOTO_REVIEWED` — see below). Returns one entry per reviewed photo, newest first:
```json
{ "data": { "reviews": [
  { "request_id": "...", "decision": "APPROVED" | "REJECTED", "note": "…or null", "reviewed_at": "2026-06-25T14:31:02.000000Z" }
] } }
```
Correlate `request_id` with the photo you submitted. Works after the shift ends too (reviews can land later); a 404 `SHIFT_NOT_FOUND` means it isn't this guard's shift. Surface `REJECTED` to the guard (with the note) so they know to expect follow-up.

---

## 🔔 Push Notifications (FCM — Phase 6)

The backend sends three types of push notifications to the guard's device. All are **best-effort** — the server creates the underlying record first and the app can always fall back to polling, so a missed push is never fatal. All data values arrive as **strings** (FCM data-only payloads are `string → string` maps).

### Notification structure

Every push has two layers — the visible notification and the data payload your app handles:

```
notification.title  →  shown in the system tray
notification.body   →  shown in the system tray
data.*              →  key/value strings your app handler reads
```

On **Android** the push arrives at `HIGH` priority with the default sound.  
On **iOS** (APNs) it arrives at `apns-priority: 10` (immediate) with sound + badge `1`.

---

### Type 1 — `WAKEFULNESS_CHALLENGE`

Sent when the server dispatches an **online** wakefulness code-challenge — either automatically on the randomised schedule **or** when a supervisor triggers one manually from the dashboard. Both are identical on the wire: same payload, same handling, nothing new app-side.

**Notification:**
```
title:  "Wakefulness check"
body:   "Open the app and enter code {CODE} within 60 seconds."
```

**Data payload:**
```json
{
  "type":             "WAKEFULNESS_CHALLENGE",
  "check_id":         "<uuid>",
  "shift_id":         "<uuid>",
  "code":             "4821",
  "response_seconds": "60"
}
```

| Field | Type | Notes |
|---|---|---|
| `type` | string | Always `"WAKEFULNESS_CHALLENGE"` — use this to route the push in your handler |
| `check_id` | string (UUID) | Pass to `POST /wakefulness/{check_id}/respond` and `POST /wakefulness/{check_id}/received` |
| `shift_id` | string (UUID) | The guard's current shift |
| `code` | string | The 4-digit code the guard must transcribe |
| `response_seconds` | string | How many seconds the guard has to respond (always `"60"` currently — parse as int) |

**Required app actions on receipt:**
1. **Fire-and-forget** `POST /wakefulness/{check_id}/received` immediately — tells the server the push landed. Without this, a push that drops in transit looks like an unresponsive guard.
2. Show a **foreground overlay / local notification** with the 4-digit code and a countdown timer.
3. Call `POST /wakefulness/{check_id}/respond` with `{ "code": "...", "responded_at": "..." }` when the guard submits.

> If the app is **backgrounded**, surface it via a local notification so the guard can tap in. The code is already in `data.code` — you don't need to fetch anything.

---

### Type 2 — `PHOTO_REQUEST`

Sent when an admin (or the scheduler) raises a live photo verification request.

**Notification:**
```
title:  "Photo required"
body:   "Open the app now to capture a live verification photo."
```

**Data payload:**
```json
{
  "type":             "PHOTO_REQUEST",
  "request_id":       "<uuid>",
  "shift_id":         "<uuid>",
  "nonce_value":      "<hex-string>",
  "issued_at":        "2026-06-25T14:05:00.000000Z",
  "response_seconds": "90"
}
```

| Field | Type | Notes |
|---|---|---|
| `type` | string | Always `"PHOTO_REQUEST"` |
| `request_id` | string (UUID) | Pass as `request_id` in the photo upload multipart body |
| `shift_id` | string (UUID) | The guard's current shift |
| `nonce_value` | string | The single-use nonce for this request; pass it as `nonce_value` in the upload and include it in the HMAC signature string |
| `issued_at` | ISO-8601 UTC string | When the request was raised — the **countdown anchor**. The hard deadline is `issued_at + response_seconds`. |
| `response_seconds` | string | Window length (parse as int; default `90`). Anchor the countdown here so the guard never uploads past the server's deadline. |

**Required app actions on receipt:**
1. Open the camera capture screen immediately.
2. Upload via `POST /shifts/{shift_id}/photos` (multipart) using `nonce_value` and `request_id` from the payload. The guard has **90 seconds** total before the request times out and a CRITICAL alert fires.
3. If the app was backgrounded and the guard misses the window, show a "verification request expired" message — no retry, a new request must come from the admin side.

> **Push is not the only delivery path.** The photo request also shows up on `GET /shifts/{id}/photos/pending` — poll this (e.g. on app foreground or every 30 s while active) as a fallback for when push doesn't arrive.

---

### Type 3 — `PHOTO_REVIEWED`

Sent when a supervisor **approves or rejects** a photo the guard submitted.

**Notification:**
```
title:  "Photo approved"   |  "Photo rejected"
body:   "Your verification photo was approved."
        "Your verification photo was rejected. Tap for details."
```

**Data payload:**
```json
{
  "type":       "PHOTO_REVIEWED",
  "request_id": "<uuid>",
  "shift_id":   "<uuid>",
  "decision":   "APPROVED",
  "note":       "Clear, on-site."
}
```

| Field | Type | Notes |
|---|---|---|
| `type` | string | Always `"PHOTO_REVIEWED"` |
| `request_id` | string (UUID) | The reviewed photo request — correlate with the photo the guard uploaded |
| `shift_id` | string (UUID) | The shift the photo belongs to |
| `decision` | string | `"APPROVED"` or `"REJECTED"` |
| `note` | string | Optional supervisor note (empty string if none) |

**Required app actions on receipt:**
1. Update the photo's status in the app (e.g. the activity timeline / photo history).
2. On `REJECTED`, surface the note so the guard understands why.
3. Treat `GET /shifts/{id}/photos/reviews` as the source of truth — refresh it on receipt (the push can be lost; the poll always reflects the latest decision).

---

### Routing pushes in Flutter

```dart
FirebaseMessaging.onMessage.listen((RemoteMessage message) {
  final type = message.data['type'];
  switch (type) {
    case 'WAKEFULNESS_CHALLENGE':
      // 1. Call POST /wakefulness/{check_id}/received (fire-and-forget)
      // 2. Show overlay with code + countdown
      break;
    case 'PHOTO_REQUEST':
      // Open camera capture screen
      break;
    case 'PHOTO_REVIEWED':
      // Refresh GET /shifts/{shift_id}/photos/reviews; show decision (+ note if rejected)
      break;
  }
});

// Background / terminated: use onMessageOpenedApp or
// FirebaseMessaging.onBackgroundMessage handler — same data map, same routing.
```

> **`onBackgroundMessage` on iOS** requires the APNs auth key to be uploaded in the Firebase console — see `APNS_KEY_UPLOAD.md`. Until that ops step is done, iOS devices won't receive background pushes.

---

## 🚨 Error Handling

### Authentication Errors
| Code | Status | Description | Action |
|------|--------|-------------|---------|
| `UNAUTHENTICATED` | 401 | No token provided | Redirect to login |
| `TOKEN_INVALID` | 401 | Invalid/corrupted token | Redirect to login |
| `TOKEN_EXPIRED` | 401 | Access token expired | Try refresh token |
| `INVALID_CREDENTIALS` | 401 | Wrong username/password | Show error, allow retry |
| `ACCOUNT_LOCKED` | 423 | Account locked after failed attempts | Show contact supervisor message |
| `LOGIN_WINDOW_CLOSED` | 403 | Outside shift window — branch on `details.reason` | `too_early` → countdown to `window_opens_at`; `expired` → "contact supervisor" + allow retry; `no_shift` → "no upcoming shift" |
| `SHIFT_ACCESS_INVALID` | 401 | SSO link unknown/bad or superseded | Show "invalid link", go to login (see Shift Access Link) |
| `SHIFT_ACCESS_EXPIRED` | 401 | SSO link older than 1 hour | Show "expired link", go to login |
| `SHIFT_ACCESS_USED` | 401 | SSO link already used (single-use) | Show "already used", go to login — do **not** retry |
| `SHIFT_ACCESS_SHIFT_INVALID` | 401 | SSO link's shift completed/cancelled | Show message, go to login |
| `SHIFT_ACCESS_UNAUTHORIZED` | 403 | SSO link not for this guard / inactive | Show message, go to login |

### Business Logic Errors
| Code | Status | Description | Action |
|------|--------|-------------|--------|
| `START_WINDOW_EXPIRED` | 409 | Start window passed; shift is/▶ becomes `missed` | Show "contact your supervisor to initiate the shift" |
| `SHIFT_NOT_STARTABLE` | 409 | Can't start shift (wrong time/status) | Refresh `/shifts/current` and gate on `can_start` |
| `SHIFT_NOT_ENDABLE` | 409 | Can't end shift (not active) | Refresh `/shifts/current` and gate on `can_end` |
| `END_BEFORE_SCHEDULED` | 409 | `ended_early:false` before `scheduled_end` | Use `/early-end-request` instead |
| `EARLY_END_NOT_APPROVED` | 409 | `ended_early:true` without an approved request | Keep End locked; poll `early_end_request.status` |
| `EARLY_END_NOT_APPLICABLE` | 409 | Early-end request when not active / past `scheduled_end` | Fall back to a normal `/end` |
| `SHIFT_NOT_ACTIVE` | 409 | GPS/photo/nonce call for a shift that isn't active / not yours | Refresh `/shifts/current`; only call while `active` |
| `PHOTO_REJECTED` | 422 | Photo failed verification, **not** stored | Branch on `error.details.reason` (see Photo Verification) |
| `FORBIDDEN` | 403 | Trying to access another guard's shift | — |
| `NOT_FOUND` | 404 | Shift doesn't exist | — |

### Technical Errors
| Code | Status | Description |
|------|--------|-------------|
| `VALIDATION_ERROR` | 422 | Invalid request data |
| `RATE_LIMITED` | 429 | Too many requests |
| `SERVER_ERROR` | 500 | Internal server error |

### Error Response Format
All errors follow this structure:
```json
{
  "success": false,
  "error": {
    "code": "ERROR_CODE",        // Machine-readable code
    "message": "Human message",  // User-friendly message  
    "details": {...}            // Optional extra data
  }
}
```

## 🔧 Implementation Notes

### Token Management
- **Access tokens expire in 2 hours** - implement automatic refresh
- **Refresh tokens expire in 7 days** - force re-login after 7 days
- **Single session per guard** - logging in on new device invalidates old session
- Store tokens securely (keychain/secure storage)

### Suggested Token Refresh Strategy
```dart
// Pseudo-code
if (response.statusCode == 401 && error.code == "TOKEN_EXPIRED") {
  try {
    await refreshAccessToken();
    // Retry the original request with new token
  } catch (refreshError) {
    // Refresh failed - redirect to login
    navigateToLogin();
  }
}
```

### HTTP Headers
```
Authorization: Bearer {access_token}
Content-Type: application/json
Accept: application/json
```

### Rate Limiting
- **Login:** 10 attempts per minute per IP
- **Refresh:** 20 attempts per minute per IP
- **Other endpoints:** No specific limits (normal usage)

## 🛌 Wakefulness Verification (LIVE — Phase 5)

At randomised intervals the server challenges the guard with a **4-digit code**
they must transcribe within **60 seconds**, or a CRITICAL alert fires. Works
online (server pushes a random code via FCM) and offline (the app computes a TOTP
code locally and replays the answer on reconnection).

- **Provisioning:** `POST /shifts/{id}/start` returns a `wakefulness` block with
  the decrypted `totp_seed`, `totp_period_seconds` (30), `totp_digits` (4),
  `response_seconds` (60) and a `schedule` of challenge times. Store the seed
  securely; fire a local notification at each schedule mark when offline.
- **Offline codes:** `TOTP(seed, window)` with `window = floor(unix_time / 30)`,
  standard RFC-6238 / HMAC-SHA1, 4 digits.
- **Answer:** `POST /wakefulness/{checkId}/respond` with `{ "code": "4821", "responded_at": "..." }`
  → `{ "data": { "result": "PASSED" | "FAILED" } }`. For an offline replay add
  `"window_reference": <int>` and `"is_offline": true`. See
  `MOBILE_API_INTEGRATION.md` §6.2 for the full contract.
- **⚠️ Confirm receipt (Phase 6 — required for online challenges):** the instant
  an **online** challenge push lands, call **`POST /wakefulness/{checkId}/received`**
  (no body) **fire-and-forget**. This tells the server the push actually arrived.
  Without it, a push that drops in transit would otherwise look like a guard who
  ignored the challenge. If the app *received* the push but the guard never
  answers, that **is** a real CRITICAL; if the app never got it, the server now
  suppresses the false alarm. Idempotent — safe to call once per challenge; skip
  it for offline TOTP challenges (there's no server push to confirm).

> ✅ `POST /shifts/{id}/locations` (GPS tracking) is now **live** — see **GPS Location Tracking** above.
> ✅ Photo verification (`POST /shifts/{id}/photos`, `nonces/prefetch`, `photos/pending`, `devices/push-token`) is now **live** — see **Photo Verification** above.

## 🧪 Testing

### Health Check (No Auth Required)
**GET** `/status`

```json
{
  "status": "IronLock Mobile API Ready",
  "version": "1.0.0", 
  "timestamp": "2026-06-15T12:30:00.000000Z"
}
```

### Test Credentials
For development testing:
- **Employee Code:** `GRD6583`
- **Password:** `TestPass123!`
- **Email:** Available in guard record

## ❓ Need Help?

**Contract Reference:** See `Details/Important/MOBILE_API_INTEGRATION.md` for complete technical specification.

**Issues:** All endpoints have been tested end-to-end and are working correctly. If you encounter any issues during Flutter integration, the backend logging will help diagnose problems.

---
**📋 2026-06-29 — Phase 7: Offline Capabilities & Sync.** The server side is live; the
**on-device queue + flush/retry is the app's remaining work.** Full client contract:
**`PHASE_7_FLUTTER_OFFLINE_SYNC.md`** (server guarantees: `PHASE_7_SYNC_INTEGRITY.md`).
**What changed for the app:**
- **GPS is now the offline-flush path.** Buffer pings on-device while offline and POST
  the backlog as a `pings[]` batch on reconnect (the same `POST /shifts/{id}/locations`).
  The earlier "no offline GPS queue" note is superseded.
- **No retroactive alerts.** A backfilled backlog records history but only alarms on a
  condition still true *now* — a zone-exit you returned from during the gap pages no one.
  Just flush honestly; the server decides.
- **Everything is idempotent — re-send freely.** Duplicate GPS ping → same row;
  duplicate wakefulness answer → `ALREADY_RESOLVED`; duplicate photo → `NONCE_ALREADY_USED`.
  Treat those two codes as **success** (stop retrying); retry only transport/5xx/timeout.
- **Reconnects are recorded + shown.** A gap > 60s writes `COMMS_GAP_START/END` +
  `SYNC_FLUSH` on the shift timeline (rendered as an "offline / backfilled" band) and the
  Live Map shows "offline since / last synced". You don't send these — the server derives
  them; nothing new on the wire for it.
- **Time integrity unchanged but critical:** never send the device wall-clock as proof of
  time — preserve the TOTP `window` (wakefulness) and the NTP anchor + elapsed (photos)
  verbatim from capture. The offline wakefulness replay and offline photo nonce-pool flows
  are unchanged from Phases 5/4; Phase 7 just ties them together with GPS.

---
**📋 2026-06-26 — Shift Access Link (SSO) is live.** **What changed for the app:**
- **New `POST /auth/shift-access`** (public, throttled) — redeem a supervisor-generated one-time link to sign in **without a password**. Body: `{ "token": "<last path segment of the link>" }` (+ optional `device`). On success it returns the **same payload as `/auth/login`** (tokens + `hmac_secret` + guard) and the guard is already `checked_in` — store it identically and call `GET /shifts/current`.
- **You must add deep-link interception** (Universal Links / App Links, or a custom scheme) so the link opens the app; extract the token (last path segment) and POST it. See **Authentication Flow → Shift Access Link (SSO)**.
- The link removes only the password step — it still enforces the **same login window** and account checks, so `LOGIN_WINDOW_CLOSED` (with `details.reason`) comes back exactly as on login. New error codes: `SHIFT_ACCESS_INVALID`, `SHIFT_ACCESS_EXPIRED`, `SHIFT_ACCESS_USED`, `SHIFT_ACCESS_SHIFT_INVALID`, `SHIFT_ACCESS_UNAUTHORIZED`. Links are **single-use** and **expire in 1 hour** — never auto-retry a token; route failures to the login screen.

---
**📋 2026-06-24 — GPS tracking is live (Phase 3.3).** **What changed for the app:**
- **New `POST /shifts/{id}/locations`** — send GPS pings for an **active** shift (steady state: one every 15 s; batch-capable). Each ping returns its server-computed `zone_status` (`INSIDE_ZONE`/`OUTSIDE_ZONE`) and a `requires_alert` flag.
- **Zone exit + grace period are server-side.** The app doesn't compute or send zone-exit alerts — just keep pinging; `requires_alert:true` is informational.
- **`battery` is a 0.0–1.0 fraction**, not a percentage. `recorded_at` is device-time (audit only); the server clock is authoritative.
- New error **`SHIFT_NOT_ACTIVE`** (409) when pinging a shift that isn't active/yours. A bad coordinate is skipped per-ping (still `200`) rather than failing the batch.
- A >30 s gap in pings shows the guard as **⊘ Comms Interrupted** on the dashboard (not a zone exit). _(Offline GPS queue / backfill added in Phase 7 — see the 2026-06-29 entry below.)_

**📋 Last Updated:** 2026-06-22 — **Shift-end rework.** **What changed for the app:**
- **`POST /end` now takes a JSON body** (`{ "ended_early": false }`). A normal end is only allowed **once `scheduled_end` has passed** (`can_end`); ending before then returns `END_BEFORE_SCHEDULED`.
- **New `POST /shifts/{id}/early-end-request`** (reason + note) to leave early — it does **not** end the shift; a supervisor must approve first.
- `/shifts/current` now carries **`can_request_early_end`** and, while a request is outstanding, an **`early_end_request`** object (`pending`/`approved`/`rejected`) — poll it to drive the End button. Only an `approved` request lets `POST /end` with `ended_early:true` succeed (else `EARLY_END_NOT_APPROVED`).
- Completed shifts carry an **`end_type`** (`guard`/`early`/`auto`). The server **auto-closes** shifts left open past `scheduled_end` + grace (`end_type:"auto"`) — nothing for the app to do.
- New error codes: `END_BEFORE_SCHEDULED`, `EARLY_END_NOT_APPROVED`, `EARLY_END_NOT_APPLICABLE`.

**📋 2026-06-18** — Added a display-only **`reference`** code (e.g. `SH-2847`) to every shift payload (`/shifts/current`, Start, End). Show it if you like; keep using the UUID `id` as the key.

**2026-06-17 — Shift attendance update.** **What changed for the app:**
- Login window is now **±15 min** (configurable) around `scheduled_start`, not "10 min before → end".
- **Login now checks the guard in** (`scheduled → checked_in`); Start is a separate step (`checked_in → active`).
- `LOGIN_WINDOW_CLOSED` carries a new **`details.reason`** (`too_early` / `expired` / `no_shift`) — branch on it.
- New statuses **`checked_in`** and **`missed`**; new start error **`START_WINDOW_EXPIRED`**.
- Missed-shift recovery is supervisor-driven + **pull-based**: on an `expired` lockout, just let the guard re-login (no new endpoint, nothing to poll).

All datetimes are UTC (`...Z`) — parse as UTC, display in local time.