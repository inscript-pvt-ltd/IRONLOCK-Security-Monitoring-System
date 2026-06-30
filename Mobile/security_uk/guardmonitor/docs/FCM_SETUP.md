# FCM Push — Status, Data Contract & Setup

**Date:** 2026-06-24
**Firebase project:** `ironlock-security-monitoring` (number `193795657352`)
**Android:** ✅ wired + builds. **iOS:** ⛔ pending (config file + APNs).

Push delivers welfare + photo checks while the app is **backgrounded/locked**.
The documented **polling fallback** ([home_screen `_pollBackend`](../lib/screens/home/home_screen.dart))
still covers the foreground case, so nothing breaks without push — push closes
the background-delivery gap (audit H5).

---

## Status

| Platform | State |
|---|---|
| **Android** | ✅ `firebase_core` + `firebase_messaging` added; `google-services` Gradle plugin wired ([settings](../android/settings.gradle.kts) + [app build](../android/app/build.gradle.kts)); `android/app/google-services.json` in place; **`flutter build apk --debug` succeeds**. App init + token registration + handlers live. |
| **iOS** | ⛔ Not configured. Needs `GoogleService-Info.plist`, an APNs auth key in Firebase, and Push + Background Modes capabilities. `PushMessaging.init()` **swallows the failure** so iOS still runs (FCM just stays off, polling continues) until this is done. |

---

## Confirmed push data contract (backend, 2026-06-24)

FCM data values are always strings. Both pushes also carry a visible
`notification` (title/body) that the OS shows in the tray when backgrounded.
Parsed + dispatched by [`push_router.dart`](../lib/services/push_router.dart)
(unit-tested in [push_router_test.dart](../test/services/push_router_test.dart)).

### `WAKEFULNESS_CHALLENGE`
```json
{ "type":"WAKEFULNESS_CHALLENGE", "check_id":"<uuid>", "shift_id":"<uuid>",
  "code":"4821", "response_seconds":"60" }
```
App: `POST /wakefulness/{check_id}/received` (fire-and-forget) → show the overlay
with `code` + a `response_seconds` countdown. Online path (server-sent code),
distinct from the offline TOTP schedule.

### `PHOTO_REQUEST`
```json
{ "type":"PHOTO_REQUEST", "request_id":"<uuid>", "shift_id":"<uuid>",
  "nonce_value":"<hex>" }
```
App: open the capture flow, sign + upload with `nonce_value` (+ `request_id`).
Mirrors `GET /shifts/{id}/photos/pending`, so push and poll converge.

---

## How it's wired (app side)

- [`push_messaging_service.dart`](../lib/services/push_messaging_service.dart):
  - `PushMessaging.init()` in [`main.dart`](../lib/main.dart) — `Firebase.initializeApp()`
    + registers the background handler. Best-effort (off if platform unconfigured).
  - `PushMessaging.start(ref)` — fired from `IronlockApp` when auth becomes
    `signedIn`: requests notification permission, registers the FCM token via
    `POST /devices/push-token`, and wires foreground (`onMessage`) + tap
    (`onMessageOpenedApp`) + cold-start (`getInitialMessage`) → `routePush`.
  - **Background isolate handler** (`_backgroundHandler`): on a backgrounded
    `WAKEFULNESS_CHALLENGE` it fires `/received` immediately via a standalone
    Dio (the providers aren't available off the UI isolate); the OS draws the
    tray notification from the `notification` block.
- Dispatch targets already existed: `wakefulnessProvider.trigger`,
  `pendingPhotoProvider.setPending`, `wakefulnessServiceProvider.confirmReceived`.
  The home-screen overlay/navigation react to that provider state.

---

## iOS — remaining steps (when we get there)

1. Add an **iOS app** to the `ironlock-security-monitoring` Firebase project
   (bundle id from `ios/Runner.xcodeproj`) → download `GoogleService-Info.plist`
   → add to `ios/Runner/` via Xcode (so it's in the target's Copy Bundle Resources).
2. Upload an **APNs auth key** (.p8) to Firebase → Project Settings → Cloud Messaging.
3. In Xcode Runner target: enable **Push Notifications** + **Background Modes →
   Remote notifications**.
4. No Dart changes expected — `PushMessaging.init()` starts succeeding on iOS
   once the plist is present, and the same handlers run.

---

## Open questions / notes

- **Background `/received` auth**: the background isolate reads the stored bearer
  to POST `/received`. If the token has expired there's no interceptor refresh in
  that isolate — the receipt is then best-effort (caught + ignored). Acceptable:
  a missed receipt only risks a false alarm, not a safety miss.
- **Android 13+ notification permission** is requested via
  `FirebaseMessaging.requestPermission()` at `start()`. Confirm on a real device
  that the runtime prompt appears.
- Not runtime-verified end-to-end (no device + live push this session) — the
  build compiles and the data contract is unit-tested.
