# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Handoff (do this every session)

Maintain `../HANDOFF.md` (at `Mobile/security_uk/HANDOFF.md`). At the **end of every working session**, prepend a new dated entry — most recent at top — covering: what was worked on, files changed, key facts learned, state at end (compiles? running? verified?), and open/next steps. Append/prepend; don't rewrite old entries.

## Commands

```bash
# Flutter app (iOS simulator target)
flutter run -d F390E17F-385D-420C-89E9-E7CF933ADC99   # iPhone 17 Pro simulator
flutter run                                             # picks first available device
flutter pub get                                         # install / sync dependencies

# Static analysis — zero errors required before any change is done
flutter analyze

# Mock backend (must be running for all API calls to work)
cd mock-backend && node server.js

# Health check
curl http://127.0.0.1:8000/api/mobile/v1/status

# Reset iOS simulator permissions after denying camera/location
xcrun simctl privacy F390E17F-385D-420C-89E9-E7CF933ADC99 reset all com.ironlock.guardmonitor

# Trigger backend-driven checks during testing
curl -X POST http://127.0.0.1:8000/admin/trigger-welfare
curl -X POST http://127.0.0.1:8000/admin/trigger-photo
```

Quality gates before any change is "done": `flutter analyze` (zero issues) **and** `flutter test` (currently 12 passing — models, providers, login widget). Run both.

## Architecture

**Ironlock Guard Monitor** is a Flutter 3.44 / Dart 3.12 mobile app for lone security guards. It is a **single-flow app** with no router package: `LoginScreen` → `HomeScreen`, with overlays pushed on top.

```
lib/
  main.dart                    # ProviderScope root; auth-gated AnimatedSwitcher
  config/
    api_config.dart            # All endpoint paths + HMAC secret constant
  models/                      # Pure data classes with fromJson — no business logic
    current_shift_model.dart   # CurrentShiftModel + ShiftSiteModel/ShiftGeofenceModel
    api_response.dart          # ApiResponse<T> (success envelope) + ApiError
  providers/
    app_providers.dart         # Barrel — re-exports the four files below (import this)
    auth_provider.dart         # AuthNotifier/authProvider + GuardProfile/guardProfileProvider
    shift_provider.dart        # CurrentShift*, Shift*, noncePoolProvider
    photo_provider.dart        # Photo*, PendingPhoto*
    ui_providers.dart          # zone, zoneUpdatedAt, battery (real), privacy, activeTab
    alerts_provider.dart       # AlertsNotifier + AppAlert model
    wakefulness_provider.dart  # WakefulnessNotifier — welfare check challenge FSM
  screens/
    login/login_screen.dart
    home/home_screen.dart      # Shift lifecycle UI; GPS zone stream listener
    photo/photo_screen.dart    # Camera capture + upload with nonce/HMAC
  overlays/
    wakefulness_overlay.dart   # Full-screen 4-digit code challenge
    end_shift_sheet.dart       # Summary sheet; requires a reason + note when ending EARLY (before scheduled_end)
    privacy_notice_overlay.dart
  services/
    api_client.dart            # Dio singleton + JWT interceptor with auto-refresh
    auth_service.dart
    shift_service.dart         # endShift() sends {ended_early, reason?, note?} in the POST body
    wakefulness_service.dart
    photo_service.dart         # HMAC-SHA256 signing on every upload (extra, non-contractual)
    gps_service.dart           # 15-second timer; streams zone state back to UI; real battery in pings
    notification_service.dart  # Local scheduled "shift ended" reminder (flutter_local_notifications)
    device_info_service.dart   # Persisted device_id + device_name/platform/app_version
    connectivity_service.dart  # connectivity_plus stream → isOnlineProvider
    secure_storage_service.dart # Tokens, expires_at, email, device_id in Keychain/EncryptedPrefs
  theme/
    responsive.dart            # ← MOST IMPORTANT — context.s() / context.sp()
```

## State Management

Riverpod 3, `NotifierProvider` only — no `StateProvider`, no `FutureProvider`.

