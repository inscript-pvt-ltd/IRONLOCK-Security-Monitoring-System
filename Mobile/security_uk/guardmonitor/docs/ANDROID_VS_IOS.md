# Android vs iOS — Platform Differences

**Scope:** every place the IronLock Guard Monitor app behaves, builds, or is configured
differently between Android and iOS. Based on a full scan of the app on 2026-06-26.

**TL;DR:** the Dart/business logic is shared; the differences are (1) **push
notifications** — fully live on Android, not yet configured on iOS; (2) **screen-capture
blocking** — Android-only; (3) the usual native config (permissions, background execution,
build setup, per-platform plugins).

---

## 1. High-level summary

| Area | Android | iOS |
|---|---|---|
| Push notifications (FCM/APNs) | ✅ Live (`google-services.json` present) | 🔴 Not configured (`GoogleService-Info.plist` missing, no APNs key) |
| Screenshot / screen-record / screen-share | 🔒 **Blocked** (`FLAG_SECURE`) | ✅ Allowed (no block) |
| Background location | Foreground service + ongoing notification | Background location updates |
| Firebase build integration | Gradle (`google-services` plugin) | CocoaPods (SPM disabled) |
| Local notifications | Native receivers + boot receiver | Darwin (APNs-less local works) |
| Camera implementation | `camera_android_camerax` (CameraX) | `camera_avfoundation` |
| Secure storage backing | EncryptedSharedPreferences | iOS Keychain (`first_unlock`) |
| Min OS target | Flutter default minSdk | iOS **15.0** (Firebase requirement) |
| Visible app name | **`guardmonitor`** (`android:label`) | **`Guard Monitor`** (`CFBundleDisplayName`) |
| Cleartext-HTTP backend allowance | `network_security_config.xml` | `NSAppTransportSecurity` domain exception |
| Release signing | Debug keys (no release keystore yet) | Xcode signing |

---

## 2. Functional differences (what actually works where)

### 2.1 Push notifications — the big one
- **Android: LIVE.** `android/app/google-services.json` is present, so Firebase
  initialises and FCM delivers `WAKEFULNESS_CHALLENGE` / `PHOTO_REQUEST` / `PHOTO_REVIEWED`
  pushes — including on the locked screen.
