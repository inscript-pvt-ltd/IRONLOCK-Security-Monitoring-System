# IronLock Guard Monitor — Project Details Log

This file is the running record of what this project is and what has been done on it.
**Update the "Session Log" section at the end of every working session** — add a new
dated entry summarizing what changed, decisions made, and what's left open. Don't rewrite
history; append.

---

## 1. What this project is

**Smart Security Guard Monitoring and Patrol Verification System** (a.k.a. IronLock /
"Guard Monitor"). A mobile app for lone security guards that verifies they're on-site,
awake, and safe during a shift, intended for BS 8484 lone-worker compliance.

This folder (`Mobile/security_uk`) contains:

- **`guardmonitor/`** — the Flutter mobile app (the guard-facing client). This is where
  active development is happening.
- **`mock-backend/`** — a Node.js/Express mock API server used to develop the app against
  before the real Laravel backend exists.
- **`documents/`** — specs, proposals, and design references (functional spec, design
  document, API integration guide, timeline, wireframes, DB schema).
- **`.agents/`, `.sixth/`** — Flutter-specific Claude skills (responsive layout, JSON
  serialization, routing, etc.) used while building the app.

Wider repo context: the git root is one level up at
`IRONLOCK-Security-Monitoring-System/`, which also contains (per commit history) a
separate Laravel + Firebase admin backend being built by another contributor (`jerry`)
in parallel — DDD folder structure, auth, geofencing, shift lifecycle, JWT sessions. That
backend is **not** in this `Mobile/security_uk` folder and is tracked elsewhere in the
repo.

---

## 2. Flutter app (`guardmonitor/`) — architecture

Flutter 3.44 / Dart 3.12, single-flow app (no router package): `LoginScreen` →
`HomeScreen`, with overlays pushed on top. State management is **Riverpod 3,
`NotifierProvider` only** (no `StateProvider`/`FutureProvider`).

```
lib/
  main.dart                    ProviderScope root; auth-gated AnimatedSwitcher
  config/api_config.dart       Endpoint paths + HMAC secret constant
  models/                      Pure data classes with fromJson, no business logic
  providers/
    app_providers.dart         Most providers (auth, shift, zone, battery, photo, nonce pool...)
    alerts_provider.dart       AlertsNotifier + AppAlert model
    wakefulness_provider.dart  Welfare-check challenge FSM
  screens/
    login/login_screen.dart
    home/home_screen.dart      Shift lifecycle UI, GPS zone stream listener
    photo/photo_screen.dart    Camera capture + upload with nonce/HMAC
  overlays/
    wakefulness_overlay.dart   Full-screen 4-digit code challenge
    end_shift_sheet.dart       Summary bottom sheet (real welfare/photo counts)
    privacy_notice_overlay.dart
  services/
    api_client.dart            Dio singleton + JWT interceptor with auto-refresh
    auth_service.dart, shift_service.dart, wakefulness_service.dart, photo_service.dart
    gps_service.dart           15s timer, streams zone state to UI
    device_info_service.dart   Persisted device_id + device metadata
    connectivity_service.dart  connectivity_plus stream -> isOnlineProvider
    secure_storage_service.dart  Tokens/expiry/email/device_id in Keychain/EncryptedPrefs
  theme/responsive.dart        context.s() / context.sp() — every dimension must use these
  widgets/                     ~20 reusable UI components (buttons, cards, loaders, etc.)
```

### Core flow: shift lifecycle
- Server is source of truth for whether a shift can start/end (`GET /shifts/current`
  returns `can_start`/`can_end`). `HomeScreen` polls every 20s.
- `ShiftNotifier.start()` orchestrates: calls `POST /shifts/{id}/start` (no optimistic UI
  update), sets local state from server response, starts `GpsService` 15s loop, generates
  a client-side nonce pool (15 random hex nonces — flagged gap: no nonce-issuing endpoint
  exists in the real contract yet).