**Providers** (defined across `auth_provider.dart`, `shift_provider.dart`, `photo_provider.dart`, `ui_providers.dart`, all re-exported by the `app_providers.dart` barrel; in dependency order):
- `authProvider` — `AsyncNotifier<AuthState>`; restores session via `GET /me` on startup, wires the JWT interceptor's forced-sign-out callback, calls `currentShiftProvider.fetch()` after sign-in
- `guardProfileProvider` — name, employee code, SIA licence fields (no `site` — that's per-shift now)
- `currentShiftProvider` (`CurrentShiftNotifier`) — server source of truth for the current shift: id, window, `can_start`/`can_end`, site/geofence. `fetch()`/`start()`/`end()` call straight through to the API; `start()`/`end()` rethrow on `409 SHIFT_NOT_STARTABLE`/`SHIFT_NOT_ENDABLE` so the UI can surface the error
- `shiftProvider` (`ShiftNotifier`) — the in-progress bookkeeping orchestrator (see below)
- `zoneProvider` — `int` (0 = inside, 1 = outside, 2 = no signal)
- `batteryProvider` — `double?` real device battery via `battery_plus` (refreshes on a 30s poll + charge-state changes). `null` = unknown (e.g. iOS simulator reports no battery), surfaced honestly in the status strip rather than faked
- `privacyAcceptedProvider`, `activeTabProvider`
- `photoProvider` (`PhotoNotifier`) — countdown + upload status FSM
- `pendingPhotoProvider` — `PendingPhotoState {pending, requestId}`; set by polling, consumed by `HomeScreen` ref.listen
- `noncePoolProvider` — `List<String>` generated client-side at shift start (no nonce-issuing endpoint in the contract); consumed one per upload

**Other providers:** `wakefulnessProvider` (wakefulness_provider.dart), `alertsProvider` (alerts_provider.dart), `gpsServiceProvider` (gps_service.dart), `isOnlineProvider` / `networkStatusProvider` (connectivity_service.dart).

## Shift Lifecycle (the core flow)

The server is the source of truth for whether a shift can start: `GET /shifts/current` returns `can_start`/`can_end` flags, computed as `now >= scheduled_start - 15min && now <= scheduled_end && status === 'scheduled'`. `HomeScreen` polls this every 20 seconds (independent of shift state) so the START button enables itself the moment the window opens, with a "You can begin your shift from HH:MM" hint while it's disabled.

`ShiftNotifier.start()` is the orchestrator — it does all of these in sequence, with **no optimistic UI update** (the app reports, the server decides):
1. `currentShiftProvider.notifier.start()` → `POST /shifts/{id}/start`; throws on `409 SHIFT_NOT_STARTABLE` (e.g. a race where the window closed between poll and tap), which `_ActionButtons` catches and shows as a snackbar
2. Local `ShiftState` is set from the server's returned shift (`actual_start`, `id`, `displayRef`)
3. `GpsService.startCapture(shiftId)` — begins 15-second GPS loop
4. `_generateNoncePool()` — generates 15 random hex nonces client-side (no nonce-issuing endpoint exists in the contract — flagged gap)
5. `NotificationService.scheduleShiftEnd(scheduledEnd)` — schedules the local "shift ended" reminder

`ShiftNotifier.end({endedEarly, reason, note})` stops the GPS service, **cancels the scheduled reminder**, clears state, then calls `currentShiftProvider.notifier.end(...)` → `POST /shifts/{id}/end` with `{ended_early, reason?, note?}`.

**Ending a shift (early vs normal):** the END button always shows while active. `end_shift_sheet.dart` computes `isEarly = now < scheduled_end`:
- **Early** → sheet titled "End Shift Early?", requires a **reason chip** + a **note (≥10 chars)**; confirm sends `ended_early:true` + `reason` + `note`. `_ActionButtons` shows a "ending now needs a reason" hint under the END circle.
- **Normal (after scheduled_end)** → standard confirm, sends `ended_early:false`.
- ⚠️ The reminder + early-reason are the **app half**. The **guarantee** (an open shift always closes) needs a backend **auto-close** job — see `docs/BACKEND_SHIFT_END_SPEC.md`. The app cannot be trusted to fire anything when backgrounded/killed/offline.

**End-of-shift reminder** (`notification_service.dart`): a **local** (not push) notification scheduled at `scheduled_end` — "your shift has ended, tap END to close it". The OS fires it even if the app is killed. Re-armed in `resumeFromServer()` after a relaunch; cancelled in `end()`. Complemented by an on-screen `_OverdueBanner` once `now > scheduled_end` while still active. Permission is requested contextually at shift start.

**Backend polling** (`HomeScreen._pollBackend`, every 20 seconds):
- Always: `currentShiftProvider.notifier.fetch()` to keep `can_start`/`can_end` live
- `GET /welfare/pending` → if `{pending:true, check_id, code}`, calls `wakefulnessProvider.notifier.trigger(checkId, code)` which shows `WakefulnessOverlay` via `ref.listen` in `build()`. The code is server-issued but shown on screen for the guard to read and type back — it's an attentiveness check, not a secret
- `GET /photos/pending` → if `{pending:true, request_id}`, calls `pendingPhotoProvider.notifier.setPending(true, requestId:)` which navigates to `PhotoScreen(requestId:)` via `ref.listen`

**Welfare check outcome**: `WakefulnessNotifier.submit()` compares the entry against the server-issued code synchronously (so the overlay gets an instant result), then fires `POST /wakefulness/{checkId}/respond` in the background for the authoritative record, and calls `shiftProvider.notifier.recordWelfareCheck(passed:)` for the end-shift summary.

**Photo upload outcome** is recorded in two places by `PhotoScreen._upload()`:
- `PhotoService.uploadPhoto()` → multipart `POST /shifts/{id}/photos` with `request_id` + nonce/HMAC signature (extra, non-contractual anti-replay fields)
- `shiftProvider.notifier.recordPhoto(passed:)` → increments counters for end-shift summary

## Services Layer

**`api_client.dart`** — single `Dio` instance (via `dioProvider`). The `_JwtInterceptor`:
- Attaches `Authorization: Bearer <token>` on every request
- On `401 TOKEN_EXPIRED`/`TOKEN_INVALID`: fetches refresh token from secure storage, calls `POST /auth/refresh` with `{refresh_token, device:{device_id}}`, saves new token pair, retries original request. Guards against concurrent refreshes with `_refreshing` flag. If the refresh call itself fails (no refresh token, or the server rejects it), invokes `forcedSignOutCallbackProvider` — a small `Notifier<void Function()?>` living in this file that `AuthNotifier.build()` wires to `signOut()`, avoiding a circular import with `app_providers.dart`.

**`device_info_service.dart`** — `DeviceInfoService` provides the `device` object sent on login/refresh (`device_id, device_name, platform, app_version`). `device_id` is a random 32-char hex string generated once and persisted via `SecureStorageService` (survives sign-out, per the contract's "stable for the install" requirement) — no `device_info_plus`/`uuid` packages, just `dart:io` + `Random.secure()`.

**`gps_service.dart`** — `GpsService` holds a `Timer` and broadcasts zone state via `zoneStream`. `HomeScreen` subscribes and calls `zoneProvider.notifier.set(index)` **and** `zoneUpdatedAtProvider.notifier.markNow()` so the zone card can show an honest "updated Xs ago". Each ping includes the **real** device battery as a 0–1 fraction (via `battery_plus`; `null` when unknown). On iOS simulator, `Geolocator.getCurrentPosition()` will throw — GPS loop continues silently, so the zone stays at its default and the card shows "Awaiting first GPS fix…".

**`photo_service.dart`** — every upload includes the contract's `photo, request_id, captured_at, latitude?, longitude?`, plus `nonce` (consumed from pool) and `signature = HMAC-SHA256(IRONLOCK_PHOTO_SECRET_v1, "nonce:shiftId:capturedAt")` as extra anti-replay fields kept alongside the spec per project decision — the backend may ignore them. The secret is in `ApiConfig.photoHmacSecret`.

**`secure_storage_service.dart`** — stores `ironlock_auth_token`, `ironlock_refresh_token`, `ironlock_guard_email`, `ironlock_expires_at`, `ironlock_device_id`. `clearSession()` (called on sign-out) deliberately leaves `device_id` untouched.

## Photo Screen (PHO-004)

`PhotoScreen` tries `availableCameras()` on init. On a real device it initialises `CameraController` and shows `CameraPreview`. On iOS simulator (no camera hardware), it falls back to the custom `_SimulatedCameraView` painter and writes `_kMinimalJpeg` (a 1×1 white JPEG byte array at the bottom of the file) to a temp file for the upload payload. The actual upload to the backend happens either way.

## Mock Backend

`mock-backend/server.js` — Node.js/Express, all state in-memory (resets on restart), mounted under `BASE = '/api/mobile/v1'`. Success responses use the envelope `{success:true, data, meta:{timestamp}}`; errors use `{success:false, error:{code, message, details?}}`. `GET /api/mobile/v1/status` is flat/non-enveloped.

| Credential | Value |
|---|---|
| Identifier | `j.smith@ironlock.co.uk` or `SGM-0042` |
| Password | `password123` |

Key behaviours:
- Login accepts a single `identifier` field (matched against email or `employee_code`) + `device{device_id,...}`; returns `access_token` (2h), `refresh_token` (7d), and the `device` object
- 5 failed login attempts for the same identifier → `423 ACCOUNT_LOCKED`
- Single session per guard — a new login invalidates the previous device's tokens (old ones then get `401 TOKEN_INVALID`)
- `POST /auth/refresh` takes `{refresh_token, device:{device_id}}`, rotates both tokens
- `GET /shifts/current` computes `can_start`/`can_end` server-side: `can_start` is true from 15 minutes before `scheduled_start` until `scheduled_end` while `status === 'scheduled'`; `can_end` is true while `status === 'active'`
- `POST /shifts/{id}/start` / `/end` return `409 SHIFT_NOT_STARTABLE` / `SHIFT_NOT_ENDABLE` outside those windows
- `/shifts/{id}/locations`, `/wakefulness/{checkId}/respond`, `/shifts/{id}/photos` (Phase 3.3) simulate working responses rather than `501` so those screens stay testable locally
- `/welfare/pending` and `/photos/pending` are a non-contractual interim polling mechanism (not in the real spec — push notifications will replace this later): consume-on-read, and now also emit `check_id`/`code` and `request_id` respectively when pending
- Admin triggers: `POST /admin/trigger-welfare` and `POST /admin/trigger-photo` (root-level, no `/api/mobile/v1` prefix, no auth required)

**After restarting the backend**, the app's cached token becomes invalid. The guard must sign out and back in.

## Responsive System

**Every pixel value must go through `context.s()` or `context.sp()`** — no hardcoded dimensions anywhere.

```dart
context.s(value)   // layout dimensions — scales relative to 390px reference width, clamps 0.86–1.14
context.sp(value)  // font sizes — gentler clamp 0.92–1.12
```

`main.dart` clamps OS `textScaler` to max 1.1 to prevent layout breaks from accessibility font sizes.

## Design Tokens

| Token | Value | Usage |
|---|---|---|
| `AppColors.bg` | `#07111F` | Screen background |
| `AppColors.surface` | `#0F172A` | Cards, overlays |
| `AppColors.gold` | `#D4AF37` | Brand accent, END button, active states |
| `AppColors.border` | `#23344D` | Card borders, dividers |
| `AppColors.success/warning/danger` | — | Zone, battery, wakefulness semantics |

Typography: `Inter` via `google_fonts`. Use `AppType.*` presets then `.copyWith(fontSize: context.sp(...))`.

## Home Screen Layout

`home_screen.dart` uses two completely different layout trees based on `shift.active`:
- **Inactive**: `Column` with scrollable content in `Expanded` + START button centred in a second `Expanded` (smaller button = `context.s(190)`, max `screenH * 0.26`) + Sign Out pinned at bottom.
- **Active**: single `SingleChildScrollView` with all content and the END button flowing below it. This avoids the "button floating in empty space" / "elapsed text clipping" bugs that arise from two equal `Expanded` sections.

## iOS Permissions

Strings are declared in `ios/Runner/Info.plist` (`NSCameraUsageDescription`, `NSLocationWhenInUseUsageDescription`, `NSLocationAlwaysAndWhenInUseUsageDescription`). `permission_handler` 11.x uses Swift Package Manager on Flutter 3.44+ and does **not** appear in CocoaPods output — this is expected. Location is requested at shift start; camera is requested when `PhotoScreen` opens.
