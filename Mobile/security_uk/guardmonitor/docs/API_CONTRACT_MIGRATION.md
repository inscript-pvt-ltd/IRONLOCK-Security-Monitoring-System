# Real-Backend API Contract — Migration Plan

**Date:** 2026-06-24
**Source:** `documents/FLUTTER_API_GUIDE.md` (revision dated 2026-06-24 — GPS live, Photo Phase 4 live, Wakefulness Phase 5 live)
**Scope:** What the new guide changes versus the app as it stands today, grounded in the current code. Nothing in here is implemented yet — this is the green-light checklist.

---

## TL;DR

| Area | Status | Action |
|---|---|---|
| Shift start / end / early-end | ✅ Already matches | None |
| `reference`, `end_type`, `can_request_early_end`, `early_end_request` | ✅ Already matches | None |
| GPS pings (`{pings:[…]}`, battery fraction, `zone_status`) | ✅ Already matches | None |
| **`hmac_secret` from login** | 🔴 Missing | Parse + store securely |
| **Photo HMAC signature scheme** | 🔴 Wrong scheme | Rewrite to 6-field contract |
| **Server-issued nonces** | 🔴 Client-generated | Fetch via `/nonces/prefetch`; use request nonce online |
| **Photo pending poll + rejection** | 🟠 Non-contractual path | Per-shift endpoint + `422` branching |
| **Wakefulness (TOTP)** | 🔴 Wrong mechanism | Seed at shift start + local TOTP |
| **Push token registration** | 🟡 Absent | Optional — polling is the fallback |

The shift lifecycle and GPS sections need **no changes**. The new contract's real impact is concentrated in **photo verification** and **wakefulness**, both of which were built against the mock backend and now diverge from production.

---

## ✅ Already aligned (verify-only, no work)

These landed in the 2026-06-22 shift-end rework and still match the new guide:

