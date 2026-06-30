# IronLock Guard Monitor — Full Technical Reference

> Every screen, provider, service, model, and API endpoint documented in one place.
> Updated: 2026-06-17

---

## Table of Contents

1. [App Entry Point — `main.dart`](#1-app-entry-point)
2. [API Configuration — `api_config.dart`](#2-api-configuration)
3. [Network Layer — `api_client.dart`](#3-network-layer--jwt-interceptor)
4. [Models](#4-models)
5. [Services](#5-services)
6. [Providers (State)](#6-providers-state)
7. [Screens](#7-screens)
8. [Overlays](#8-overlays)
9. [API Contract Reference](#9-api-contract-reference)
10. [Data & Auth Flows — end to end](#10-data--auth-flows-end-to-end)
11. [Design System Rules](#11-design-system-rules)

---

## 1. App Entry Point

**File:** `lib/main.dart`

### `main()`
- Locks orientation to **portrait only** (up + down).
- Sets status bar to transparent with light icons.
- Wraps the entire app in `ProviderScope` (Riverpod root).

### `IronlockApp` (ConsumerWidget)
- Watches `authProvider` (an `AsyncNotifier<AuthState>`).
- Uses `authValue.when()`:
  - **loading** → `_SplashView` (gold spinner on dark background).
  - **error** → `LoginScreen` (auth init failure treated as signed-out).
  - **data(signedIn)** → `HomeScreen`.
  - **data(signedOut)** → `LoginScreen`.
- Navigation is an `AnimatedSwitcher` with a 220ms **fade + 4% upward slide** between screens — no explicit router, no named routes.
- Clamps `textScaler` to max 1.1 so large OS accessibility font sizes don't break layouts.

### `_SplashView`
- Plain dark screen (`AppColors.bg`) with a centred `CircularProgressIndicator` in gold.
- Shown for the ~200ms it takes `AuthNotifier.build()` to check secure storage on first launch.

---

## 2. API Configuration

**File:** `lib/config/api_config.dart`

| Constant / method | Value / pattern |
|---|---|
| `baseUrl` | `http://generous-yellow-jaguar.23-111-165-74.cpanel.site/api/mobile/v1` (override via `--dart-define=API_BASE_URL=...`) |
| `connectTimeout` | 10 seconds |
| `receiveTimeout` | 30 seconds |
| `status` | `GET /status` |
| `login` | `POST /auth/login` |
| `refresh` | `POST /auth/refresh` |
| `logout` | `POST /auth/logout` |
| `me` | `GET /me` |
| `shiftCurrent` | `GET /shifts/current` |
| `shiftStart(id)` | `POST /shifts/{id}/start` |
| `shiftEnd(id)` | `POST /shifts/{id}/end` |
| `shiftLocations(id)` | `POST /shifts/{id}/locations` |
| `shiftPhotos(id)` | `POST /shifts/{id}/photos` |
| `photoPending` | `GET /photos/pending` |
| `wakefulnessRespond(checkId)` | `POST /wakefulness/{checkId}/respond` |
| `welfarePending` | `GET /welfare/pending` |
| `alerts` | `GET /alerts` |
| `alertDismiss(id)` | `POST /alerts/{id}/dismiss` |
| `photoHmacSecret` | `"IRONLOCK_PHOTO_SECRET_v1"` (HMAC key, extra anti-replay, not in official spec) |

---

## 3. Network Layer / JWT Interceptor

**File:** `lib/services/api_client.dart`

### `dioProvider` (Provider\<Dio\>)
Creates and holds the single `Dio` instance used by every service:
- `baseUrl` from `ApiConfig`.
- Default headers: `Accept: application/json`, `Content-Type: application/json`, `X-App-Version: 4.0`, `X-Platform: mobile`.
- Adds `_JwtInterceptor` to the interceptors list.

### `_JwtInterceptor`

#### `onRequest`
Reads `ironlock_auth_token` from `SecureStorageService` and attaches `Authorization: Bearer <token>` to every outgoing request.

#### `onError`
Handles `401 TOKEN_EXPIRED` / `TOKEN_INVALID` automatically:
1. Checks the error is a 401 with one of those codes AND is not the refresh endpoint itself (preventing infinite loops).
2. If already refreshing, queues the failed request in `_pendingRetries`.
3. Sets `_refreshing = true`, reads `ironlock_refresh_token` from storage.
4. POSTs `POST /auth/refresh` with `{refresh_token, device:{device_id}}` (no `Authorization` header — clears it explicitly).
5. On success: saves the new `access_token`, `refresh_token`, `expires_at` to secure storage, then retries the original request and drains any queued retries.
6. On failure (refresh token itself rejected): calls `forcedSignOutCallbackProvider` which triggers `AuthNotifier.signOut()`, routing the user back to `LoginScreen`.

### `ForcedSignOutNotifier` / `forcedSignOutCallbackProvider`
A `Notifier<void Function()?>` that holds the sign-out callback. `AuthNotifier.build()` wires its own `signOut` method into this provider. This breaks the circular import between `api_client.dart` and `app_providers.dart`.

---

## 4. Models

### `ApiResponse<T>` — `lib/models/api_response.dart`
Generic success envelope. All successful server responses have the shape `{success: true, data: {...}}`.

```
ApiResponse.fromJson(json, fromData)
  → success: json['success']
  → data: fromData(json['data'])  // null-safe; skipped if data is null
```

### `ApiError` — `lib/models/api_response.dart`
Error envelope `{success: false, error: {code, message, details?}}`.

- `ApiError.fromJson(json)` — parses the `error` sub-object.
- `ApiError.fromDioException(e)` — extracts the error if the server responded with the standard envelope; falls back to a generic "check your connection" message if the server never responded (timeout, no network).

### `AuthTokenModel` — `lib/models/auth_token_model.dart`
Returned by `POST /auth/login`.

| Field | Type | Source |
|---|---|---|
| `accessToken` | `String` | `data.access_token` |
| `refreshToken` | `String?` | `data.refresh_token` |
| `expiresAt` | `DateTime` | `data.expires_at` (parsed, `.toLocal()`) |
| `guard` | `GuardProfileModel?` | `data.guard` (optional, not always present) |

### `GuardProfileModel` — `lib/models/guard_profile_model.dart`
Returned by `GET /me` inside `data.guard`.

| Field | Source |
|---|---|
| `firstName`, `lastName`, `fullName` | `data.guard.first_name`, etc. |
| `email` | `data.guard.email` |
| `employeeCode` | `data.guard.employee_code` |
| `siaLicenceNumber`, `siaLicenceExpiry` | `data.guard.sia_licence_number/expiry` |

### `CurrentShiftModel` — `lib/models/current_shift_model.dart`
Returned by `GET /shifts/current`. Also used as the merged result of start/end.

| Field | Type | Notes |
|---|---|---|
| `id` | `String` | UUID |
| `status` | `String` | `scheduled` / `active` / `completed` / `cancelled` |
| `scheduledStart` | `DateTime` | From `scheduled_start`, `.toLocal()` |
| `scheduledEnd` | `DateTime` | From `scheduled_end`, `.toLocal()` |
| `actualStart` | `DateTime?` | From `actual_start`, `.toLocal()`. Null until shift started |
| `actualEnd` | `DateTime?` | From `actual_end`, `.toLocal()`. Null until shift ended |
| `canStart` | `bool` | Server flag — true 15 min before scheduled start while `scheduled` |
| `canEnd` | `bool` | Server flag — true while `active` |
| `role` | `String?` | Guard's role for this shift |
| `notes` | `String?` | Shift notes from supervisor |
| `site` | `ShiftSiteModel?` | Site name + grace_period_minutes |
| `geofence` | `ShiftGeofenceModel?` | Polygon coordinate pairs |
| `durationHours` | `double?` | Set on end response; backend-computed |
| `displayRef` | computed | `"#SH-" + first 6 chars of UUID uppercased` (no real ref field in spec) |

---

## 5. Services

### `AuthService` — `lib/services/auth_service.dart`

#### `login(identifier, password) → AuthTokenModel`
- `POST /auth/login` with `{identifier, password, device: DeviceInfoService.toJson()}`.
- Parses response with `ApiResponse.fromJson → AuthTokenModel.fromJson`.

#### `logout()`
- `POST /auth/logout` (fire-and-forget; swallowed in `AuthNotifier.signOut()`).

#### `getProfile() → GuardProfileModel`
- `GET /me` → `ApiResponse → GuardProfileModel.fromJson(data['guard'])`.

---

### `ShiftService` — `lib/services/shift_service.dart`

#### `fetchCurrent() → CurrentShiftModel?`
- `GET /shifts/current` → parses `data['shift']` via `CurrentShiftModel.fromJson`.
- Returns `null` if `data.shift` is null (no shift scheduled today).

#### `startShift(shiftId) → DateTime?`
- `POST /shifts/{id}/start` (no body needed).
- The server response is **partial**: `{id, status, actual_start, can_end}`.
- Returns just `actual_start` as `DateTime` (converted to local) or `null` if absent.
- The caller (`CurrentShiftNotifier.start()`) merges this with existing full state.

#### `endShift(shiftId) → ({actualStart, actualEnd, durationHours})`
- `POST /shifts/{id}/end`.
- Response is also partial: `{id, status, actual_start, actual_end, duration_hours}`.
- Returns a named record with the three relevant fields (all nullable).

---

### `GpsService` — `lib/services/gps_service.dart`

#### `startCapture(shiftId)`
1. Checks location permission via `Geolocator.checkPermission()`. Exits silently if denied.
2. Cancels any existing timer.
3. Starts a 15-second repeating `Timer` calling `_capture()`.
4. Calls `_capture()` immediately on start.

#### `_capture()` (private)
1. Calls `Geolocator.getCurrentPosition(accuracy: high, timeLimit: 10s)`.
2. Builds a ping: `{latitude, longitude, accuracy, battery: 0.8, recorded_at: UTC ISO}`.
3. `POST /shifts/{id}/locations` with `{pings: [ping]}`.
4. Reads `data.results[last].zone_status` from the response.
5. Emits the zone string on `zoneStream` broadcast stream.
6. Any error (iOS simulator throws, offline) is silently swallowed.

#### `stopCapture()`
Cancels the timer, clears `_shiftId`.

#### `zoneStream` (Stream\<String\>)
Broadcast stream emitting `'INSIDE_ZONE'` / `'OUTSIDE_ZONE'` / `'NO_SIGNAL'`.
`HomeScreen` subscribes in `initState` and maps strings to integers for `zoneProvider`.

---

### `PhotoService` — `lib/services/photo_service.dart`

#### `uploadPhoto({filePath, shiftId, requestId, latitude?, longitude?, nonce?}) → PhotoUploadResult`
1. Captures `capturedAt = DateTime.now().toUtc().toIso8601String()`.
2. If `nonce` is provided, computes HMAC-SHA256 signature: `HMAC(key="IRONLOCK_PHOTO_SECRET_v1", message="nonce:shiftId:capturedAt")`.
3. Builds `FormData` with: `photo` (multipart file), `request_id`, `captured_at`, `latitude?`, `longitude?`, `nonce?`, `signature?`.
4. `POST /shifts/{id}/photos` (multipart, 60s receive timeout).
5. Returns `PhotoUploadResult(result: data['result'])` — result is `'VALIDATED'` or `'FLAGGED'`.

---

### `WakefulnessService` — `lib/services/wakefulness_service.dart`

#### `respond(checkId, code) → bool`
- `POST /wakefulness/{checkId}/respond` with `{code, responded_at: UTC ISO}`.
- Returns `true` if `data.result == 'PASSED'`, `false` otherwise.

---

### `DeviceInfoService` — `lib/services/device_info_service.dart`
No network calls. Pure utility for generating the `device` object sent on login/refresh.

#### `getOrCreateDeviceId() → String`
- Reads `ironlock_device_id` from secure storage.
- If absent, generates a 32-char random hex string (`Random.secure()`), saves it, returns it.
- Survives sign-out (intentionally not cleared by `clearSession()`).

#### `toJson() → Map`
Returns `{device_id, device_name: "Ironlock Guard App", platform: "ios"/"android", app_version: "4.0.0"}`.

---

### `SecureStorageService` — `lib/services/secure_storage_service.dart`
Thin wrapper around `flutter_secure_storage` (Keychain on iOS, EncryptedSharedPreferences on Android).

| Method | Key | Notes |
|---|---|---|
| `saveToken(t)` / `getToken()` | `ironlock_auth_token` | Access token |
| `saveRefreshToken(t)` / `getRefreshToken()` | `ironlock_refresh_token` | Refresh token |
| `saveEmail(e)` / `getSavedEmail()` | `ironlock_guard_email` | Last-used identifier |
| `saveExpiresAt(dt)` / `getExpiresAt()` | `ironlock_token_expires_at` | ISO string |
| `getDeviceId()` / `saveDeviceId(id)` | `ironlock_device_id` | Never cleared |
| `clearSession()` | all except device_id | Called on sign-out |

---

### `ConnectivityService` — `lib/services/connectivity_service.dart`

#### `networkStatusProvider` (StreamProvider\<bool\>)
Wraps `Connectivity().onConnectivityChanged`. Maps the list of connectivity results to a single bool: `true` if any result is not `ConnectivityResult.none`.

#### `isOnlineProvider` (Provider\<bool\>)
Derives a plain bool from `networkStatusProvider`. Defaults to `true` (assume online) until the stream emits.

---

## 6. Providers (State)

All providers use **Riverpod 3 `NotifierProvider`** — no `StateProvider`, no `FutureProvider`.

---

### `authProvider` — `AsyncNotifier<AuthState>`

**`AuthState` enum:** `signedOut`, `signedIn`

#### `build()` — runs on app startup
1. Wires `forcedSignOutCallbackProvider` → own `signOut` to handle JWT refresh failures.
2. Reads `ironlock_auth_token` from secure storage.
3. If token exists: calls `GET /me` to restore the guard profile. On failure, falls back to the saved email to derive a display name. Calls `currentShiftProvider.fetch()`. Returns `signedIn`.
4. If no token: returns `signedOut`.

#### `signIn(identifier, password, {bool rememberMe = true})`
1. `AuthService.login(identifier, password)` → `AuthTokenModel`.
2. Always saves `access_token` and `expires_at`.
3. **If `rememberMe`**: also saves `refresh_token` and `email`. If unchecked, neither is saved — after the 2-hour access token expires, the interceptor cannot refresh and forces sign-out.
4. Sets `guardProfileProvider` from the token's embedded `guard` object (or derives from email).
5. Sets `state = AsyncData(AuthState.signedIn)`.
6. Calls `currentShiftProvider.fetch()`.

#### `signOut()`
1. Fire-and-forget `POST /auth/logout`.
2. `SecureStorageService.clearSession()` (clears tokens, email, expiry; keeps device_id).
3. Clears `currentShiftProvider`.
4. Invalidates `shiftProvider`.
5. Sets `state = AsyncData(AuthState.signedOut)`.

---

### `guardProfileProvider` — `Notifier<GuardProfile>`

`GuardProfile` holds: `email`, `name`, `initials`, `employeeCode?`, `siaLicenceNumber?`, `siaLicenceExpiry?`.

#### `setFromApi(GuardProfileModel)`
Populates full profile from the API response (first + last name → initials).

#### `setFromEmail(String)`
Fallback: derives a display name by splitting the email local-part on `.`, `_`, `-`. Sets initials from the first two parts.

---

### `currentShiftProvider` — `Notifier<CurrentShiftModel?>`
Server source of truth for the shift's scheduling and flags.

#### `fetch()`
`GET /shifts/current` → updates `state` with the full `CurrentShiftModel`. Silently swallows errors (stale state is acceptable; the next 20-second poll retries).

#### `start() → CurrentShiftModel`
1. Guards: `state` must not be null.
2. `ShiftService.startShift(id)` → extracts `actual_start` from the partial response.
3. Merges into a new `CurrentShiftModel`: keeps all existing fields (`scheduledStart/End`, `site`, `geofence`, `role`, `notes`), updates `status → active`, `canStart → false`, `canEnd → true`, `actualStart`.
4. Updates `state`, returns the merged model.
5. Throws `DioException` on `409 SHIFT_NOT_STARTABLE` (propagates to UI for snackbar).

#### `end() → CurrentShiftModel`
1. `ShiftService.endShift(id)` → `{actualStart?, actualEnd?, durationHours?}`.
2. Merges: `status → completed`, `canStart/End → false`, `actualStart` (preserves existing if response omits it), `actualEnd`, `durationHours`.
3. Throws on `409 SHIFT_NOT_ENDABLE`.

#### `clear()`
Sets `state = null`. Called on sign-out.

---

### `shiftProvider` — `Notifier<ShiftState>`
Local in-progress bookkeeping. Tracks active status, elapsed time, welfare/photo counters.

**`ShiftState` fields:** `active`, `startTime?`, `id?`, `shiftRef?`, `welfareChecksTotal`, `welfareChecksPassed`, `photosTotal`, `photosPassed`.

#### `start()`
1. `currentShiftProvider.notifier.start()` → merged `CurrentShiftModel`.
2. Creates `ShiftState(active: true, startTime: updated.actualStart ?? DateTime.now(), id, shiftRef)`.
3. `GpsService.startCapture(shiftId)`.
4. `_generateNoncePool()` — generates 15 random 32-char hex nonces into `noncePoolProvider`.

#### `end()`
1. Reads current `id`.
2. `GpsService.stopCapture()`.
3. **Clears state immediately** to `const ShiftState()`.
4. If `id != null`: calls `currentShiftProvider.notifier.end()` (swallows errors — state is already cleared).

#### `resumeFromServer(CurrentShiftModel shift)`
Called from `HomeScreen`'s `ref.listen` when the server reports an active shift but local state is inactive. Guards with `if (state.active) return`. Sets `ShiftState(active: true, startTime: shift.actualStart, ...)`, starts GPS, generates nonce pool.

#### `recordWelfareCheck({required bool passed})`
Increments `welfareChecksTotal` (always) and `welfareChecksPassed` (if passed).

#### `recordPhoto({required bool passed})`
Increments `photosTotal` and `photosPassed`.

#### `_generateNoncePool()` (private)
Generates 15 × 16-byte random hex strings using `Random.secure()`. Loads them into `noncePoolProvider`.

---

### `zoneProvider` — `Notifier<int>`
`0` = inside zone, `1` = outside zone, `2` = no GPS signal.

`set(int)` — clamps 0–2. Called by `HomeScreen` when `GpsService.zoneStream` emits.
`cycle()` — cycles 0→1→2→0. Used in development.

---

### `batteryProvider` — `Notifier<double>`
Simulated battery level (starts at 72%). `tick()` decrements by 0.02% every 4 seconds (HomeScreen timer). `set(pct)` for direct assignment. No real battery hardware integration.

---

### `privacyAcceptedProvider` — `Notifier<bool>`
`false` by default. `accept()` sets it to `true`. Used by `PrivacyNoticeOverlay`.

---

### `activeTabProvider` — `Notifier<int>`
Tracks the selected bottom tab index. `setTab(int)`. Called when the End Shift sheet is confirmed to reset to tab 0.

---

### `photoProvider` — `Notifier<PhotoState>`
FSM for the photo capture/upload cycle within `PhotoScreen`.

**`PhotoStatus` states:** `idle → capturing → uploading → validated / flagged / failed / expired`

- `tick()` — called every 1s by `PhotoScreen` timer. Decrements `secondsRemaining` from 78. At 0 → `expired`. While `expired`, decrements `expireCountdown` from 30; at 0 → resets to `idle`.
- `capture()` → `uploading`.
- `setResult(PhotoStatus)` → sets terminal state.
- `tryAgain()` → resets to `const PhotoState()`.

---

### `pendingPhotoProvider` — `Notifier<PendingPhotoState>`
Bridge between the 20-second poll and `HomeScreen`'s navigation listener.

`setPending(bool, {String? requestId})` — sets pending + requestId. `HomeScreen.ref.listen` watches this and navigates to `PhotoScreen(requestId:)` when `pending` becomes true.

---

### `noncePoolProvider` — `Notifier<List<String>>`
Pool of 15 single-use nonces for photo HMAC signing.

- `load(nonces)` — replaces the pool (called at shift start).
- `consume() → String?` — pops and returns the first nonce; returns `null` if empty.
- `isEmpty` — getter.

---

### `wakefulnessProvider` — `Notifier<WakefulnessState>`

**`WakefulnessStatus`:** `idle`, `challenge`, `success`, `failed`

**`WakefulnessState` fields:** `status`, `checkId`, `code`, `entry`, `secondsRemaining (10)`, `startedAt`.

#### `trigger(checkId, code)`
Transitions to `challenge` status, sets the server-issued code (4 digits), resets `secondsRemaining` to 10 and `entry` to empty.

#### `addDigit(digit)`
Appends digit to `entry` if `status == challenge` and `entry.length < 4`.

#### `deleteDigit()`
Removes the last digit from `entry`.

#### `tick()`
Called every 1s by `WakefulnessOverlay`'s countdown timer. Decrements `secondsRemaining`. At 0 → transitions to `failed`, records welfare check as failed, calls `_respond()` in background.

#### `submit()`
Synchronously compares `entry` vs `code`. If equal → `success`, records passed. If not → `failed`, records failed. In both cases calls `_respond()` in background.

#### `reset()`
Resets to `const WakefulnessState()`. Called after the overlay closes.

#### `_respond(checkId, code)` (private async)
Fire-and-forget `POST /wakefulness/{checkId}/respond`. Any error is swallowed — the local result already stands.

---

### `alertsProvider` — `Notifier<List<AppAlert>>`
Initialises with 5 hardcoded default alerts (urgent + notice + reminder). Immediately attempts `GET /alerts` via `AlertsService` to replace them.

- `dismiss(id)` — marks alert dismissed locally; fire-and-forget `POST /alerts/{id}/dismiss`.
- `prepend(AppAlert)` — inserts at front of list (used by wakefulness failure to add a live alert).
- `refresh()` — re-fetches from server.
- `unreadCount` — count of non-dismissed alerts.

**`AlertSeverity`:** `urgent`, `notice`, `reminder`.

---

## 7. Screens

### Login Screen — `lib/screens/login/login_screen.dart`

**State:** `_emailCtrl`, `_passCtrl`, `_obscure`, `_rememberMe (default true)`, `_loading`, `_error`

#### `initState()`
Calls `_loadSavedEmail()` — reads `ironlock_guard_email` from secure storage. If found and widget is still mounted, pre-fills `_emailCtrl`. This is the "Remember Me" pre-fill effect.

#### `_canSubmit` (getter)
`true` when `_emailCtrl` is not blank AND `_passCtrl` is not blank AND not `_loading`.

#### `_signIn()`
1. Guards with `_canSubmit`.
2. Sets `_loading = true`, clears `_error`.
3. Calls `authProvider.notifier.signIn(email, password, rememberMe: _rememberMe)`.
4. On `DioException`: parses `ApiError.fromDioException`. Shows `"⚠ Account locked. Please contact your supervisor."` for `ACCOUNT_LOCKED`; otherwise shows `"⚠ <apiError.message>"`.
5. On any other exception: shows `"⚠ Connection error. Please check your network and try again."`.
6. On success: auth state changes → `AnimatedSwitcher` in `main.dart` transitions to `HomeScreen` automatically. No manual navigation needed.

#### UI Components (Login)
- **`_LogoCard`** — loads `assets/images/logo.png`; falls back to "IL" text in gold if the asset is missing.
- **Email field** (`AppInput`) — type `emailAddress`, action `next`. Pre-filled from storage if Remember Me was checked last time.
- **Password field** (`AppInput`) — `obscureText: _obscure`. Eye icon toggles visibility. `onSubmitted` calls `_signIn()` if `_canSubmit`.
- **Error box** (`_MessageBox`) — shown only when `_error != null`. Red border, danger background.
- **Remember Me checkbox** — `GestureDetector` wrapping a `Checkbox` (gold fill, dark bg). Toggles `_rememberMe`. When unchecked: refresh token and email are NOT saved on next sign-in.
- **Sign In button** (`AppButton.primary`) — disabled (greyed out, `onPressed: null`) until `_canSubmit`.
- **Loading state** — replaces the entire form with a centred arc spinner and "Signing in…" label. Uses `_LoaderPainter` (custom `CustomPainter` drawing a static gold arc — intentionally not animated, acts as a visual placeholder).
- **Footer** — "Privacy Notice · Ironlock Civil Engineering & Security" + version "v4.0 · UK".

---

### Home Screen — `lib/screens/home/home_screen.dart`

The most complex screen. Two completely different layout trees depending on `shiftProvider.active`.

#### Timers / Subscriptions (in `initState`)
- **`_batteryTimer`** — fires every 4s → `batteryProvider.notifier.tick()`.
- **`_pollingTimer`** — fires every 20s → `_pollBackend()`.
- **`_zoneSub`** — subscribes to `GpsService.zoneStream`. Maps string to int (`INSIDE_ZONE→0`, `OUTSIDE_ZONE→1`, everything else →2). Calls `zoneProvider.notifier.set(index)`.

All three are cancelled in `dispose()`.

#### `_pollBackend()`
Runs every 20 seconds regardless of shift state:
1. `currentShiftProvider.notifier.fetch()` — always runs. Keeps `canStart`/`canEnd` live, enables START button when the 15-minute window opens.
2. If `shiftProvider.active` is false → stops here.
3. `GET /welfare/pending` — if `{pending: true, check_id, code}` and wakefulness status is `idle` → `wakefulnessProvider.notifier.trigger(checkId, code)`.
4. `GET /photos/pending` — if `{pending: true, request_id}` → `pendingPhotoProvider.notifier.setPending(true, requestId:)`.
5. Any `DioException` in steps 3/4 is silently swallowed (the real backend returns 501 for these).

#### `ref.listen` listeners (in `build()`)
- **`currentShiftProvider`** — if server says `status == active` and `actualStart != null` but local `shiftProvider.active == false` → calls `shiftProvider.notifier.resumeFromServer(next)`. This recovers the stuck state where the server has an active shift but the app lost track of it (after a parse error or app restart).
- **`wakefulnessProvider`** — when `status` transitions to `challenge` → shows `WakefulnessOverlay` as a dialog (`barrierDismissible: false`).
- **`pendingPhotoProvider`** — when `pending` becomes true and `requestId != null` → clears pending flag, pushes `PhotoScreen(requestId:)`.

#### Layout — INACTIVE shift
`Column` with:
1. `Expanded(flex: 3)` — scrollable content area.
2. `Expanded(flex: 2)` — centred `_ActionButtons` (START button).
3. Thin gradient divider line.
4. `_SignOutButton` pinned at bottom.

#### Layout — ACTIVE shift
Single `SingleChildScrollView` — all content and the END button flow vertically so nothing clips on small phones. No split Expanded layout.

#### `_StatusIconStrip`
Row of 4 icon tiles (right-aligned):
1. **Wifi icon** — green if zone ≠ 2 (GPS signal OK), red if no signal.
2. **Sync icon** — green if online, amber if offline.
3. **Battery icon** — green > 30%, amber > 15%, red ≤ 15%.
4. **Check-circle icon** — green if online, red if offline.

Each tile is a rounded square with `AppColors.surface` background, 1px border, card shadow, and a top highlight gradient stripe.

#### `_Avatar`
44px circle with gold gradient and 50% transparent gold border. Displays guard's initials from `guardProfileProvider`. Green status dot (12px) bottom-right.

#### `_ZoneCard` (shown only when shift active)
Animated card showing zone state:
- **Inside** — green, no pulse.
- **Outside** — amber, pulsing shadow (2.5s cycle).
- **No signal** — red, fast pulsing shadow (1.5s cycle).

Shows icon (✓ / ⚠ / ⊘), zone label, "Westfield Shopping Centre A · on site" (placeholder site name), last update time. If `OUTSIDE_ZONE`, shows a 75% progress bar in amber.

#### `_ShiftCard`
Displays:
- "TODAY'S SHIFT" micro-label + shift reference chip (`#SH-XXXXXX` derived from UUID).
- Site name from `currentShift.site?.name` (shows `—` if not loaded yet).
- Subtitle:
  - Active shift: `"Started HH:MM · Day D Mon YYYY"` (uses server's `actual_start` via `shift.startTime`).
  - Inactive: `"HH:MM – HH:MM · Day D Mon YYYY"` (scheduled window).
  - No shift: today's date.
- Role and notes (shown only pre-shift, from `currentShift.role` / `.notes`).
- Gradient divider line.
- Active shift: live elapsed timer (`StreamBuilder` ticking every 1s, shows `HH:MM:SS` in gold).
- Inactive: chips "✓ GPS Active", "● Online", "All synced".

#### `_ActionButtons`

**START button path (`shift.active == false`):**
- `canStart = currentShift?.canStart ?? false`.
- `_startHint`:
  - `null` if `canStart` (no hint needed — button is active).
  - `"No shift scheduled for today."` if `currentShift == null`.
  - `"You can begin your shift from HH:MM."` if status is `scheduled` but not in window yet.
  - Empty (no hint, no button label) if `scheduled` but in non-startable status.
- Shows `_CircleStartButton` (disabled/greyed if `canStart == false`) + hint text below.

**`_startWithPermissions(context, ref)`:**
1. `Permission.locationWhenInUse.request()`.
2. If permanently denied → snackbar telling user to enable in Settings.
3. `shiftProvider.notifier.start()`.
4. On `DioException` (e.g. 409) → snackbar with `apiError.message`.

**END button path (`shift.active == true`):**
- Shows `_CircleEndButton` in gold.
- Tap → `showEndShiftSheet(context)`.

#### `_CircleStartButton`
190px diameter (clamped to 26% of screen height). Dark navy gradient, gold border. "START" text in white. `AnimatedScale` shrinks to 96% on press. Opacity 0.4 when disabled.

#### `_CircleEndButton`
Same size as start button. Transparent fill with gold border and text "END". `AnimatedScale` on press.

#### `_OfflineBanner`
Full-width amber banner at the top when `isOnlineProvider == false`. Shows wifi-off icon + "No connection — data will sync when back online".

#### `_SignOutButton`
Full-width transparent button with red border. Icon + "Sign Out". Tap sequence:
1. `shiftProvider.notifier.end()` — stops GPS, clears state, fires `POST /shifts/{id}/end`.
2. `authProvider.notifier.signOut()` — clears tokens, returns to login.

---

### Photo Screen — `lib/screens/photo/photo_screen.dart`

Receives `requestId` as a constructor parameter (from `pendingPhotoProvider`).

#### `initState()`
- Creates `_scanCtrl` (3500ms repeating) — drives the simulated camera scanner animation.
- Creates `_flashCtrl` (350ms) — drives the white flash on capture.
- Calls `_initCamera()`.
- Starts 1-second timer calling `photoProvider.notifier.tick()`.

#### `_initCamera()`
Calls `availableCameras()`. If cameras exist, initialises `CameraController(first, high, audioOff)` and sets `_cameraReady = true`. On error (simulator) → falls through silently; the simulated view is used instead.

#### `_capture()`
1. Guards: `photoProvider.status` must be `idle`.
2. Plays `_flashCtrl.forward → reverse` (white screen flash).
3. Sets photo status to `uploading`.
4. If real camera ready: `CameraController.takePicture()` → `filePath`.
5. Fallback (simulator): writes `_kMinimalJpeg` (a hardcoded 1×1 white JPEG byte array) to a temp file; `filePath` is set to that.
6. Calls `_upload(filePath)`.

#### `_upload(filePath)`
1. Reads `shiftProvider.id` — bails with `failed` if null.
2. `noncePoolProvider.notifier.consume()` — pops one nonce.
3. `PhotoService.uploadPhoto(filePath, shiftId, requestId, nonce)`.
4. Maps result: `'VALIDATED' → validated`, `'FLAGGED' → flagged`, anything else → `failed`.
5. `photoProvider.notifier.setResult(photoStatus)`.
6. `shiftProvider.notifier.recordPhoto(passed: photoStatus == validated)`.
7. On exception → `failed`, `recordPhoto(passed: false)`.

#### UI Components (Photo)
- **Header** — back arrow + "Photo Verification" title.
- **Timer bar** — "Respond within" label + countdown seconds (`78s → 0`). Turns red below 10s, shows "Expired" when `expired`.
- **Progress bar** — `LinearProgressIndicator(value: secondsRemaining / 78)`.
- **Camera view** — real `CameraPreview` on device; `_SimulatedCameraView` (custom painter with grid, scanning line, corner brackets, crosshair) on simulator.
- **Shutter button** (`_ShutterButton`) — 72px white circle with inner 58px circle. `AnimatedScale` to 88% on press. Disabled (opacity 0.25) when not `idle`.
- **Upload status** (`_UploadStatus`) — appears after capture. Shows state-specific icon, colour, and message:
  - `uploading` → gold, "Submitting photo…"
  - `validated` → green, "✓ Photo verified and securely stored"
  - `flagged` → amber, "Photo flagged for supervisor review — no action needed"
  - `failed/expired` → red, error text or expired countdown
- **Try Again button** — appears only on `flagged` or `failed`. Calls `photoProvider.notifier.tryAgain()`.

---

## 8. Overlays

### Wakefulness Overlay — `lib/overlays/wakefulness_overlay.dart`

Full-screen dialog (`barrierDismissible: false`) shown when a welfare check is triggered.

#### `initState()`
- Creates `_shakeCtrl` (400ms elastic-in) for the wrong-code shake animation.
- Creates `_fadeCtrl` (250ms) — fades in immediately.
- Calls `_startCountdown()`.

#### `_startCountdown()`
1-second repeating timer. On each tick:
1. Reads wakefulness status — if not `challenge`, cancels and stops.
2. `wakefulnessProvider.notifier.tick()` — decrements countdown.
3. If status is now `failed` → `_onFailed()`.

#### `_onKeyTap(key)`
Routes key presses:
- `'DEL'` → `deleteDigit()`.
- `'OK'` → `_submit()` only if 4 digits entered.
- digit → `addDigit(digit)`.

#### `_submit()`
1. Cancels countdown timer.
2. `wakefulnessProvider.notifier.submit()` — compares entry vs server code.
3. If `success` → `Future.delayed(900ms, _close)`.
4. If `failed` → `_onFailed()`.

#### `_onFailed()`
1. Runs `_shakeCtrl.forward(from: 0)` — shakes the keypad.
2. Prepends an urgent alert to `alertsProvider`: "Welfare check not completed — your supervisor has been notified".
3. `Future.delayed(1600ms, _close)`.

#### `_close()`
Reverses `_fadeCtrl` (fade out), then `Navigator.pop()`, then `wakefulnessProvider.notifier.reset()`.

#### UI Components (Wakefulness)
- **`_Banner`** — red-gradient header strip: "⚠ WELFARE CHECK" + online/offline chip.
- **`_CodeDisplay`** — large gold number display (40sp, letter-spacing 8) showing the server-issued code with spaces between digits. Guard must read this and type it back.
- **`_CountdownRing`** — 110×110 custom-painted ring. Arc drains from full (10s) to empty (0s). Gold → red in final 3 seconds. Shows ✓ on success, ✗ on failure.
- **`_PinDots`** — row of 4 circles (16×16, margin 10). Filled gold for entered digits, empty with faint gold border otherwise. Shakes horizontally on wrong entry (sine wave via `_shake` animation, 8px amplitude).
- **`_Keypad`** — 4 rows × 3 keys (1–9, DEL, 0, OK). Each key is `_Key`:
  - Number keys: dark gradient background.
  - DEL: same style, muted text.
  - OK: transparent when disabled, blue gradient when `entryLength == 4`. Scale to 92% on press.
- **`_FooterMessage`** — "Tap OK after entering all 4 digits" → "✓ Check-in confirmed" (green) → red error box "Check-in missed — Your supervisor has been alerted".

---

### End Shift Sheet — `lib/overlays/end_shift_sheet.dart`

Bottom modal sheet (`isScrollControlled: true`, transparent background).

Shows a summary before confirming shift end:
- Site name (from `currentShift.site.name`).
- Shift ID (`shift.shiftRef`).
- Started time (`_formatTime(shift.startTime)` — `HH:MM` from server's `actual_start`).
- Duration (`DateTime.now() - shift.startTime` — formatted as `Xh Ym`).
- Location status: "Interrupted" (amber) if zone == 2, else "Active throughout".
- Welfare checks: "None" / `"X / Y ✓"` or `"X / Y ⚠"` (amber if any missed).
- Photos: same pattern.

**"Confirm End Shift" button** (danger red):
1. `Navigator.pop()` — closes the sheet.
2. `shiftProvider.notifier.end()` — stops GPS, clears local state, fires `POST /shifts/{id}/end`.
3. `zoneProvider.notifier.set(0)` — resets zone to inside.
4. `activeTabProvider.notifier.setTab(0)` — resets tab.

**"Cancel" button** — closes sheet, no action.

---

### Privacy Notice Overlay — `lib/overlays/privacy_notice_overlay.dart`

Full-screen fade-in overlay shown before first shift (controlled by `privacyAcceptedProvider`). Contains:
- **What we collect**: GPS, wakefulness responses, photos, connectivity.
- **How it is used**: operational security only, not shared.
- **Your rights**: UK GDPR access/correction/deletion; `privacy@ironlock.co.uk`.
- **"I Understand — Continue"** button → `privacyAcceptedProvider.notifier.accept()` → fade out → pop.

---

## 9. API Contract Reference

**Base URL:** `http://generous-yellow-jaguar.23-111-165-74.cpanel.site/api/mobile/v1`

**Success envelope:**
```json
{ "success": true, "data": { ... }, "meta": { "timestamp": "..." } }
```

**Error envelope:**
```json
{ "success": false, "error": { "code": "...", "message": "...", "details": {} } }
```

---

### Auth

#### `POST /auth/login`
**Request:**
```json
{
  "identifier": "guard@ironlock.co.uk",
  "password": "password",
  "device": { "device_id": "hex32", "device_name": "Ironlock Guard App", "platform": "ios", "app_version": "4.0.0" }
}
```
**Response:**
```json
{
  "data": {
    "access_token": "...",
    "refresh_token": "...",
    "expires_at": "2026-06-15T14:00:00Z",
    "guard": { "first_name": "...", "last_name": "...", "email": "...", "employee_code": "...", "sia_licence_number": "...", "sia_licence_expiry": "..." }
  }
}
```
**Errors:** `INVALID_CREDENTIALS (401)`, `ACCOUNT_LOCKED (423)`, `LOGIN_WINDOW_CLOSED (403)` (guard can only log in 10 min before scheduled shift start until shift end).

#### `POST /auth/refresh`
**Request:** `{ "refresh_token": "...", "device": { "device_id": "..." } }`
**Response:** New `access_token`, `refresh_token`, `expires_at`.

#### `POST /auth/logout`
No body. No meaningful response — fire and forget.

#### `GET /me`
**Response:** `{ "data": { "guard": { ...GuardProfileModel fields... } } }`

---

### Shifts

#### `GET /shifts/current`
**Response (shift exists):**
```json
{
  "data": {
    "shift": {
      "id": "uuid",
      "status": "scheduled",
      "scheduled_start": "2026-06-15T12:00:00Z",
      "scheduled_end": "2026-06-15T20:00:00Z",
      "actual_start": null,
      "actual_end": null,
      "can_start": true,
      "can_end": false,
      "role": "Patrol Guard",
      "notes": "Check all exits",
      "site": { "id": "...", "name": "Westfield", "grace_period_minutes": 5 },
      "geofence": { "id": "...", "name": "...", "coordinates": [[lat, lng], ...] }
    }
  }
}
```
**Response (no shift):** `{ "data": { "shift": null } }`

#### `POST /shifts/{id}/start`
No body required (lat/long optional).
**Response (partial):**
```json
{ "data": { "shift": { "id": "...", "status": "active", "actual_start": "2026-06-15T12:05:00Z", "can_end": true } } }
```
**Error:** `409 SHIFT_NOT_STARTABLE`

#### `POST /shifts/{id}/end`
**Response (partial):**
```json
{ "data": { "shift": { "id": "...", "status": "completed", "actual_start": "...", "actual_end": "2026-06-15T20:10:00Z", "duration_hours": 8.08 } } }
```
**Error:** `409 SHIFT_NOT_ENDABLE`

#### `POST /shifts/{id}/locations`
**Request:** `{ "pings": [{ "latitude": 51.5, "longitude": -0.1, "accuracy": 5.0, "battery": 0.8, "recorded_at": "UTC ISO" }] }`
**Response:** `{ "data": { "results": [{ "zone_status": "INSIDE_ZONE" }] } }`
**Status: NOT YET IMPLEMENTED** — app degrades gracefully (GPS errors are swallowed).

---

### Wakefulness

#### `GET /welfare/pending`
**Response:** `{ "data": { "pending": true, "check_id": "...", "code": "1234" } }` or `{ "data": { "pending": false } }`

#### `POST /wakefulness/{checkId}/respond`
**Request:** `{ "code": "1234", "responded_at": "UTC ISO" }`
**Response:** `{ "data": { "result": "PASSED" } }` or `"FAILED"`
**Status: NOT YET IMPLEMENTED** — app swallows 501 errors silently.

---

### Photos

#### `GET /photos/pending`
**Response:** `{ "data": { "pending": true, "request_id": "..." } }` or `{ "data": { "pending": false } }`

#### `POST /shifts/{id}/photos`
Multipart form data:
- `photo` — JPEG file
- `request_id` — from pending poll
- `captured_at` — UTC ISO string
- `latitude?`, `longitude?`
- `nonce?` — from nonce pool (extra, non-contractual)
- `signature?` — HMAC-SHA256 (extra, non-contractual)

**Response:** `{ "data": { "result": "VALIDATED" } }` or `"FLAGGED"`
**Status: NOT YET IMPLEMENTED server-side.**

---

### Alerts

#### `GET /alerts`
**Response:** `{ "data": { "alerts": [{ "id", "severity", "title", "description", "time", "dismissed" }] } }`

#### `POST /alerts/{id}/dismiss`
Fire-and-forget. Marks alert dismissed server-side.

---

### Error Codes

| Code | HTTP | Description | App behaviour |
|---|---|---|---|
| `INVALID_CREDENTIALS` | 401 | Wrong email/password | Show error message |
| `ACCOUNT_LOCKED` | 423 | 5 failed logins | "Contact your supervisor" |
| `LOGIN_WINDOW_CLOSED` | 403 | Outside shift window | Show server message as-is |
| `TOKEN_EXPIRED` | 401 | Access token expired | Auto-refresh via interceptor |
| `TOKEN_INVALID` | 401 | Corrupt/revoked token | Force sign-out |
| `SHIFT_NOT_STARTABLE` | 409 | Shift can't start now | Snackbar on home screen |
| `SHIFT_NOT_ENDABLE` | 409 | Shift not active | Snackbar on home screen |
| `UNAUTHENTICATED` | 401 | No token at all | Interceptor → sign-out |

---

## 10. Data & Auth Flows — End to End

### App cold start (previously signed in)
```
main() → ProviderScope → IronlockApp
  → authProvider.build() [AsyncLoading → show _SplashView]
  → SecureStorageService.getToken() → token found
  → AuthService.getProfile() [GET /me] → guardProfileProvider updated
  → currentShiftProvider.fetch() [GET /shifts/current]
  → authProvider → AsyncData(signedIn) → HomeScreen shown
  → HomeScreen.initState() → start batteryTimer + pollingTimer + zoneSub
```

### Sign In
```
User types email + password → Sign In button tap
→ _signIn() → authProvider.notifier.signIn(email, password, rememberMe)
  → AuthService.login() [POST /auth/login]
  → save access_token + expires_at
  → if rememberMe: save refresh_token + email
  → guardProfileProvider updated
  → currentShiftProvider.fetch()
  → authProvider → signedIn → AnimatedSwitcher → HomeScreen
```

### 20-second poll cycle (shift inactive)
```
_pollingTimer fires
→ currentShiftProvider.fetch() [GET /shifts/current]
→ currentShiftProvider updated (canStart may flip to true)
→ _ActionButtons rebuilds → START button enables
```

### Starting a shift
```
User taps START
→ Permission.locationWhenInUse.request() → granted (or denied → snackbar)
→ shiftProvider.notifier.start()
  → currentShiftProvider.notifier.start()
    → ShiftService.startShift(id) [POST /shifts/{id}/start]
    → extracts actual_start
    → merges into existing CurrentShiftModel (status→active, canEnd→true)
    → currentShiftProvider.state updated
  → ShiftState(active:true, startTime:actual_start, id, shiftRef)
  → GpsService.startCapture(id) → immediate GPS capture + 15s timer
  → _generateNoncePool() → 15 nonces loaded
→ HomeScreen rebuilds → active layout → ZoneCard visible → elapsed timer starts
```

### GPS cycle (while shift active, every 15 seconds)
```
GpsService._timer fires
→ Geolocator.getCurrentPosition()
→ POST /shifts/{id}/locations {pings: [{lat, lng, accuracy, battery, recorded_at}]}
→ response: data.results[last].zone_status
→ zoneStream.add(zoneString)
→ HomeScreen _zoneSub → zoneProvider.notifier.set(index)
→ ZoneCard rebuilds with new colour/animation
```

### Welfare check (backend-triggered)
```
_pollingTimer fires [while shift active]
→ GET /welfare/pending → {pending:true, check_id:"abc", code:"4821"}
→ wakefulnessProvider.notifier.trigger("abc", "4821")
→ wakefulnessProvider.status → challenge
→ HomeScreen ref.listen → showDialog(WakefulnessOverlay)

WakefulnessOverlay:
→ _fadeCtrl.forward() → fades in
→ _startCountdown() → 1s timer
→ User sees code "4 8 2 1" displayed
→ User taps 4, 8, 2, 1 on keypad → addDigit() each
→ taps OK → _submit()
  → wakefulnessProvider.notifier.submit()
    → entry == code → status:success
    → shiftProvider.notifier.recordWelfareCheck(passed:true)
    → _respond() fire-and-forget [POST /wakefulness/{id}/respond]
  → WakefulnessStatus.success → delay 900ms → _close()
    → _fadeCtrl.reverse() → fade out
    → Navigator.pop() → wakefulnessProvider.notifier.reset()
```

### Photo verification (backend-triggered)
```
_pollingTimer fires [while shift active]
→ GET /photos/pending → {pending:true, request_id:"req-xyz"}
→ pendingPhotoProvider.notifier.setPending(true, requestId:"req-xyz")
→ HomeScreen ref.listen → Navigator.push(PhotoScreen(requestId:"req-xyz"))

PhotoScreen:
→ _initCamera() → real camera or simulated view
→ 1s timer → photoProvider.notifier.tick() (78s countdown)
→ User taps shutter → _capture()
  → _flashCtrl.forward/reverse → white flash
  → photoProvider.notifier.capture() → status:uploading
  → takePicture() OR write 1×1 JPEG fallback
  → _upload(filePath)
    → noncePoolProvider.consume() → nonce
    → PhotoService.uploadPhoto(filePath, shiftId, "req-xyz", nonce)
      [POST /shifts/{id}/photos multipart]
    → result: VALIDATED → photoProvider status:validated
    → shiftProvider.notifier.recordPhoto(passed:true)
```

### Ending a shift
```
User taps END → showEndShiftSheet(context)
→ EndShiftSheet shows summary (start time, duration, welfare checks, photos)
→ User taps "Confirm End Shift"
  → Navigator.pop() (closes sheet)
  → shiftProvider.notifier.end()
    → GpsService.stopCapture()
    → state = const ShiftState() (clears immediately — no optimistic guard)
    → currentShiftProvider.notifier.end()
      → ShiftService.endShift(id) [POST /shifts/{id}/end]
      → merges actual_end + duration_hours into currentShiftProvider state
  → zoneProvider.notifier.set(0)
  → activeTabProvider.notifier.setTab(0)
→ HomeScreen rebuilds → inactive layout
```

### Token refresh (automatic, transparent)
```
Any API call → 401 TOKEN_EXPIRED
→ _JwtInterceptor.onError()
→ SecureStorageService.getRefreshToken() → refresh token
→ POST /auth/refresh {refresh_token, device:{device_id}}
→ save new access_token, refresh_token, expires_at
→ retry original request with new token
→ drain queued concurrent requests
```

### Forced sign-out (terminal refresh failure)
```
POST /auth/refresh → 401 TOKEN_INVALID (refresh token itself rejected)
→ _JwtInterceptor calls forcedSignOutCallbackProvider()
→ AuthNotifier.signOut()
→ POST /auth/logout (ignored)
→ SecureStorageService.clearSession()
→ authProvider → signedOut → LoginScreen
```

### Session resume after app restart with active shift
```
App cold start (token exists) → HomeScreen
→ currentShiftProvider.fetch() → GET /shifts/current
  → response: {status: "active", actual_start: "...", can_end: true}
→ HomeScreen ref.listen(currentShiftProvider)
  → status == "active" AND actual_start != null AND shiftProvider.active == false
  → shiftProvider.notifier.resumeFromServer(currentShift)
    → ShiftState(active:true, startTime:actual_start, id, shiftRef)
    → GpsService.startCapture(id)
    → _generateNoncePool()
→ HomeScreen rebuilds → active layout → END button shown
```

---

## 11. Design System Rules

### Responsive scaling — MANDATORY
Every pixel value must go through:
- `context.s(value)` — layout dimensions. Scales linearly from 390px reference width. Clamped to 0.86× – 1.14×.
- `context.sp(value)` — font sizes. Gentler clamp 0.92× – 1.12×.

**Never hardcode a pixel dimension.** No exceptions.

### Colour Tokens (`AppColors`)

| Token | Hex | Usage |
|---|---|---|
| `bg` | `#07111F` | Screen backgrounds |
| `surface` | `#0F172A` | Cards, overlays, inputs |
| `gold` | `#D4AF37` | Brand accent, START button gradient, END button border+text, active states |
| `border` | `#23344D` | Card borders, input borders, dividers |
| `text` | `#FFFFFF` | Primary text |
| `muted` | mid-grey | Secondary text, labels |
| `subtle` | lighter grey | Hint text, icons |
| `faint` | very faint | Footer text |
| `success` | `#22C55E` | Zone inside, battery OK, welfare passed |
| `warning` | `#F59E0B` | Zone outside, battery low, partial checks |
| `danger` | `#DC2626` | Zone no signal, battery critical, failed checks |
| `dangerBg` | dark red tint | Error box background |
| `warningBg` | dark amber tint | Warning box background |
| `blue` | navy | Keypad press highlight |

### Typography (`AppType` + `google_fonts` Inter)
Always use an `AppType.*` preset, then `.copyWith(fontSize: context.sp(...))` for size overrides.

| Preset | Usage |
|---|---|
| `display` | Large countdown numbers |
| `h2` | Screen titles |
| `h3` | Card headings, section labels |
| `body`, `bodySemi` | Body text |
| `label` | Button labels, field labels |
| `caption` | Subtitle and helper text |
| `micro` | Footer, timestamps, chip labels |

### Gradients (`AppGradients`)
- `background` — full-screen dark gradient (used on Login + Home scaffold).
- `primaryButton` — gold-tinted gradient for the START button.
- `avatar` — guard avatar circle.
- `cardTopHighlight` — subtle top-edge glow on status tiles.
- `bottomSheet` — end shift sheet background.

### State management rules
- **Only `NotifierProvider`**. Never `StateProvider`, `FutureProvider`, or `StateNotifierProvider`.
- No optimistic UI updates — all state changes must come from confirmed server responses.
- Exceptions from service calls are let through to the UI (caught at the `Screen` level for display), except polling errors which are silently swallowed.