- `ShiftNotifier.end()` stops GPS, clears state, calls `POST /shifts/{id}/end`.
- Backend polling every 20s also checks `GET /welfare/pending` (triggers
  `WakefulnessOverlay`) and `GET /photos/pending` (navigates to `PhotoScreen`) — this
  polling mechanism is **non-contractual**, a stand-in for push notifications.
- Welfare check: server-issued code, guard reads it and types it back (attentiveness
  check, not a secret); compared client-side instantly, then posted to server for record.
- Photo upload: multipart `POST /shifts/{id}/photos` with `request_id` + nonce + HMAC-SHA256
  signature (`IRONLOCK_PHOTO_SECRET_v1`) as extra anti-replay fields — not in the official
  spec, added as a project decision; backend may ignore them.

### Photo screen
Tries real camera via `availableCameras()`; on iOS simulator (no camera hardware) falls
back to a custom `_SimulatedCameraView` painter and writes a 1×1 placeholder JPEG so the
upload path is still exercised end-to-end.

### Design tokens
`AppColors.bg #07111F`, `surface #0F172A`, `gold #D4AF37` (brand accent / END button),
`border #23344D`. Typography: Inter via `google_fonts`. Every pixel must go through
`context.s()` (layout, 390px reference width) or `context.sp()` (fonts) — no hardcoded
dimensions anywhere; this is enforced as the most important rule in `guardmonitor/CLAUDE.md`.

Full architecture detail lives in [`guardmonitor/CLAUDE.md`](../guardmonitor/CLAUDE.md) —
treat that as the authoritative technical reference; this file is the higher-level
session/history log.

---

## 2a. Real backend (live, as of 2026-06-16)

The app's default `ApiConfig.baseUrl` now points at the real cPanel-hosted Laravel
backend: `http://generous-yellow-jaguar.23-111-165-74.cpanel.site/api/mobile/v1`
(`guardmonitor/lib/config/api_config.dart`). Confirmed reachable — `GET /status` and
`POST /auth/login` both return the exact envelope shapes the app's models already
expect (`{success,data,meta}` / `{success:false,error:{code,message}}`).

Contract reference: `documents/FLUTTER_API_GUIDE.md` (the live, tested mobile API
guide — supersedes the older `documents/MOBILE_API_INTEGRATION.md` draft for anything
they disagree on).

**Login window rule (new):** guards can only log in during an active shift, or from 10
minutes before a scheduled shift starts until it ends. Outside that window the server
returns `403 LOGIN_WINDOW_CLOSED` with a human-readable message — already surfaced
as-is by `login_screen.dart`'s generic `ApiError.message` display, no special-casing
needed.

**Not yet implemented server-side** (return `501 NOT_IMPLEMENTED` or simply don't
exist): `POST /shifts/{id}/locations` (GPS), `POST /wakefulness/{checkId}/respond`,
`POST /shifts/{id}/photos`, and the mock-only `/welfare/pending` / `/photos/pending`
polling stand-ins. The app already degrades gracefully against these — `gps_service.dart`
swallows all capture errors, and `home_screen.dart`'s `_pollBackend` wraps the
pending-polling calls in a try/catch that silently ignores `DioException`. Practical
effect: **login, profile, and shift start/end are the live working path today**; GPS
zone tracking, welfare checks, and photo verification will stay inert against the real
backend until Phase 3.3 ships server-side.

To point the app back at the local mock backend for offline dev work:
`flutter run --dart-define=API_BASE_URL=http://127.0.0.1:8000/api/mobile/v1`.

---

## 3. Mock backend (`mock-backend/server.js`)

Node/Express, all state in-memory (resets on restart), mounted under
`/api/mobile/v1`. Endpoints implemented:

```
GET  /api/mobile/v1/status                       health check (flat response, no envelope)
POST /api/mobile/v1/auth/login                    identifier (email or employee_code) + device
POST /api/mobile/v1/auth/refresh                  rotates access+refresh token
POST /api/mobile/v1/auth/logout
GET  /api/mobile/v1/me
GET  /api/mobile/v1/shifts/current                 can_start/can_end computed server-side
POST /api/mobile/v1/shifts/:id/start
POST /api/mobile/v1/shifts/:id/end
POST /api/mobile/v1/shifts/:id/locations
POST /api/mobile/v1/wakefulness/:checkId/respond
POST /api/mobile/v1/shifts/:id/photos              multipart upload
GET  /api/mobile/v1/alerts
POST /api/mobile/v1/alerts/:id/dismiss
GET  /api/mobile/v1/welfare/pending                 consume-on-read polling stand-in
GET  /api/mobile/v1/photos/pending                   consume-on-read polling stand-in
POST /admin/trigger-welfare                          test helper, no /v1 prefix, no auth
POST /admin/trigger-photo                            test helper, no /v1 prefix, no auth
```

Test credentials: `j.smith@ironlock.co.uk` or `SGM-0042` / `password123`. 5 failed
logins → `423 ACCOUNT_LOCKED`. Single session per guard (new login invalidates old
device's tokens). **After restarting the backend, cached app tokens become invalid** —
guard must sign out/in again.

**Note / discrepancy worth resolving later:** `guardmonitor/API_SPEC.md` documents an
older/aspirational Laravel-style contract (`/api/auth/login`, `/api/shifts/start`, Sanctum
tokens, different envelope) that **does not match** what `mock-backend/server.js` and the
app actually implement (`/api/mobile/v1/...`, JWT access+refresh, `{success,data,meta}`
envelope). `documents/MOBILE_API_INTEGRATION.md` is the more current shared contract doc.
Treat `mock-backend/server.js` + `guardmonitor/CLAUDE.md` as ground truth for what's
actually wired up today.

---

## 4. Reference documents (`documents/`)

- `FUNCTIONAL_SPECIFICATION.md` — full functional capability list
- `DESIGN_DOCUMENT.md` — UX/UI design spec
- `PROPOSAL_CLIENT.md` — client-facing project proposal (v2.0)
- `TIMELINE.md` — 5-week + Phase 4 delivery plan (Phase 4 = ARC integration for BS 8484
  Level 3 lone worker certification)
- `MOBILE_API_INTEGRATION.md` — shared mobile/backend API contract (status-tagged per
  endpoint: built vs reserved for later)
- `FLUTTER_SPEC.md` — what the app looks like/does (screens, states, animations)
- `SCENARIOS.md`, `FLOWCHART.md` / `FLOWCHART_FULL.html`, `WIREFRAMES.html`,
  `WIREFRAME_SPEC.md`, `MOBILE_PREVIEW.html` — supporting UX artefacts
- `database_schema_mysql_corrected.sql` — DB schema for the real backend

---

## 5. Known gaps / open items

- No nonce-issuing endpoint in the real API contract — app currently generates its own
  pool client-side at shift start (flagged as a gap in `guardmonitor/CLAUDE.md`).
- Welfare/photo "pending" polling is a temporary stand-in for push notifications.
- No automated test suite for the Flutter app yet — `flutter analyze` (zero errors) is
  currently the only quality gate.
- `API_SPEC.md` (Laravel-style) is stale relative to the actual mock backend contract —
  should be reconciled or removed to avoid confusion.
- Build artifacts and `node_modules` appear to have been committed to git in some recent
  commits (e.g. `.gradle` caches, `mock-backend/node_modules/**`) — worth adding/fixing
  `.gitignore` so the repo doesn't keep tracking generated files.

---

## 6. Session Log

> Append a new entry here at the end of every session. Keep each entry short: what
> changed, why, and what's left open. Most recent entry at the top.

### 2026-06-18 — Shift start/login fixes, Remember Me, server times, `reference` field

- **Remember Me** wired end-to-end: `login_screen.dart` pre-fills the saved email and
  passes `rememberMe` to `AuthNotifier.signIn()`, which now only persists the refresh
  token + email when checked. Also surfaces a retry hint on `LOGIN_WINDOW_CLOSED` +
  `reason: expired`.
- **Server-authoritative shift times**: `shift_service.dart` `startShift()/endShift()`
  now extract only the timestamps (`actual_start/end`, `duration_hours`) defensively (a
  2xx never throws on the body), and `CurrentShiftNotifier.start()/end()` merge them into
  the existing full `CurrentShiftModel` (the start/end responses are partial and lack
  `scheduled_*`). `ShiftNotifier.start()` now guarantees the app goes active on any 2xx.
- **Login auto-start bug fixed**: resume logic (`home_screen.dart` `ref.listen` + the
  post-start reconcile) now fires ONLY on `status == 'active'` — never `checked_in`.
  `checked_in` = logged in but not started, so the START button must stay visible.
- **New `reference` field** (doc update 2026-06-18): `CurrentShiftModel` now parses the
  server's display-only `reference` (e.g. `SH-2847`); `displayRef` shows `#<reference>`
  with the UUID-derived label as fallback. UUID `id` stays the only key used in URLs.
- Added `[shift]` debug logging and verified against the live backend on the iOS sim.
- **Backend bug found (flag to backend dev)**: `GET /shifts/current` returns
  `{shift: null}` for a shift that is `active` once `scheduled_start` passes — a contract
  violation; the app guards against it not wiping in-progress state.
- Started a running `HANDOFF.md` at the repo root and noted the convention in
  `guardmonitor/CLAUDE.md`.
- Open: end-to-end verify the login→START flow on a fresh scheduled shift; consider
  removing debug logging before release; backend to fix the `/shifts/current` null bug.

### 2026-06-16 — Connected the app to the real backend

- User provided `documents/FLUTTER_API_GUIDE.md`, confirming a live, tested Laravel
  backend at `http://generous-yellow-jaguar.23-111-165-74.cpanel.site/api/mobile/v1`.
- Verified the app's existing models/services (`ApiResponse`, `ApiError`,
  `AuthTokenModel`, `GuardProfileModel`, `CurrentShiftModel`, `auth_service.dart`,
  `shift_service.dart`, `api_client.dart`'s JWT refresh interceptor) already match this
  exact contract field-for-field — an earlier session (Phase 3.2 commit) had built
  against this spec in anticipation.
- Changed `guardmonitor/lib/config/api_config.dart`: `ApiConfig.baseUrl` default now
  points at the real backend instead of the local mock (`127.0.0.1:8000`); local mock
  testing still available via `--dart-define=API_BASE_URL=...`.
- Cleared the hardcoded mock credentials (`j.smith@ironlock.co.uk` / `password123`)
  pre-filled in `login_screen.dart` — they're mock-backend-only and meaningless against
  the real server now.
- Verified live: `GET /status` responds; `POST /auth/login` with the guide's documented
  test credentials (`GRD6583` / `TestPass123!`) returned a well-formed
  `INVALID_CREDENTIALS` error (right envelope shape, just stale/wrong creds — user has
  their own real credentials to test with).
- `flutter analyze`: 0 issues after the change.
- See new §2a for full real-backend notes (login window rule, which endpoints are
  still 501/unimplemented server-side, and how the app degrades gracefully against
  those).
- Open: user to do an actual end-to-end login test on a real device/simulator with
  valid guard credentials.

### 2026-06-16 — Initial project documentation pass

- Read through the whole `Mobile/security_uk` tree (Flutter app, mock backend, docs,
  git history) to build a complete picture of the project.
- Created this file (originally at `detels/`, later renamed to `details/`) as a
  persistent project knowledge base.
- No code changes made this session.
- Open follow-up noticed: `API_SPEC.md` vs actual mock backend contract mismatch (see
  §3); generated files (`node_modules`, `.gradle`) committed to git (see §5).