- **Early-end flow** — [shift_service.dart](../lib/services/shift_service.dart) `endShift()` posts `{ended_early, reason?, note?}`; `requestEarlyEnd()` hits `/early-end-request`. [current_shift_model.dart](../lib/models/current_shift_model.dart) parses `early_end_request` (`pending`/`approved`/`rejected`), `can_request_early_end`, and `end_type` (`guard`/`early`/`auto`).
- **`reference`** display code — parsed and surfaced via `displayRef`.
- **GPS** — [gps_service.dart:114-143](../lib/services/gps_service.dart#L114-L143) already sends `{pings:[{latitude, longitude, accuracy, battery, recorded_at}]}`, with **battery as a 0–1 fraction** and `recorded_at` as device time, and reads the server's `zone_status` out of `data.results`. This is exactly the new GPS contract. `SHIFT_NOT_ACTIVE` and "comms interrupted" are handled server-side; nothing to add.

---

## 🔴 P1.1 — Capture `hmac_secret` from login

**Why it's first:** the entire photo-signing chain is blocked on this. Without the real key the rewritten signature still fails server verification.

**What the contract changed:** the login response now returns a per-guard/session shared secret:

```json
"hmac_secret": "f3a9…c1"   // 64-char hex
```

It is the key used to sign every photo upload, and the server verifies the signature against it (rejecting with `422 HMAC_INVALID` on a mismatch).

**Current state:**
- [auth_token_model.dart:19-29](../lib/models/auth_token_model.dart#L19-L29) parses `access_token`, `refresh_token`, `expires_at`, `guard` — **no `hmac_secret`**.
- [auth_provider.dart:49-81](../lib/providers/auth_provider.dart#L49-L81) `signIn()` stores token/refresh/email — **never the secret**.
- [secure_storage_service.dart](../lib/services/secure_storage_service.dart) has no slot for it.
- Photo signing instead uses a hardcoded constant `IRONLOCK_PHOTO_SECRET_v1` at [api_config.dart:46](../lib/config/api_config.dart#L46) — the same fake key in every install.

**Changes:**
1. `AuthTokenModel` — add `final String? hmacSecret;` and parse `json['hmac_secret']`.
2. `SecureStorageService` — add `_hmacSecretKey` with `saveHmacSecret` / `getHmacSecret`, and **add it to `clearSession()`** so it's wiped on sign-out.
3. `AuthNotifier.signIn()` — after login, `if (token.hmacSecret != null) await SecureStorageService.saveHmacSecret(token.hmacSecret!);`.
4. Retire the hardcoded `ApiConfig.photoHmacSecret`.

**Persistence note:** session restore on relaunch goes through `GET /me`, which does **not** return `hmac_secret`. So the secret must survive in secure storage from the last login (it does, since `KeychainAccessibility.first_unlock`) — do **not** clear it on app restart, only on sign-out.

---

## 🔴 P1.2 — Rewrite the photo HMAC signature

**Current scheme** ([photo_service.dart:57-62](../lib/services/photo_service.dart#L57-L62)):

```
HMAC-SHA256( IRONLOCK_PHOTO_SECRET_v1, "nonce:shiftId:capturedAt" )
```

Sent as `nonce` + `signature`, described in code as "extra anti-replay fields the backend may ignore." Against the real backend they are **not** ignored — they're verified — and this scheme is wrong on every axis: wrong key, wrong message, wrong field name, and it never touches the image bytes.

**New contract scheme:** HMAC-SHA256 keyed with the login `hmac_secret`, over **six fields joined by `\n`** in this exact order, lowercase hex:

```
nonce_value
request_id          ← "" for offline (self-initiated) checks
captured_at
latitude            ← "" if omitted
longitude           ← "" if omitted
sha256_hex(image_bytes)
```

```dart
final imageBytes = await file.readAsBytes();
final message = [
  nonceValue,
  requestId ?? '',
  capturedAtIso,
  lat?.toString() ?? '',
  lng?.toString() ?? '',
  sha256.convert(imageBytes).toString(),
].join('\n');
final signature = Hmac(sha256, utf8.encode(hmacSecret))
    .convert(utf8.encode(message)).toString();
```

**Critical rule — byte-for-byte:** send the *same strings* you signed. The signed `latitude`/`longitude`/`request_id` must equal the multipart fields character-for-character (empty string `""` for missing values, not `"null"`, not omitted). Any drift → `422 HMAC_INVALID`, photo **not stored**.

**Changes:**
- `PhotoService.uploadPhoto()` — read `hmac_secret` from secure storage; compute `sha256(image_bytes)`; build the 6-field message; rename the field `nonce` → **`nonce_value`**; keep `request_id`, `captured_at`, `latitude`, `longitude`, `signature`.
- The last line means the file must be read into memory once for hashing **and** sent as the multipart — make sure both see identical bytes.

---

## 🔴 P1.3 — Server-issued nonces (online + offline pool)

**Current state:** nonces are invented **client-side** — [shift_provider.dart:322-329](../lib/providers/shift_provider.dart#L322-L329) `_generateNoncePool()` makes 15 random hex strings at shift start, and `NoncePoolNotifier.consume()` ([shift_provider.dart:343-353](../lib/providers/shift_provider.dart#L343-L353)) tops up by generating more. [photo_screen.dart:186](../lib/screens/photo/photo_screen.dart#L186) draws one per upload. A self-printed nonce proves nothing; the real server returns `NONCE_NOT_FOUND` for every one.

**New two-track model:**

- **Online (server-initiated, the normal case):** the photo request itself carries `request_id` + `nonce_value` (valid 60 s; 90 s total to capture+upload before a CRITICAL-alert timeout). You do **not** draw from a pool — you sign with the nonce that arrived on the request.
- **Offline (self-initiated):** while online, keep a pool topped up via the new endpoint **`POST /shifts/{id}/nonces/prefetch`** (single-use nonces, 15-min validity). When offline, draw one, capture, queue, and upload on reconnect — **omit `request_id`**, send only `nonce_value`. The server reconstructs capture time and accepts if within the 15-min window.

**Changes:**
1. `ApiConfig` — add `noncesPrefetch(id) => '/shifts/$id/nonces/prefetch'`.
2. New `NonceService` (or extend `ShiftService`) — `prefetch(shiftId)` returning server nonce strings.
3. `NoncePoolNotifier` — flip from generate-locally to **fetch-and-refill from the server**; remove the client `_generate()`.
4. Online uploads — thread the request's `nonce_value` (see P2.1) through to `uploadPhoto` instead of `consume()`.
5. Offline uploads — `consume()` a server pool nonce; pairs with the offline upload queue (see IMPROVEMENT_REPORT P1.1).

> **Backend dependency:** confirm `/shifts/{id}/nonces/prefetch` is live on the production host and what its response envelope looks like (field name for the nonce array, how many it returns).

---

## 🟠 P2.1 — Photo pending poll delivers the nonce; new path

**Current state:** [home_screen.dart:122-127](../lib/screens/home/home_screen.dart#L122-L127) polls the **non-contractual** `/photos/pending` and reads only `request_id`, then navigates to `PhotoScreen(requestId:)`. No `nonce_value` is captured.

**New contract:** the per-shift endpoint is **`GET /shifts/{id}/photos/pending`**, and the payload carries `request_id` **and `nonce_value`** — both needed to sign. (Push is the primary delivery; this poll is the documented fallback.)

**Changes:**
- `ApiConfig.photoPending` → per-shift `shiftPhotosPending(id) => '/shifts/$id/photos/pending'`.
- `PendingPhotoState` — add `nonceValue`; `setPending(...)` to carry it.
- [home_screen.dart](../lib/screens/home/home_screen.dart) poll — read `nonce_value`, pass it into `PhotoScreen`.
- `PhotoScreen` / `uploadPhoto` — use the request's `nonce_value` for online checks.

---

## 🟠 P2.2 — Handle `422 PHOTO_REJECTED`

**Current state:** [photo_service.dart:50-54](../lib/services/photo_service.dart#L50-L54) only reads `result` (`VALIDATED`/`FLAGGED`). A rejected photo (`422`, **not stored**) currently surfaces as an opaque `DioException`.

**New contract:** branch on `error.details.reason`:

| reason | meaning | app action |
|---|---|---|
| `NONCE_NOT_FOUND` | nonce never issued | should vanish once P1.3 lands |
| `NONCE_ALREADY_USED` | replay / double-submit | don't retry same nonce |
| `NONCE_EXPIRED` | past 60 s / 15 min | request a fresh check |
| `TIMELINE_ANOMALY` | claimed capture time implausible | surface "check device clock" |
| `HMAC_INVALID` | signature mismatch | **re-login to refresh `hmac_secret`, then retry** |
| `REQUEST_NOT_FOUND` | unknown `request_id` | drop the request |

**Changes:** catch the `422` in `PhotoService`/`PhotoScreen`, parse `error.code == 'PHOTO_REJECTED'` + `details.reason`, and drive the UI (retry vs re-login vs abandon).

---

## 🔴 P1.4 — Wakefulness → TOTP, provisioned at shift start

**This is the largest piece, and the current approach cannot work against the real backend** — the real backend has **no `/welfare/pending`** endpoint. That poll (and its server-pushed code) is mock-only.

**Current state:**
- [home_screen.dart:111](../lib/screens/home/home_screen.dart#L111) polls `/welfare/pending` for `{check_id, code}`.
- [wakefulness_provider.dart:50-58](../lib/providers/wakefulness_provider.dart#L50-L58) shows the server's 4-digit code, **10-second** timer, compares locally.
- [wakefulness_service.dart:12-25](../lib/services/wakefulness_service.dart#L12-L25) posts `{code, responded_at}`.

**New contract (RFC-6238 TOTP):** instead of the server sending a code each time, the phone is given a **seed once** and both sides compute the same time-based code on demand — so a challenge works even offline/backgrounded.

- **Provisioning:** `POST /shifts/{id}/start` now returns a `wakefulness` block — `totp_seed`, `totp_period_seconds` (30), `totp_digits` (4), `response_seconds` (**60**, not 10), and a `schedule` of challenge times. Store the seed **securely**; set local notifications at each schedule mark.
- **Code:** `TOTP(seed, floor(unix_time / 30))`, HMAC-SHA1, 4 digits.
- **Respond:** `POST /wakefulness/{checkId}/respond` with `{code, responded_at}`; for an **offline replay** add `window_reference` (the 30 s window index) + `is_offline: true`.

**Changes:**
1. `ShiftService.startShift()` — currently returns just `actual_start`; extend to also return the `wakefulness` block (or parse it in the notifier).
2. Persist `totp_seed` + schedule in secure storage at start.
3. Add a TOTP computer (few lines of `crypto` HMAC-SHA1, or the `otp` package).
4. `WakefulnessNotifier` — get the code from the seed, not a poll; bump the timer 10 s → 60 s.
5. `WakefulnessService.respond()` — add optional `window_reference` + `is_offline` for offline replay.
6. Stop polling `/welfare/pending`; schedule challenges from the start-response schedule (+ push later).

> **Backend dependency:** confirm the exact `wakefulness` block shape returned by `POST /shifts/{id}/start` on production, and whether the seed is base32 or hex.

---

## 🟢 P3 — FCM push (Android done; iOS pending) — see [FCM_SETUP.md](FCM_SETUP.md)

**Android is wired and builds.** Firebase project `ironlock-security-monitoring`;
`firebase_core` + `firebase_messaging` added; `google-services` Gradle plugin
applied; `google-services.json` in `android/app/`; `flutter build apk --debug`
passes. The app registers its token (`POST /devices/push-token`) on sign-in and
dispatches pushes through the pure [`push_router.dart`](../lib/services/push_router.dart)
into the existing wakefulness/photo providers.

**Confirmed FCM data contract** (backend, 2026-06-24):
- `WAKEFULNESS_CHALLENGE` → `{ check_id, shift_id, code, response_seconds }` →
  fire `POST /wakefulness/{check_id}/received`, then show the overlay with `code`.
- `PHOTO_REQUEST` → `{ request_id, shift_id, nonce_value }` → open capture + upload.

**`POST /wakefulness/{checkId}/received`** (Phase 6) — fire-and-forget receipt so a
dropped push isn't mistaken for an ignored check. Fired from the foreground
dispatcher and the background isolate handler. Online/push-only.

**iOS pending**: needs `GoogleService-Info.plist` + an APNs key + push capabilities.
`PushMessaging.init()` swallows the failure so iOS keeps running on the polling
fallback until configured. Steps in [FCM_SETUP.md](FCM_SETUP.md).

---

## New endpoints / fields summary

| New in contract | Where it lands |
|---|---|
| `hmac_secret` (login response) | `AuthTokenModel`, `SecureStorageService`, `signIn()` |
| `POST /shifts/{id}/nonces/prefetch` | `ApiConfig`, nonce service, `NoncePoolNotifier` |
| `GET /shifts/{id}/photos/pending` (carries `nonce_value`) | `ApiConfig`, `home_screen` poll, `PendingPhotoState` |
| `nonce_value` field on photo upload (was `nonce`) | `PhotoService.uploadPhoto()` |
| 6-field `\n`-joined HMAC over image hash | `PhotoService._sign()` |
| `422 PHOTO_REJECTED` + `details.reason` | `PhotoService` / `PhotoScreen` |
| `wakefulness` block on `POST /shifts/{id}/start` | `ShiftService.startShift()`, `WakefulnessNotifier` |
| TOTP code generation | new helper + `WakefulnessNotifier` |
| `window_reference` + `is_offline` on respond | `WakefulnessService.respond()` |
| `POST /devices/push-token` | new (deferred) |
| `POST /wakefulness/{checkId}/received` | new (deferred — coupled to push) |

---

## Dependency order

```
P1.1  hmac_secret (login → secure storage)
   │
   ├── P1.2  photo signature (6-field, keyed with secret)
   │     └── P1.3  server nonces ──┐
   │     └── P2.1  pending + nonce ┤→ photo verification works end-to-end
   │     └── P2.2  rejection branch┘
   │
   └── P1.4  wakefulness TOTP  (independent — seed comes from /start, not login)

P3   push token (deferred — polling is the fallback)
```

Photo work (P1.2 / P1.3 / P2.x) is **blocked** on the secret (P1.1). Wakefulness (P1.4) is **independent** and is the largest single piece. Recommended sequence: **P1.1 → photo chain → wakefulness**, push token last.

## Open questions for the backend dev

1. Is `POST /shifts/{id}/nonces/prefetch` live on `generous-yellow-jaguar…`? Response shape / count?
2. Exact `wakefulness` block shape on `POST /shifts/{id}/start`; seed encoding (base32 vs hex)?
3. Does `GET /shifts/{id}/photos/pending` exist on production, and does it return `nonce_value` inline?
4. Is `hmac_secret` returned on **every** login, and does it rotate (driving the `HMAC_INVALID → re-login` retry)?