- **iOS: NOT working yet.** Three things are missing:
  1. `ios/Runner/GoogleService-Info.plist` — **absent**, so Firebase doesn't initialise on
     iOS. `PushMessagingService.init()` is written to **swallow this gracefully**
     ([push_messaging_service.dart:20,40](../lib/services/push_messaging_service.dart#L20))
     — the app runs fine, it just gets no pushes.
  2. **APNs auth key** not uploaded to Firebase (ops step — see `APNS_KEY_UPLOAD.md`).
  3. No push entitlement / background mode for remote notifications (see §4.3).
- **Net effect:** on iOS, a backgrounded/locked device receives **no** welfare or photo
  pushes. The in-app **polling fallback** still surfaces checks when the app is foregrounded
  (`/welfare/pending`, `/shifts/{id}/photos/pending`), but the screen-off "tap the
  notification" flow is Android-only until iOS FCM/APNs is set up.

### 2.2 Screen capture / screen-sharing
- **Android: blocked.** [MainActivity.kt:14-17](../android/app/src/main/kotlin/com/ironlock/guardmonitor/MainActivity.kt#L14)
  sets `WindowManager.LayoutParams.FLAG_SECURE` (audit L6). Screenshots, screen recording,
  app-switcher thumbnails, **and casting/mirroring (e.g. Google Meet screen-share) all show
  black**.
- **iOS: not blocked.** There is **no** equivalent screen-capture protection in the iOS
  code (confirmed: no `isSecure`/secure-window logic). The comment in `MainActivity.kt`
  notes "iOS cannot fully block screenshots; this is Android-only." So the **same app
  screen-shares normally on iPhone**.

---

## 3. Code-level platform branches (Dart)

These are the only spots in `lib/` that branch on the platform:

| File | Difference |
|---|---|
| [`gps_service.dart`](../lib/services/gps_service.dart) | `AndroidSettings` (foreground service + ongoing "Shift tracking active" notification) vs `AppleSettings` (`allowBackgroundLocationUpdates`, pauses disabled). Two genuinely different background-location mechanisms. |
| [`push_service.dart`](../lib/services/push_service.dart) | Sends `'platform': Platform.isIOS ? 'ios' : 'android'` when registering the push token. |
| [`device_info_service.dart`](../lib/services/device_info_service.dart) | Reports `'android'` / `'ios'` in the device descriptor sent on login/refresh. |
| [`notification_service.dart`](../lib/services/notification_service.dart) | `AndroidInitializationSettings` + `AndroidNotificationDetails` vs `DarwinInitializationSettings` + `DarwinNotificationDetails`; permission request path differs (iOS always asks; Android only 13+). |
| [`secure_storage_service.dart`](../lib/services/secure_storage_service.dart) | iOS uses `IOSOptions(accessibility: KeychainAccessibility.first_unlock)` (Keychain). Android uses the default EncryptedSharedPreferences. |
| [`connectivity_service.dart`](../lib/services/connectivity_service.dart) | Note/handling for the iOS simulator, where WiFi connectivity events are unreliable. |
| [`app_theme.dart`](../lib/theme/app_theme.dart) | Page-transition builders set per `TargetPlatform` (both Cupertino — cosmetic). |

Everything else (providers, models, screens, shift/photo/wakefulness logic) is **fully
shared** — no platform forks.

---

## 4. Native configuration differences

### 4.1 Permissions

**Android** — declared in `AndroidManifest.xml`:
`INTERNET`, `CAMERA`, `ACCESS_FINE_LOCATION`, `ACCESS_COARSE_LOCATION`,
`ACCESS_BACKGROUND_LOCATION`, `FOREGROUND_SERVICE`, `FOREGROUND_SERVICE_LOCATION` (API 34+),
`WAKE_LOCK`, `POST_NOTIFICATIONS` (API 33+), `RECEIVE_BOOT_COMPLETED` (re-arm reminders
after reboot), `VIBRATE`.

**iOS** — usage strings in `Info.plist`:
`NSCameraUsageDescription`, `NSLocationWhenInUseUsageDescription`,
`NSLocationAlwaysAndWhenInUseUsageDescription`. (iOS has no notification-permission *string*
— it's requested at runtime via the plugin.)

> Android needs the foreground-service + boot + post-notifications permissions that iOS has
> no concept of; iOS needs human-readable usage strings that Android doesn't.

### 4.2 Background execution
- **Android:** background GPS runs as a **foreground service** with a persistent "Shift
  tracking active" notification (required on modern Android), plus `flutter_local_notifications`
  receivers (`ScheduledNotificationReceiver`, `ScheduledNotificationBootReceiver`) for the
  end-of-shift reminder surviving reboot.
- **iOS:** background GPS uses `UIBackgroundModes: location` (the only background mode
  declared — note **`remote-notification` is NOT present**, another reason background push
  isn't wired yet).

### 4.3 Build & Firebase integration
- **Android:** Firebase via the Gradle `google-services` plugin reading
  `android/app/google-services.json`. SDK levels come from Flutter defaults
  (`minSdk`/`targetSdk`/`compileSdk = flutter.*`). App id `com.ironlock.guardmonitor`.
- **iOS:** deployment target **15.0** on all three build targets + `platform :ios, '15.0'`
  in the Podfile (Firebase requires 15.0). **Swift Package Manager is disabled**
  (`enable-swift-package-manager: false`) so Firebase resolves via **CocoaPods** — the pods
  are now installed (`Firebase/Messaging 12.15.0`, `firebase_core 4.11.0`,
  `firebase_messaging`). This was needed because Flutter's generated SPM package pins iOS
  13.0, which conflicts with Firebase's 15.0 requirement. *(See HANDOFF cont. 5 — there are
  still stale SPM references in `project.pbxproj` to clean up before iOS is build-verified.)*
- **`flutter_secure_storage`** does not support SPM on iOS — another reason CocoaPods is the
  iOS integration path.

### 4.4 Native entry points
- **Android:** `MainActivity.kt` (Kotlin) — also where `FLAG_SECURE` is set.
- **iOS:** `AppDelegate.swift` — standard Flutter delegate, registers plugins; no custom
  security or push handling yet.

### 4.5 Cleartext HTTP (backend is currently `http://…cpanel.site`)

Both platforms block plain HTTP by default and **both have a temporary allowance** for the
current backend domain (flagged "audit C1 — remove once the backend serves HTTPS"), but via
different mechanisms:
- **Android:** `android:networkSecurityConfig="@xml/network_security_config"` →
  `android/app/src/main/res/xml/network_security_config.xml`.
- **iOS:** an `NSAppTransportSecurity` → `NSExceptionDomains` entry for
  `generous-yellow-jaguar.23-111-165-74.cpanel.site` with `NSExceptionAllowsInsecureHTTPLoads`.
> Both must be removed together when the backend moves to HTTPS.

### 4.6 App identity, signing & build

- **Visible app name differs:** Android shows **`guardmonitor`** (`android:label`), iOS shows
  **`Guard Monitor`** (`CFBundleDisplayName`). Internal names align (`CFBundleName` =
  `guardmonitor`). Bundle/app id is the same on both: `com.ironlock.guardmonitor`.
- **Release signing:** Android's release build is currently **signed with the debug keys**
  (`signingConfig = signingConfigs.getByName("debug")` — a TODO; needs a real release
  keystore before store distribution). iOS signs via Xcode/Apple provisioning.
- **Android-only build requirements:** core-library **desugaring** + **Java/Kotlin 17**
  (needed by `flutter_local_notifications`' use of `java.time`), and the
  `com.google.gms.google-services` Gradle plugin (reads `google-services.json`). iOS has no
  equivalents.
- **Note:** `android/app/google-services.json` exists locally (Firebase project
  `ironlock-security-monitoring`) but is **untracked in git** — make sure it's committed (or
  intentionally git-ignored and provisioned per-build) so Android FCM keeps working on other
  machines/CI.

### 4.7 Orientation

- **iOS:** portrait + landscape-left + landscape-right allowed (`UISupportedInterfaceOrientations`).
- **Android:** no orientation lock declared (system default). Minor; the UI is built
  responsive either way.

### 4.8 Toolchain & SDK versions

| | Android | iOS |
|---|---|---|
| Language/version | Kotlin **2.3.20**, Java/JVM **17** | Swift **5.0** |
| Build system | Gradle **9.1.0**, AGP + Flutter Gradle plugin | Xcode (project `LastUpgradeCheck 1510`) + CocoaPods |
| SDK floor | `minSdk = flutter.minSdkVersion` (Flutter default) | Deployment target **15.0** |
| Extras | core-library **desugaring** (`desugar_jdk_libs 2.1.4`), NDK from Flutter | — |

### 4.9 Launch / splash screen

- **Android:** native splash via theme — `LaunchTheme`/`NormalTheme` in **both**
  `values/styles.xml` (light) **and** `values-night/styles.xml` (dark), background drawable
  `launch_background.xml` (plain white). `Theme.Light/Black.NoTitleBar`.
- **iOS:** `LaunchScreen.storyboard` + `Main.storyboard`, UIKit **scene-based**
  (`UIApplicationSceneManifest`). Different mechanism entirely.
- App icons are separate asset pipelines (iOS `AppIcon.appiconset`, Android `mipmap-*`),
  both populated.

### 4.10 Android Activity & receivers (no iOS equivalent)

From `AndroidManifest.xml`:

- **Activity config:** `launchMode="singleTop"`, `taskAffinity=""`, `hardwareAccelerated`,
  `windowSoftInputMode="adjustResize"`, and a broad `configChanges` list (orientation,
  locale, density, uiMode…) so the activity handles those changes without recreating.
- **Boot/reschedule receivers:** `flutter_local_notifications` registers
  `ScheduledNotificationReceiver` + `ScheduledNotificationBootReceiver` listening for
  `BOOT_COMPLETED` / `MY_PACKAGE_REPLACED` / `QUICKBOOT_POWERON` — to **re-arm the
  end-of-shift reminder after a reboot**. iOS keeps scheduled local notifications across
  reboot automatically, so it needs none of this.
- **`<uses-feature camera required="false">`** so the app still installs on camera-less
  Android hardware.
- **`PROCESS_TEXT` queries** block (Flutter engine requirement) — Android-only.

---

## 5. Per-platform plugins

The federated plugins resolve to different native implementations. Full split from
`.flutter-plugins-dependencies`:

| Capability | Android native impl | iOS native impl |
|---|---|---|
| Camera | `camera_android_camerax` (CameraX) | `camera_avfoundation` |
| Location | `geolocator_android` | `geolocator_apple` |
| Permissions | `permission_handler_android` | `permission_handler_apple` |
| File paths | `path_provider_android` | `path_provider_foundation` |
| Lifecycle/JNI glue | `flutter_plugin_android_lifecycle`, `jni`, `jni_flutter` | *(none — iOS doesn't need them)* |
| Firebase / messaging | via `google-services` Gradle plugin | via CocoaPods pods |
| Local notifications | native + boot receivers | Darwin |

**Shared (same federated package both sides):** `battery_plus`, `connectivity_plus`,
`firebase_core`, `firebase_messaging`, `flutter_local_notifications`,
`flutter_secure_storage`.

> Android pulls **13** native plugins, iOS **10** — the extras on Android are the JNI/
> lifecycle glue CameraX needs.

---

## 6. Simulator / emulator caveats (dev only)

- **iOS simulator:** no camera hardware (the app falls back to a simulated view + a dummy
  image); reports a negative/"unknown" battery level (surfaced as unknown, not faked); WiFi
  connectivity events are unreliable. None of these affect a real iPhone.
- **Android emulator:** generally exposes working (emulated) cameras and battery.

**Supported build targets:** only **`android/`** and **`ios/`** are intended targets. A
**`web/`** folder exists (default Flutter scaffolding) but the app is **not** designed for
web — it relies on camera, background location, push, and `FLAG_SECURE`, all of which are
mobile concepts. `macos/`, `windows/`, `linux/` folders are absent. The FCM background
handler is a top-level `@pragma('vm:entry-point')` function (`_backgroundHandler`) that runs
in a background isolate — functional on Android, dormant on iOS until Firebase is configured.

---

## 7. Outstanding iOS work (to reach Android parity)

1. Add `ios/Runner/GoogleService-Info.plist` (Firebase iOS app config).
2. Upload the **APNs auth key** to Firebase (ops step).
3. Add the push capability / `remote-notification` background mode + an `.entitlements`
   file with `aps-environment`.
4. Finish the CocoaPods migration — strip the stale SPM package references from
   `Runner.xcodeproj/project.pbxproj` and verify `flutter build ios`.
5. (Optional, by design) decide whether iOS should get any screen-capture protection — it
   currently has none, which is why iOS can screen-share/record freely.

Until 1–3 are done, **iOS gets no background pushes**; everything else (sign-in, shifts,
GPS, welfare checks, photo capture) works on iOS via the shared Dart code + the polling
fallback.

---

## 8. One-line takeaway

> The app is **one shared codebase**; the only behavioural gaps are **push notifications
> (Android-only until iOS FCM/APNs is set up)** and **screen-capture blocking
> (Android-only by design)**. Everything else differs only in native plumbing
> (permissions, background execution, build integration), not in features.
