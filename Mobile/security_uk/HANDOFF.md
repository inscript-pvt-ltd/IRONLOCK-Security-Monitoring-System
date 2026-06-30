# Handoff

Running handoff log for the IronLock Guard Monitor work. **Most recent session at the top.**
Each entry: what changed, current state, what's verified, and what's still open.

---

## 2026-06-26 (cont. 7) — New HTTPS domain + cleartext removed (closes SECURITY #1 / audit C1·H4)

Backend moved to **`https://dashboard.ironlock.co.uk/api/mobile/v1`** (real branded HTTPS host,
replacing the HTTP cPanel host). Because it's now HTTPS, also **removed the cleartext exceptions**
— the #1 security hole (tokens/`hmac_secret`/photos/GPS were travelling unencrypted).

- **URL:** `api_config.dart` default + `config/prod.json` → the new HTTPS base. (`config/dev.json`
  unchanged — local mock.)
- **iOS** `Info.plist`: deleted the `NSAppTransportSecurity` cPanel exception; replaced with just
  `NSAllowsLocalNetworking` (local mock dev only — does **not** relax ATS for any remote host, so
  production is HTTPS-only).
- **Android** `network_security_config.xml`: now `base-config cleartextTrafficPermitted="false"`
  with cleartext allowed only for `localhost`/`127.0.0.1`/`10.0.2.2` (dev mock + emulator host).
- **SSO:** **no app-code change** — `extractShiftAccessToken` matches the `shift-access` path, not
  the domain. Updated the domain in `SHIFT_ACCESS_SSO.md`, `SHIFT_ACCESS_BACKEND_REQUIREMENTS.md`,
  `APPLE_DEVELOPER_ACCOUNT_TODO.md` (the future `applinks:` domain is now `dashboard.ironlock.co.uk`).
- **Docs:** `SECURITY.md` #1 marked **RESOLVED**; cert-pinning (P1 #2) is now **unblocked** (just
  needs the cert/SPKI hash from the backend). `SECURITY_AUDIT.md` / `ANDROID_VS_IOS.md` still show
  the old host as historical audit snapshots (not updated).

✅ analyze clean · ✅ **91 tests** · no old host left in code/native/config (grep-verified).

> Open: (1) **confirm `https://dashboard.ironlock.co.uk/api/mobile/v1` actually serves the mobile
> API** (and the SSO page at `/m/shift-access/<token>`) before relying on it — device sign-in is
> the real test; (2) cert pinning now unblocked — ask backend for the cert/SPKI hash; (3) CLAUDE.md
> still names the old cPanel host (stale, low priority).

---

## 2026-06-26 (cont. 6) — App-side reconciliation of a server auto-closed shift + launcher icon

### ✅ App icon → IronLock gold phoenix
`assets/images/logo-app.png` (1300×1156, transparent) → padded onto brand navy `#07111F` square
sources in `assets/icons/` (`app_icon.png` no-alpha for iOS; `app_icon_foreground.png` for the
Android adaptive icon). `flutter_launcher_icons: ^0.14.4` (dev dep) + config in `pubspec.yaml`;
ran `dart run flutter_launcher_icons` → Android mipmaps + adaptive (`colors.xml` #07111F) + iOS
AppIcon set (21 pngs). Regenerate with `dart run flutter_launcher_icons`. **Delete+reinstall** to
beat the OS icon cache. Login-screen logo unchanged (still `logo.png`).

### ✅ Reconcile a server-closed shift (auto-close / cancel)
**The gap:** the backend auto-closes a shift past `scheduled_end + grace` (`end_type: auto`), but
`/shifts/current` returns `null` for it, and the app **deliberately ignores a null while active**
([shift_provider.dart:22](guardmonitor/lib/providers/shift_provider.dart#L22)) — so the guard's
app stayed on the END screen forever; a manual END would then `409`.

**App half (built now):**
- `ShiftNotifier.reconcileServerClosed()` — tears down like `end()` (stop GPS, cancel reminder,
  clear wakefulness + state) **without** the `POST /end` (already closed → would 409). Idempotent
  (`if (!active) return`).
- `home_screen` currentShift listener now also fires when the server reports the shift
  `completed` **with `end_type == 'auto'`**, or `cancelled`, **while still locally active** →
  reconcile + in-app AppAlert + snackbar. **Critical guard:** gated on `end_type=='auto'`/
  `cancelled` so a **guard's own END** (which also lands as `completed`, but `end_type:guard`/
  `early`) never trips it. Verified `currentShiftProvider.end()` stamps `end_type` from the server,
  so the distinction holds.
- Test: `reconcileServerClosed` is a no-op when inactive (the double-fire guard). 91 tests pass.

**Backend half (needs them):** `docs/AUTO_CLOSE_BACKEND_REQUIREMENTS.md` — the app only triggers if
the backend **surfaces** the closed shift. Asked for **Option A** (return the closed shift once on
`/shifts/current` with `status` + `end_type:auto`, for ~2–3 min) — or Option B (a `SHIFT_CLOSED`
push) — plus confirm the auto-close job is live + the grace value. **Harmless until then:** the
listener simply never fires (the app keeps its current behaviour) until the backend returns it.

✅ analyze clean · ✅ **91 tests**. Not device-tested (needs the backend to return the closed shift).

---

## 2026-06-26 (cont. 5) — Photo-review notification loop (PHOTO_REVIEWED + /photos/reviews)

Closed the deferred review-feedback gap: the guard is now notified when a supervisor
**approves/rejects** a submitted photo, **with the rejection note**. Push + poll converge (same
pattern as the photo-check fix), so it works on **iOS without APNs** via the poll.

- **[push_router.dart](guardmonitor/lib/services/push_router.dart):** new `PushKind.photoReviewed`
  (+ `decision`/`note` fields) and an `onPhotoReviewed` callback in `routePush`. Previously a
  `PHOTO_REVIEWED` push hit `unknown` and was dropped.
- **[photo_review_service.dart](guardmonitor/lib/services/photo_review_service.dart)** (NEW) —
  `PhotoReview` model + `fetchReviews(shiftId)` → `GET /shifts/{id}/photos/reviews` (new
  `ApiConfig.shiftPhotosReviews`).
- **[photo_review_provider.dart](guardmonitor/lib/providers/photo_review_provider.dart)** (NEW) —
  `PhotoReviewNotifier`: single ingest point for push + poll. Dedups by `request_id` (persisted
  via new `SecureStorageService.getSeenReviewIds`/`addSeenReviewId`, capped 80, cleared on
  sign-out). Surfaces every review as an in-app **AppAlert** (rejected→urgent, with note) and
  fires a local tray notification — but only on the **poll** path when `!PushMessaging.isDelivering`
  (so it never duplicates the OS banner a real push already drew).
- **[notification_service.dart](guardmonitor/lib/services/notification_service.dart):** new
  `showPhotoReview(decision, note, requestId)` one-off tray notification (`photo_reviews` channel).
- **Wiring:** `push_messaging_service._dispatch` → `ingestPush`; `home_screen._pollBackend` →
  `pollAndIngest(shiftId, pushDelivering: PushMessaging.isDelivering)` right after the photo poll.
- Cross-platform by default (see memory [[cross-platform-both-android-ios]]).

Tests: `push_router_test` (+2: PHOTO_REVIEWED dispatch + not-actionable), `photo_review_test`
(+4: `PhotoReview.fromJson` approve/reject/note-trim/missing). ✅ analyze clean · ✅ **90 tests**.

> **Known bug (deferred, per user):** tapping **Use Photo** can briefly show a **Try Again**
> button. Likely the upload returns `FLAGGED` (or fails) — the result block shows Try Again for
> `flagged||failed` while the `ref.listen` also auto-pops on `flagged`, so Try Again flashes
> before the pop. Pre-existing (not from the multi-photo change). Investigate: is the test backend
> returning FLAGGED, or is the `photos[]` array upload failing? Fix later.
> Other open: device-test multi-photo + review notifications; strip `[poll-debug]`/`[cam]` temp logs.

---

## 2026-06-26 (cont. 4) — Multi-photo verification (1–5 per request) + env config + security doc

Three things: a config-management setup, an honest security posture doc, then the new
**multi-photo** API feature.

### ✅ Env config via `--dart-define-from-file` (config management, NOT security)
- `config/dev.json` + `config/prod.json` (gitignored) + `config/example.json` (committed).
  `.gitignore`: `config/*.json` except example. `api_config.dart` now reads `ENV` + `API_BASE_URL`
  with an `isProduction` flag; defaults keep a plain `flutter run` on prod.
- Run: `flutter run --dart-define-from-file=config/dev.json`.

### ✅ Security posture — `docs/SECURITY.md`
Honest, risk-prioritised. Headline: **#1 real hole = the API is plain HTTP** (tokens/`hmac_secret`/
photos/GPS in cleartext; both platforms allow it via the iOS ATS exception + Android
network-security config). Fix = backend HTTPS, then remove those. Then cert pinning, then
`--obfuscate` release builds. Everything else (secure storage, no global secrets, screen-capture
protection, HMAC uploads) already solid. A `.env` is NOT on the list — config ≠ security.

### ✅ Photo verification now supports 1–5 images per request (NEW API feature)
From the updated guide: one request can be answered with up to 5 photos in **one** upload
(`photos[]` + `signatures[]`), one nonce, all-or-nothing, success returns `count`. Built to NOT
disturb existing logic:
- **[photo_service.dart](guardmonitor/lib/services/photo_service.dart):** `uploadPhoto` →
  `uploadPhotos(filePaths: List<String>)`. **1 photo = the proven single `photo`/`signature`
  body (zero regression); 2–5 = arrays.** Each image signs with shared fields + its own
  `sha256` (`_sign` → public **`buildSignature`** for testability). `PhotoUploadResult.count`
  added; `kMaxPhotosPerRequest = 5`.
- **[photo_screen.dart](guardmonitor/lib/screens/photo/photo_screen.dart):** `_capturedPaths`
  (committed batch) + held shot. Review controls now **Retake · Add (N/5) · Use N Photos**.
  New removable thumbnail strip (`_CapturedStrip`). Temp files cleaned on success/try-again/
  dispose, and the batch is dropped if the window expires (so no stale thumbnails after reset).
- **FSM untouched** (`photo_provider.dart`): countdown still spans capture+review; **one upload =
  one `recordPhoto`** regardless of image count.
- Tests: `test/services/photo_signature_test.dart` — per-image signature differs only by hash,
  matches an independent HMAC, deterministic, offline shape, count defaults.

**State at end:** ✅ `flutter analyze` clean · ✅ **84 tests pass** (was 78). Dart-only changes
(analyze = compiles). Not yet device-tested for multi-capture UX.

> Open: device-test the 1–5 capture flow; the SSO https-link path is now LIVE backend-side (guide
> confirms the bounce page) — test with a real token; still pending: strip `[poll-debug]`/`[cam]`
> temp logs, iOS APNs (Apple account), backend HTTPS (`SECURITY.md` #1).

---

## 2026-06-26 (cont. 3) — Shift Access Link (SSO) implemented + camera flip lens fix

Two pieces: a camera bug fix, then the new **passwordless deep-link sign-in** from the updated
API guide.

### 🐞✅ Camera flip landed on the ultra-wide lens
On modern iPhones `availableCameras()` returns several **back** lenses (wide, ultra-wide,
telephoto). The flip did `(_cameraIndex + 1) % _cameras.length` — cycling **every** lens — so
flipping to "back" stepped onto the ultra-wide (the zoomed-out look). Fixed in
`lib/screens/photo/photo_screen.dart`: flip is now a strict **front ↔ back toggle** picking the
**first lens of each direction** (the normal wide-angle) via `_indexForDirection`; button
gated on `_hasFrontAndBack`. Added a temp `[cam]` log of the lens list (remove after
on-device confirm) — if a device lists ultra-wide *before* wide, switch the picker to select by
position/name using that log.

### ✅ Shift Access Link (SSO) — passwordless deep-link sign-in (NEW feature)
The one genuinely new thing in the updated `FLUTTER_API_GUIDE.md` (everything else was already
implemented). A supervisor sends a one-time link; tapping it signs the guard into a specific
shift with no password. **Decisions:** domain = the cPanel API host; mechanism = **custom
scheme `ironlock://` now**, Universal/App Links deferred (need the Apple Developer account).

Implemented end-to-end — see **`docs/SHIFT_ACCESS_SSO.md`** for the full map:
- `app_links: ^7.2.0` added (`pod install` run; iOS pod `app_links 7.0.0`).
- New `POST /auth/shift-access` (`ApiConfig.shiftAccess`) + `AuthService.redeemShiftAccess`.
- `AuthNotifier.signInWithShiftAccess` reuses a new shared `_persistSession` with password
  login (identical session storage + post-login `GET /shifts/current`).
- `services/shift_access_link.dart` — pure, unit-tested token parser + error-code→copy map.
- `services/deep_link_service.dart` — `app_links` cold-start + warm listener, started in
  `main.dart`; only acts on a valid 64-hex shift-access token.
- `providers/shift_access_provider.dart` — drives the login screen's "Signing in…" loader +
  failure message (login screen now surfaces redeem errors in its existing error box).
- Native scheme registered: iOS `Info.plist` `CFBundleURLTypes` + Android `AndroidManifest.xml`
  intent-filter (`ironlock://shift-access`).

**🔴 Backend dependency:** a custom scheme is NOT auto-tappable from the **https** link in
WhatsApp/SMS. The backend's `/m/shift-access/<token>` page must **redirect / button to**
`ironlock://shift-access/<token>` (documented in `SHIFT_ACCESS_SSO.md`). Until then, test via
`xcrun simctl openurl` / `adb am start` with a real token.

**State at end:** ✅ `flutter analyze` clean · ✅ **78 tests pass** (was 68; +10 SSO parser/error
tests). `pod install` done. **Not yet device-tested** — needs a real backend-issued token.

> Open: (1) backend adds the https→scheme redirect, then end-to-end test the link;
> (2) Universal/App Links later (Apple account) — `SHIFT_ACCESS_SSO.md` §later + `IOS_PARITY_TODO.md`;
> (3) still pending from cont.2: confirm photo check on iOS + strip `[poll-debug]`/`[cam]` temp logs.

---

## 2026-06-26 (cont. 2) — iOS check delivery: photo parser bug (the real cause), GPS heartbeat, scheduler-suppression fix

User reported that on iOS neither the **photo** nor the **wakefulness** check ever appeared,
while **Android got both**. Drove it to ground with on-device `[poll-debug]` logging (run in
**debug, attached** — `flutter run`, since `debugPrint` is stripped from `--release`). Three
distinct findings; the headline one is **not** an FCM problem at all.

- **🐞✅ FIXED — photo check: `extractPendingPhoto` couldn't parse the real backend's shape.**
  The poll **was** returning the request on iOS the whole time. Device log showed:
  `GET /shifts/{id}/photos/pending → {data: {requests: [{request_id, nonce_value, issued_at,
  expires_at, response_seconds, …}]}}`. But `extractPendingPhoto` ([home_screen.dart](guardmonitor/lib/screens/home/home_screen.dart))
  only handled a nested `request`/`photo_request` object or a **bare** list — **not** a
  `{requests:[…]}` array nested under a map → fell through to `m = data`, found no top-level
  `request_id`, returned null → capture screen never opened. **This was silently broken on
  Android's poll path too** — Android only "worked" because it received the **FCM push**
  instead. Fix: parse `data['requests']` / `data['photo_requests']` as a list (empty list →
  nothing pending; else first element). **FCM is NOT required for the photo check** — the
  foreground poll delivers it on iOS. Added 4 tests incl. the exact device payload →
  **68 tests pass** (was 65).
- **🐞✅ FIXED — iOS wakefulness scheduler was being suppressed.** Adding
  `GoogleService-Info.plist` last session made `PushMessaging.isAvailable` true on iOS
  (Firebase core inits), but APNs still can't issue a token (`apns-token-not-set`). The poll
  gated the local TOTP scheduler on `isAvailable`, so it **stopped running**, waiting on a
  push that never comes. Added **`PushMessaging.isDelivering`** (true only once an FCM token
  actually registers) and gated the scheduler on that instead. iOS now runs the local
  scheduler again. Files: `lib/services/push_messaging_service.dart`, `home_screen.dart`.
- **❗ NOT-A-BUG — a *manual* wakefulness check genuinely cannot reach iOS without FCM.**
  Confirmed from `documents/FLUTTER_API_GUIDE.md` §Wakefulness: the only delivery paths are
  **(a) server FCM push** (online/manual) and **(b) local TOTP at the `schedule` marks**
  (offline). There is **no `/wakefulness/pending` poll endpoint** (only `/respond` +
  `/received`). So unlike photo, there's nothing to poll — a manual/ad-hoc wakefulness check
  is **push-only**. The test shift returned `schedule: []` (empty), so the local path had
  nothing to fire either. To see wakefulness on iOS: either set up **APNs** (online/manual) or
  use a shift with a **non-empty schedule** (offline TOTP, no FCM needed).
- **✅ NEW — GPS iOS heartbeat (parity with Android cadence).** iOS Core Location is
  distance-driven (`distanceFilter: 10`) so a **stationary** guard barely updated, while
  Android updates on a ~15s `intervalDuration`. Added an **iOS-only** 15s `Timer` in
  `lib/services/gps_service.dart` (`_kIosHeartbeat`) that fires a one-shot `getCurrentPosition`
  → ping at Android's cadence; cancelled in `stopCapture()`/`dispose()`. Android untouched
  (would double its pings). Foreground only — backgrounded iOS stays movement-driven (OS limit).

**State at end:** ✅ `flutter analyze` clean · ✅ **68 tests pass**. Code changes complete and
unit-verified; **on-device confirmation of the photo fix still pending** (user to rebuild).
**⚠️ Temp `[poll-debug]` diagnostics are still in `home_screen.dart`** (`_pollBackend`: state
line, `photos/pending` dump, and a now-logged `DioException` that was previously swallowed) —
**remove once the photo fix is confirmed on device.**

> Open: (1) confirm photo check opens on iOS after rebuild, then strip the `[poll-debug]`
> lines; (2) iOS wakefulness (online/manual) still blocked on **APNs / Apple Developer
> account** — `docs/IOS_PARITY_TODO.md` §2–§4; (3) iOS background-stationary GPS still
> movement-driven (would need significant-location-change or a background push).

---

## 2026-06-26 (cont.) — iOS→Android parity pass (build verified, screen-capture protection, app name, push prep)

Worked through the `ANDROID_VS_IOS.md` gaps to bring iOS up to Android. Most "differences"
were already at functional parity (platform plumbing); the real gaps were push, the app
name, and screen-capture protection. User chose **full screen-capture parity on iOS** and
has **Firebase access but no Apple Developer account yet**.

- **✅ iOS build verified.** `flutter build ios --no-codesign --debug` → `✓ Built Runner.app`.
  This **resolves the cont.5 concern** — the stale SPM refs in `project.pbxproj` do **not**
  break the build: with SPM disabled, Flutter's generated package no longer pulls Firebase,
  so the 13.0-vs-15.0 conflict is gone and Firebase resolves via CocoaPods. No pbxproj
  surgery needed.
- **✅ App name parity.** `android:label` `guardmonitor` → **"Guard Monitor"** (matches iOS
  `CFBundleDisplayName`).
- **✅ iOS screen-capture protection (parity with Android `FLAG_SECURE`).** Native, in
  `ios/Runner/AppDelegate.swift`: an opaque "Protected content" cover laid over the window
  on `willResignActive` (app-switcher snapshot) and whenever `UIScreen.isCaptured` is true
  (screen recording / mirroring / ReplayKit share — i.e. a Google Meet phone screen-share).
  Observes `UIScreen.capturedDidChangeNotification`. ⚠️ iOS has **no API to block a still
  screenshot** (Android does) — this covers app-switcher + live capture, the closest parity.
- **✅ iOS push app-side prep.** Added `remote-notification` to `UIBackgroundModes` in
  `Info.plist`. Firebase init is already graceful in Dart. **Deliberately did NOT add the
  `aps-environment` entitlement** — without Apple provisioning it breaks code-signing.
- **📄 New `docs/IOS_PARITY_TODO.md`** — the remaining ops steps: add
  `GoogleService-Info.plist` (Firebase console, user has access) + the Apple-account steps
  (APNs `.p8` upload, Push Notifications capability/entitlement, provisioning).

Quality gates: ✅ `flutter analyze` clean · ✅ `flutter build ios --no-codesign` green ·
✅ **65 tests pass**. Files: `android/app/src/main/AndroidManifest.xml`,
`ios/Runner/AppDelegate.swift`, `ios/Runner/Info.plist`, `docs/IOS_PARITY_TODO.md`.

**Update (same day): `GoogleService-Info.plist` added.** User provided it; placed at
`ios/Runner/GoogleService-Info.plist` (bundle id + project verified) and **registered in the
Xcode project** via the `xcodeproj` gem (Homebrew CocoaPods' Ruby) — confirmed it now lands
in the built bundle (`Runner.app/GoogleService-Info.plist`), so `Firebase.initializeApp()`
will succeed on iOS. ⚠️ Push still won't deliver (and the FCM token likely won't issue) until
the **Apple-account** steps: APNs `.p8` upload + Push Notifications capability/entitlement.
Plist is **untracked in git** — decide commit-vs-ignore alongside `google-services.json`.

**Update (same day): fixed a real iOS permission bug found during on-device testing.** On a
real iPhone the camera/location permission dialogs **never appeared** and the app never
showed up in iOS Settings — the guard was stuck on the permission gate. Cause: the Podfile's
`post_install` was missing the **`permission_handler` macros**, so on iOS the camera/location
handlers are compiled out and `request()` returns "denied" instantly with no dialog (and no
Settings entry). Fix: added `GCC_PREPROCESSOR_DEFINITIONS` `PERMISSION_CAMERA=1`,
`PERMISSION_LOCATION=1`, `PERMISSION_NOTIFICATIONS=1` to `ios/Podfile` `post_install`;
`pod install` → verified the macros are in the Pods build settings; `flutter build ios` green.
This bug had been present since the project start (iOS was never tested on a device before).
On-device repro confirmed the app otherwise runs fine on iPhone (login + shift polling work;
the `aps-environment`/`apns-token-not-set` log lines are expected — no APNs yet). **After this,
the guard must delete+reinstall (or fresh-run) so iOS shows the prompts cleanly.**

> Open: iOS push still needs the **Apple Developer account** (APNs key + Push capability +
> `aps-environment` entitlement) — see `docs/IOS_PARITY_TODO.md` §2–§4. Screen-capture
> protection is code-complete but **needs on-device verification** (simulator can't exercise
> `isCaptured`). After the Apple steps, iOS == Android except a single still screenshot (iOS
> OS limitation).

---

## 2026-06-26 — Android vs iOS platform-difference audit + two actionable build findings

Full static scan of every Android/iOS divergence (native code, build config, both
manifest/plist in full, permissions, all Dart platform branches, federated plugins,
toolchain, networking, signing, identity, launch/splash, receivers). Written up in
**`docs/ANDROID_VS_IOS.md`** (no code changed — documentation only).

**Headline differences:** push notifications (Android live, iOS not — no
`GoogleService-Info.plist` / APNs key / remote-notification mode); screen-capture blocking
(`FLAG_SECURE` Android-only — this is the "black screen on Google Meet share" cause; iOS has
none); background location (Android foreground service vs iOS `UIBackgroundModes:location`);
Firebase build (Gradle vs CocoaPods/SPM-disabled); per-platform plugin impls
(camerax/avfoundation, geolocator_android/apple, etc.); toolchain (Kotlin 2.3.20/Gradle
9.1.0/Java17 vs Swift 5.0/iOS 15.0); app name (`guardmonitor` vs `Guard Monitor`).

**⚠️ Two actionable findings (not yet fixed):**

1. **`android/app/google-services.json` is untracked in git** (Firebase project
   `ironlock-security-monitoring`). It exists locally so Android FCM works here, but a fresh
   clone / CI / another machine would build **without** it and lose Android push. Decide:
   commit it, or git-ignore + provision per-build.
2. **Android release build is signed with DEBUG keys** (`signingConfig =
   signingConfigs.getByName("debug")` in `android/app/build.gradle.kts`, a TODO). Fine for
   `flutter run`, but **blocks a real Play Store release** until a proper keystore +
   `key.properties` is configured.

**Also noted (security, both platforms):** temporary cleartext-HTTP allowances for the
current `http://…cpanel.site` backend — Android `res/xml/network_security_config.xml` + iOS
`NSAppTransportSecurity` exception (both flagged "audit C1"). **Remove both together** once
the backend serves HTTPS.

> Open: commit-or-ignore `google-services.json`; add a real Android release keystore; remove
> the cleartext-HTTP allowances when the backend goes HTTPS. (These join the cont. 5 open
> items: finish iOS SPM-ref cleanup, photo-review loop, iOS FCM/APNs.)

---

## 2026-06-25 (cont. 5) — iOS Firebase build fix (SPM→CocoaPods), API-guide review, photo-review loop deferred

Two unrelated threads this session segment; **no Dart/logic changes** beyond the doc.

### iOS build — Firebase "requires platform 15.0, target supports 13.0" (SPM conflict)

- Cause: Flutter's generated Swift package (`ios/Flutter/ephemeral/Packages/FlutterGeneratedPluginSwiftPackage/Package.swift`) hard-pins **`.iOS("13.0")`** — Flutter's SPM floor for 3.44, **not** derived from the Xcode deployment target. Confirmed: project `IPHONEOS_DEPLOYMENT_TARGET` is 15.0 (all 3) + Podfile `platform :ios, '15.0'`, yet a `flutter clean` + regenerate **still** produced `.iOS("13.0")`. `firebase-messaging`'s SPM package requires 15.0 → the 13.0 umbrella can't consume it.
- Action taken: **disabled Swift Package Manager** (`flutter config --no-enable-swift-package-manager`) so Firebase resolves via **CocoaPods** (which honours the 15.0 Podfile). `flutter clean` + `pub get` + `pod install` → `firebase_core 4.11.0` + `firebase_messaging 16.4.1` now install as **pods** (previously absent from Podfile.lock — they were coming via SPM).
- ⚠️ **NOT verified to build yet — two loose ends:**
  1. `Runner.xcodeproj/project.pbxproj` **still contains the SPM package references** (`XCLocalSwiftPackageReference "FlutterGeneratedPluginSwiftPackage"`, `packageReferences`, `packageProductDependencies`, the Frameworks build file). With SPM disabled these are stale; Xcode may still try to resolve the 13.0 package. **Next step: strip those SPM refs from the pbxproj** (or open in Xcode and remove the package dependency) so it doesn't re-trigger the 13.0 conflict.
  2. `pod install` warned it **didn't set the base configuration** (project has a custom config). `Flutter/Debug.xcconfig` + `Release.xcconfig` already `#include?` the Pods-Runner xcconfig; **`Flutter/Profile.xcconfig` was empty** — confirm all three include the Pods config so the pods actually link.
- Android unaffected (CocoaPods is iOS-only; Android camera/Firebase fine).

### API-guide review (the updated "three push types" FLUTTER_API_GUIDE)

Compared the new guide vs current app. Findings:

- **Already implemented** — `PHOTO_REQUEST` now carries `issued_at`+`response_seconds` (app parses + anchors `deadline = issued_at + response_seconds`); nonce/timeout collapsed to one 90s window (app already defaults 90).
- **No-op** — `WAKEFULNESS_CHALLENGE` can now be supervisor-triggered manually, but it's **wire-identical** (same payload) → nothing to do. **The wellness/wakefulness check has no changes from this guide.**
- **NEW, not implemented** — the **photo review feedback loop**: a `PHOTO_REVIEWED` push (`decision` APPROVED/REJECTED + `note`) and `GET /shifts/{id}/photos/reviews`. The app's router only knows `wakefulness, photo, unknown`, so a `PHOTO_REVIEWED` push hits `unknown` and is dropped. On-device the guard sees a **tray banner** (OS draws the push's `notification` block automatically) but the app ignores the `data` → the rejection **note is never shown in-app**, no status update, reviews endpoint never polled.

### Deferred

- Created **`docs/TODO_PHOTO_REVIEW_LOOP.md`** — full spec + 6-step plan + files-to-touch for the photo-review loop, to implement later (user chose to defer). Recommended surface: existing `alertsProvider` (REJECTED = urgent + note, APPROVED = quiet/info).

> Open: (1) finish the iOS build fix — strip stale SPM refs from pbxproj + confirm Profile.xcconfig includes the Pods config, then verify `flutter build ios`. (2) Photo-review loop deferred (see the new TODO doc). (3) iOS FCM/APNs still pending (unchanged).

---

## 2026-06-25 (cont. 4) — Photo capture flow bugs: flip button stealing taps, blank back-camera on flip, camera-error hardening

Field report: photo verification opens, but the captured photo doesn't show, it doesn't return to the shift screen, and there's no camera flip button — "whole function doesn't work." Tester is on a **real Android phone**. Root-caused via an on-screen `diag:` line (since the failure was in the native camera layer, not derivable from code): it read `cams=2 ready=true noHW=false denied=false err=-` → **the camera worked perfectly**, which redirected the hunt to the UI layout.

- **Root cause (one layout bug → all three symptoms): the flip button was overlapping the shutter and stealing its taps.** The capture controls were `SizedBox(height: 72, child: Stack(... shutter, Positioned(right:28, flip)))`. The `SizedBox` constrained only **height**, so the `Stack` shrink-wrapped to the 72px shutter; the flip's `right: 28` was then measured against that 72px-wide stack, placing it **on top of** the centred shutter. Painted after the shutter, it (a) was hidden → "no flip button", and (b) intercepted shutter taps → tapping "capture" fired `_switchCamera` instead of `_capture`, so no photo was taken → nothing to review → no upload → no return. Fix: `SizedBox(width: double.infinity, …)` so the Stack spans the screen, and `Positioned(right:28, top:0, bottom:0, child: Center(child: _FlipButton))` pins the flip bottom-right, vertically centred.
- **Flip showed a blank back-camera feed.** `_setController` initialized the **new** controller *before* disposing the old one; most Android devices allow only one open camera at a time, so opening the back camera while the front was still held threw "camera in use" → silent `catch (_)` → blank preview. Fix: **dispose the current controller first** (null it, `await dispose()`), then create+initialize the new one. `_switchCamera` now sets `_cameraError` on failure (shows the problem view + Retry) instead of swallowing it.
- **Camera-error hardening (kept from the same debugging pass).** `PhotoScreen` now: explicitly requests `Permission.camera` on init (surfaces a "Camera access needed → Open Settings" view when denied, via permission_handler); retries an empty `availableCameras()` up to 3× (transient empty enumeration after a permission grant); surfaces real `takePicture()` errors (`Capture failed: <reason>` snackbar + `_cameraError`) and verifies the captured file exists before review, instead of the old silent `catch (_)` → fake "simulated" view; `_CameraProblemView` widget + `_retryCamera`; the held shot now stays on screen if the window lapses mid-review (`showCaptured` includes `expired`); shutter disabled unless the camera is genuinely usable (`canCapture`). The temporary `diag:` line was removed after root-causing.

Files: `lib/screens/photo/photo_screen.dart` (all of the above). Plugin note: Android camera impl is `camera_android_camerax` (CameraX) 0.6.30 — registered fine; was never the problem. ✅ analyze clean, ✅ **65 tests pass** (no test changes — pure UI/lifecycle). **Awaiting on-device re-verify**: flip visible bottom-right + switches front/back feed; shutter captures → frozen photo shows → Use Photo uploads → returns to shift screen.

---

## 2026-06-25 (cont. 3) — Wakefulness check: server-verdict reconciliation, deadline-anchored timer, window floor, respond retry

Audited the wakefulness (welfare) check flow for holes; user scoped to the **online (FCM push) path** (offline deferred) and dropped the "retry-until-timeout on a wrong code" idea (wrong code still fails instantly). Implemented 4 fixes:

- **#1 Server verdict is now authoritative (was ignored).** `WakefulnessNotifier.submit()` was synchronous: it compared the entry to the code locally, showed success, and discarded the `PASSED`/`FAIL` that `respond()` returns — so on server clock-skew / an expired server window the guard was told they passed a check the server logged as a miss. `submit()` is now `async`: a wrong code still fails instantly, but a locally-**correct** code moves to a new `WakefulnessStatus.verifying` state and resolves on the server's verdict. A spinner shows in the countdown ring + "Checking…" footer while verifying. Lenient fallback: if the server is unreachable/slow past `kVerifyGrace` (4s), trust the optimistic pass so a connectivity blip can't fail a guard who answered. The welfare counter (`recordWelfareCheck`) is recorded from the resolved verdict, not optimistically.
- **#3 Deadline-anchored countdown (was a frozen-able decrement).** `WakefulnessState` gained `deadline` (wall-clock UTC). `tick()` now **recomputes** remaining from `deadline` instead of `secondsRemaining - 1`, so a timer that froze while backgrounded re-syncs on resume — a guard can no longer pause the clock by locking the screen. The overlay added `WidgetsBindingObserver`; `didChangeAppLifecycleState(resumed)` calls `tick()` to recompute immediately. `tick()` is now an idempotent recompute (safe to fire on backlog/resume).
- **#4 Minimum-window floor.** `trigger()` skipped only when `remaining <= 0`; now skips when `remaining < kMinResponseSeconds` (8, capped at the window itself) so an unwinnable 1–3s late-push challenge isn't shown to guarantee a false alarm — the server's own missed-check handling takes over.
- **#5 Bounded retry on `respond()`/`confirmReceived()` (was `catch(_){}` swallow).** `WakefulnessService.respond()` now retries transient failures (no-response / 5xx) up to 3 attempts with backoff, stamps `responded_at` **once** before the loop, and treats a **4xx as an authoritative reject** (returns `false`, no retry). `confirmReceived()` gets a light 2-attempt retry. The provider's `_report()` wraps it and never throws (returns the optimistic value on exhaustion).

Overlay side-effects (close on success / shake+alert on fail) were moved off the synchronous post-submit read onto a `ref.listen(status)` so a verdict resolved asynchronously is handled identically to a synchronous one (`_onResolved`, guarded by `_resolved` against double-firing).

Files: `lib/providers/wakefulness_provider.dart` (verifying state, deadline, floor, async submit + `_report`, `kMinResponseSeconds`/`kVerifyGrace`), `lib/services/wakefulness_service.dart` (retry + 4xx-as-verdict), `lib/overlays/wakefulness_overlay.dart` (lifecycle re-sync, verifying UI, listener-driven outcome). New `test/providers/wakefulness_verdict_test.dart` (7: reconciliation pass/reject/unreachable/wrong-code, floor shown/not-shown, deadline recompute). ✅ analyze clean, ✅ **65 tests pass** (was 58). Pure-Dart; no native changes. **Not runtime-verified on a device.**

> Open (deferred by user): **#2** one-wrong-digit-no-retry kept as-is (instant fail); **offline path** untouched — offline-while-backgrounded still fires no challenge (H5, OS-level), and an offline correct answer still relies on the bounded retry, not a durable queue. **#6** in-memory challenge state (lost on app-kill; `_handled` resets on restart) not addressed.

---

## 2026-06-25 (cont. 2) — Photo verification: server-anchored timer, screen-off delivery, dual-camera

Android-focused work on photo verification (iOS deliberately deferred — FCM/APNs still not set up). Read `documents/FLUTTER_API_GUIDE.md` + `MOBILE_API_INTEGRATION.md` first: confirmed the backend **already sends a `notification` block** on `PHOTO_REQUEST`, so Android already surfaces the lock-screen notification + tap→camera when backgrounded — the "doesn't show" was the iPhone (no APNs). So no app-drawn notification was needed (would've duplicated). The real gaps were the timer and the camera.

- **Server-anchored 90s timer (was 78s, started on screen-open).** The response window now counts from when the request was **issued/arrived**, not when the camera opens — a late tap spends the elapsed time and can open already-expired. New pure `photoSecondsRemaining(...)` + `kPhotoWindowSeconds = 90` in `photo_provider.dart`; `PhotoState` gained `windowSeconds` (progress bar divides by it, not a literal 78); `PhotoNotifier.startWindow(remaining, windowSeconds)`. Anchor precedence: server `issued_at` → FCM background-isolate arrival stamp → foreground receipt → now.
- **Arrival stamping for the backgrounded case.** `_backgroundHandler` now handles `PHOTO_REQUEST` (was early-returning) and persists `savePhotoReceipt(requestId, now)` via new `SecureStorageService` slot (`getPhotoReceipt`/`clearPhotoReceipt`, wiped in `clearSession`). `PhotoScreen._openWindow()` reads it (one-shot) so a tap minutes after the notification still anchors correctly.
- **Timing plumbed end-to-end (optional, backward-compatible).** `push_router.dart` `onPhoto` now carries `issuedAt`/`responseSeconds`; `extractPendingPhoto` parses `issued_at`/`expires_at`/`response_seconds`; `PendingPhotoState` + `setPending` carry them; `PhotoScreen` takes `issuedAt`/`receivedAt`/`responseSeconds`. All null today (backend sends none) → graceful fallback.
- **Front/back camera toggle.** `PhotoScreen` defaults to front (presence check) but shows a flip button (`_FlipButton`, `Icons.flip_camera_ios_outlined`) when 2+ lenses exist; `_switchCamera()` re-inits the controller via `_setController()` with a re-entrancy guard, idle-only.

Files: `lib/providers/photo_provider.dart`, `lib/services/{secure_storage_service,push_router,push_messaging_service}.dart`, `lib/screens/home/home_screen.dart`, `lib/screens/photo/photo_screen.dart`. New `docs/BACKEND_PHOTO_REQUEST_SPEC.md` (what the backend should add: `issued_at`/`expires_at` + `response_seconds` on the push **and** the pending poll; APNs key for iOS). Tests: new `test/providers/photo_seconds_remaining_test.dart` (6) + photo timing cases in `push_router_test.dart`; `onPhoto` callsites updated to the 4-arg signature. ✅ analyze clean, ✅ **58 tests pass** (was 50). Pure-Dart; no native changes. **Not runtime-verified on a device** — needs an Android device + a live `PHOTO_REQUEST` to confirm screen-off notification → tap → camera with a partially-elapsed timer.

> Open: backend should add `issued_at`/`response_seconds` for *exact* timing (see the new spec); the receipt-stamp fallback covers Android meanwhile. iOS photo screen-off still blocked on FCM/APNs setup (unchanged).
>
> **Update 2026-06-25 (cont. 3): backend shipped items 2 & 3.** The `PHOTO_REQUEST` push now carries `issued_at` + `response_seconds`; `GET /photos/pending` carries all three (`issued_at`/`expires_at`/`response_seconds`). Backend also **collapsed the nonce TTL and request timeout into one 90s window** (`nonce TTL = timeout = response_seconds`), killing a hidden dead zone (nonce died at 60s but timeout was 90s → a ~70s upload hit `NONCE_EXPIRED`/CRITICAL while the timer still showed time left). **No app change needed** — the push (`push_messaging_service` → `setPending`) and poll (`extractPendingPhoto`) already parse these and anchor `deadline = issued_at + response_seconds` ([photo_screen.dart](guardmonitor/lib/screens/photo/photo_screen.dart) `_openWindow`). Spec doc updated (items 2–3 ✅, nonce-collapse noted, new §5 asks for `issued_at` on the **wakefulness** push too — optional, not yet shipped). Only **item 4 (APNs key, iOS)** outstanding, on the backend/ops side.

---

## 2026-06-25 (cont.) — Permission black-screen + photo-not-showing fixes

Two field bugs reported: (a) after granting permissions the overlay closes onto a stuck/blank screen; (b) a backend photo verification request never opens the capture screen.

- **Photo never shows — FCM start on relaunch.** `main.dart` started FCM from a build-time `ref.listen(authProvider)` that only fires on the **sign-in transition**. On a mid-shift app **relaunch** (already signed in) it never fired, so the token wasn't registered and no push (photo *or* wakefulness) reached the device. Moved to `ref.listenManual(authProvider, …, fireImmediately: true)` in `initState`, which also fires for the already-signed-in launch. (Per the guide, push is the **primary** delivery path for `PHOTO_REQUEST`/`WAKEFULNESS_CHALLENGE`.)
- **Photo never shows — tolerant poll parser.** The guide promises `request_id` + `nonce_value` on `GET /shifts/{id}/photos/pending` but never pins the envelope; the old code required `data['pending'] == true` exactly. New top-level `extractPendingPhoto(data)` in `home_screen.dart` tolerates `{pending:true,…}`, a bare `{request_id,nonce_value}`, nested `{request|photo_request|photo|pending_request:{…}}`, a list, and `id`/`nonce` aliases — so the poll **fallback** reliably surfaces a request regardless of shape.
- **Permission → blank screen.** `PermissionGateOverlay._finish()` popped synchronously from inside a lifecycle (`didChangeAppLifecycleState → _checkStatuses`) callback during the permission-dialog resume storm, which could leave a frozen frame. Now defers the pop to a post-frame callback, captures the navigator up front, and guards with `canPop()` so it only ever removes the gate.

Files: `lib/main.dart`, `lib/screens/home/home_screen.dart`, `lib/overlays/permission_gate_overlay.dart`. New test `test/screens/extract_pending_photo_test.dart` (7 cases). ✅ analyze clean, ✅ **50 tests pass**. Pure-Dart; no native changes. Not yet runtime-verified on a device with a live push.

> Open: if the photo *still* doesn't show against the real backend, capture the raw `GET /shifts/{id}/photos/pending` body and the FCM `data` payload to confirm the field names — the parser covers the common shapes but the exact envelope is undocumented.

---

## 2026-06-25 — Flow/conflict audit + fixes (8 holes closed)

### What this session worked on

Audited the whole app for flow holes and functions conflicting with each other (auth, shift lifecycle, wakefulness TOTP+push, photo poll+push, FCM wiring), then fixed the full list:

- **H1 — PhotoScreen stacking.** The 20s poll re-opened a new `PhotoScreen` every cycle when a real backend keeps a photo request pending until fulfilled (the mock hid this via consume-on-read). Added `_handlingPhotoRequestId` dedup in `home_screen.dart` — both the poll and the `ref.listen` skip a request already being handled; freed on the route's `whenComplete`.
- **H2 — Dual wakefulness challenges.** Online (FCM push) and the local TOTP scheduler could both fire for one window with different codes. Gated the scheduler to **offline-or-push-unavailable** (`!online || !PushMessaging.isAvailable`) so push is the single online authority, and added `check_id` dedup inside `WakefulnessNotifier` (`_claim`/`clearHistory`) as defense-in-depth.
- **H3 — Online countdown not anchored to server.** `trigger()` now accepts `issuedAt` (parsed from a new optional `issued_at` push field in `push_router.dart`) and shortens the countdown by elapsed server time; an already-expired challenge raises nothing.
- **H4 — `/received` double-fire.** `_dispatch` takes `confirmReceipt`; only the foreground `onMessage` path sends the receipt (tap/cold-start paths skip it since the background isolate already sent it).
- **H5 — Token not unregistered on sign-out.** Added `PushService.unregisterToken()` (`DELETE /devices/push-token`) + `PushMessaging.clearToken()`; both called in `AuthNotifier.signOut()` before `clearSession()`. Also `ref.invalidate(wakefulnessProvider)` on sign-out.
- **H6 — Dead nonce-pool path.** `noncePoolProvider`/`NoncePoolNotifier`, `_prefetchNoncePool`, `PhotoService.prefetchNonces`, and `ApiConfig.noncesPrefetch` were never consumed (no offline self-initiated capture entry point) and fired a pointless request each shift start — removed.
- **H7 — FLAGGED counted as a failed photo.** `recordPhoto` now counts `VALIDATED || FLAGGED` as completed (flagged = accepted/stored).
- **H8 — CLAUDE.md drift.** Refreshed the stale sections (client-side nonce gen, `photoHmacSecret`, old 3-field signature, polling path) to match the migrated code.

### Files changed

`lib/providers/wakefulness_provider.dart` (dedup + issuedAt), `lib/services/push_router.dart` (issued_at + 4-arg onWakefulness), `lib/services/push_messaging_service.dart` (isAvailable, clearToken, confirmReceipt), `lib/services/push_service.dart` (unregisterToken), `lib/providers/auth_provider.dart` (sign-out unregister + invalidate), `lib/screens/home/home_screen.dart` (photo dedup + offline-gated scheduler), `lib/screens/photo/photo_screen.dart` (flagged counts), `lib/providers/shift_provider.dart` + `lib/services/photo_service.dart` + `lib/config/api_config.dart` + `lib/providers/app_providers.dart` (nonce-pool removal + clearHistory on end), `CLAUDE.md`. New tests: `test/providers/wakefulness_dedup_test.dart`, plus `issued_at` cases + 4-arg signature in `test/services/push_router_test.dart`.

### State at end

✅ `flutter analyze` clean. ✅ `flutter test` — **43 passing** (was 36; +8 new wakefulness/router cases, push_router callback updated). Pure-Dart logic changes, no native/Gradle/plist changes. Not runtime-tested against a live backend/device this session.

### Open / next

- **H2 caveat:** if the real backend provisions a TOTP schedule **and** sends pushes, confirm which is authoritative. The app now treats push as primary online and TOTP as the offline/push-down fallback — verify that matches the backend's intent.
- **H3 / H5 are backend-coordinated:** `issued_at` on the wakefulness push is honored if sent (else full window); `DELETE /devices/push-token` is best-effort (no-op if the route doesn't exist yet).
- iOS FCM still deferred (plist + APNs) — unchanged from the previous session.
- Mock backend still serves the flat `/photos/pending`; the app now calls per-shift `/shifts/{id}/photos/pending`, so update the mock if you want to exercise the photo flow locally.

---

## 2026-06-24 — Real-backend contract migration (hmac_secret, photo signing, server nonces, wakefulness TOTP)

### What this session worked on

Implemented the new `FLUTTER_API_GUIDE` contract changes captured in
[`guardmonitor/docs/API_CONTRACT_MIGRATION.md`](guardmonitor/docs/API_CONTRACT_MIGRATION.md).
The shift lifecycle + GPS already matched; this session was photo verification,
wakefulness, and the auth secret.

### Changes (by migration item)

- **P1.1 — `hmac_secret`**: parsed in `auth_token_model.dart`; new
  `saveHmacSecret`/`getHmacSecret` slots in `secure_storage_service.dart`
  (wiped in `clearSession`); persisted in `auth_provider.signIn` regardless of
  "remember me" (session-scoped, `/me` never returns it). Retired the hardcoded
  `ApiConfig.photoHmacSecret`.
- **P1.2 — photo signature**: `photo_service.dart` now signs the 6-field
  `\n`-joined message (`nonce_value\nrequest_id\ncaptured_at\nlat\nlng\nsha256(image)`)
  keyed with the login secret; multipart field renamed `nonce`→`nonce_value`.
- **P1.3 — server nonces**: added `ApiConfig.noncesPrefetch`; `PhotoService.prefetchNonces`;
  `NoncePoolNotifier` is now a server-fed pool (no client generation — `consume()`
  returns null when empty). Prefetch runs at shift start/resume (offline pool only;
  online uses the request's nonce).
- **P2.1 — pending poll**: switched to per-shift `GET /shifts/{id}/photos/pending`;
  `PendingPhotoState`/`PhotoScreen` now carry `nonce_value`.
- **P2.2 — rejection**: `PhotoRejectedException(reason)` on `422 PHOTO_REJECTED`;
  `PhotoScreen` shows a reason-specific snackbar (HMAC_INVALID → re-login hint).
- **P1.4 — wakefulness TOTP**: new `totp_service.dart` (RFC-6238, base32/hex seed);
  `startShift` returns the `wakefulness` block; `WakefulnessScheduleNotifier`
  persists the seed+schedule (secure storage), restores on resume, and fires
  local TOTP challenges from the active-shift poll. `respond()` sends
  `window_reference` + `is_offline`; overlay window 10s → server `response_seconds`
  (60). Legacy `/welfare/pending` poll kept as a fallback only when no seed was
  issued (i.e. the local mock).
- **P3 — FCM push: Android wired + builds; iOS pending.** Firebase project
  `ironlock-security-monitoring` created; `google-services.json` placed in
  `android/app/`. Added `firebase_core` + `firebase_messaging`; wired the
  `google-services` Gradle plugin (settings + app build.gradle.kts); **`flutter
  build apk --debug` succeeds**. `PushMessaging` (`push_messaging_service.dart`)
  inits Firebase in `main.dart`, registers the token on sign-in (`IronlockApp`
  `ref.listen(authProvider)`), and routes foreground/tap/cold-start + a
  background-isolate `/received` via the pure/tested `push_router.dart`.
  **Confirmed FCM data contract** (backend): `WAKEFULNESS_CHALLENGE`
  `{check_id, shift_id, code, response_seconds}` and `PHOTO_REQUEST`
  `{request_id, shift_id, nonce_value}`. `push_router` + tests were updated to
  these exact type strings.
  ⏳ **iOS still TODO**: add iOS app to the Firebase project →
  `GoogleService-Info.plist` in `ios/Runner/` + APNs key + Push/Background-Modes
  capabilities. `PushMessaging.init()` swallows the failure so iOS runs on the
  polling fallback until then. Steps in
  [`guardmonitor/docs/FCM_SETUP.md`](guardmonitor/docs/FCM_SETUP.md).

### State at end

- `flutter analyze` — **clean**. `flutter test` — **29 passing** (22 prior + 7 new
  RFC-6238 TOTP vectors in `test/services/totp_service_test.dart`).
- Not runtime-verified against the real backend (no access this session).

### Open / next steps

- **⚠️ Local mock now diverges for photos/wakefulness.** `mock-backend/server.js`
  still serves `/photos/pending` (not per-shift), has no `/nonces/prefetch`, issues
  no `hmac_secret`, and returns no `wakefulness` block at start — so photo upload +
  TOTP can't be exercised against the mock until it's updated to the new contract.
  Wakefulness still works via the `/welfare/pending` fallback.
- **Open contract questions** (see migration doc): exact `wakefulness` block shape +
  seed encoding (base32 vs hex — decoder tolerates both); whether each `schedule`
  mark carries a `check_id`; whether `/nonces/prefetch` and per-shift `photos/pending`
  are live on the production host.
- **Push (P3)** still needs a Firebase project + native config to acquire a token
  and wire the `/received` receipt.

---

## 2026-06-24 — Permission flow fix + photo verification UX (single prompt, full-screen camera, retake)

### What this session worked on

UX/flow fixes to permissions and the photo verification screen.

#### 1. Single permission prompt + "stuck screen" bug fix

- **Consolidated to one permission moment.** Background location ("Always") is now
  requested at the launch permission gate, right after When-In-Use is granted —
  the redundant request at shift start was removed. The gate still passes on
  When-In-Use + camera, so a declined background upgrade never locks the guard out.
- **Fixed the "screen stuck after first granting permissions" bug.** Root cause was
  a **double `Navigator.pop()`** in `PermissionGateOverlay._finish()`: both
  `_requestAll()` and the lifecycle-resume `_checkStatuses()` observed
  `_allGranted == true` in the same resume storm, so `_finish()` ran twice — the
  second pop removed the route *below* the gate (HomeScreen / privacy notice),
  leaving a blank screen. Force-quitting "fixed" it because on relaunch the
  permissions were already granted and the gate was never shown. Fix: `_finish()`
  is now idempotent via a `_finished` flag.

#### 2. Photo verification — resolution, full-screen camera, retake-before-upload

- **Lower capture resolution:** `ResolutionPreset.high` → `.medium` (~480p). Smaller
  JPEG → faster uploads, less likely to hit the 60s receive timeout on weak signal.
- **Full-bleed camera-app layout:** the preview now fills the whole screen (cover-scale
  via `Transform.scale` + `ClipRect`) with header/timer + capture controls overlaid on
  gradient scrims, instead of a fixed ~270px boxed card.
- **Retake-before-upload flow (new `reviewing` state):**
  - Tap shutter → photo is held, screen freezes on the still — no upload yet.
  - Review screen shows the captured photo full-bleed with **Retake** + **Use Photo**.
  - The response-window countdown keeps ticking through `capturing`/`reviewing` — the
    whole capture+confirm must fit in the one window. **Retake does NOT reset the
    timer** (`retake()` keeps `secondsRemaining`); only flagged/failed "Try Again"
    does a full reset.
  - On `VALIDATED`/`FLAGGED` the overlay auto-pops back to the shift screen after a
    ~1s confirmation (Navigator captured before the async gap; guarded by `_popping`).
  - Provider is reset to a fresh window each time the screen opens (it's global and
    previously retained its terminal state).

### Files changed

- `lib/overlays/permission_gate_overlay.dart` — idempotent `_finish()`; `_requestAll()` escalates to `locationAlways`
- `lib/screens/home/home_screen.dart` — removed the `locationAlways` request in `_startWithPermissions()`
- `lib/providers/photo_provider.dart` — new `reviewing` state; `startCapture/review/retake/uploading`; tick counts through capturing/reviewing
- `lib/screens/photo/photo_screen.dart` — `.medium` resolution; full-bleed layout; capture→review→confirm flow; auto-pop on success

### State at end

- `flutter analyze` → **No issues**. `flutter test` → **22/22**.
- All photo + permission changes are UI/flow only — no API contract changes.

### Open / next steps

- **On-device verification needed:** the cover-scale preview, full-bleed layout, real
  captured-photo review, and the single "Always" prompt all behave differently on the
  iOS simulator (no camera feed; captured shot is the 1×1 placeholder JPEG → white
  review frame; simulator may not show the separate iOS "Always" dialog).
- Optional: remove the now-redundant "Simulated camera · gallery disabled" label that
  sits behind the bottom controls in the simulated view.
- Carries forward all backend items from prior entries (HTTPS, server-side enforcement,
  push, `/shifts/current` null bug, etc.).

---

## 2026-06-24 — API guide diff applied: new model fields + robust shift-end error handling

### What this session worked on

Two things prompted by the updated `FLUTTER_API_GUIDE.md` (2026-06-22 shift-end rework + 2026-06-24 GPS live):

#### 1. New model fields — `canRequestEarlyEnd` and `endType`

- `CurrentShiftModel` gained two fields:
  - `canRequestEarlyEnd` (`bool`, default `false`) — parsed from `can_request_early_end` in `GET /shifts/current`. Server flag saying the guard may submit an early-end request right now. Incorporates timing + existing-request state so the app doesn't need to check the device clock.
  - `endType` (`String?`) — parsed from `end_type` on completed shifts (`'guard'` / `'early'` / `'auto'`). Not displayed yet but available for future summary screens.
- `ShiftService.endShift()` return record extended with `endType` field.
- `CurrentShiftNotifier.start()` / `end()` in `shift_provider.dart` pass these fields through in the merge.
- `EndShiftSheet` now uses `canRequestEarlyEnd` as the gate for showing the early-end request UI (falls back to device-clock `isEarly` for older backends).

#### 2. Named error handling for new 409 codes

- `ShiftNotifier.end()` restructured: calls the API first, then tears down GPS/timer/state. Previously, local state was cleared before the API call, so 409 rejections (`END_BEFORE_SCHEDULED`, `EARLY_END_NOT_APPROVED`, `EARLY_END_NOT_APPLICABLE`) were silently swallowed and the guard saw the shift disappear even though the server said no. Now a 409 propagates correctly.
- `EndShiftSheet._confirmEnd()` made `async` with full error handling:
  - "End Shift" / "Confirm End Shift" buttons show "Ending…" and disable while the API call is in flight.
  - Sheet only closes (`Navigator.pop`) on a successful server response.
  - Named 409 codes show clear human messages, e.g. "Your early-end request hasn't been approved yet."
  - Generic network errors show "Connection error. Please try again."

### Files changed

- `lib/models/current_shift_model.dart` — added `canRequestEarlyEnd`, `endType`; updated `fromJson`, `copyWith`
- `lib/services/shift_service.dart` — `endShift()` return record adds `endType`
- `lib/providers/shift_provider.dart` — `CurrentShiftNotifier.start/end` pass new fields; `ShiftNotifier.end()` restructured to API-first
- `lib/overlays/end_shift_sheet.dart` — `_confirmEnd` async + error handling; `needsRequest` uses server flag; buttons show loading state
- `test/providers/current_shift_notifier_test.dart` — fake `endShift` return type updated to match

### State at end

- `flutter analyze` → **No issues**. `flutter test` → **22/22**.
- `can_request_early_end` is not yet sent by the mock backend — falls back to device-clock correctly.
- `end_type` will be returned by the real backend on completed shifts; not yet surfaced in the UI.

### Open / next steps

- **Mock backend**: add `can_request_early_end` to `GET /shifts/current` response, `end_type` to `POST /shifts/{id}/end` response.
- **`SHIFT_NOT_ACTIVE` (409)** on GPS pings is already silently swallowed in `GpsService._postPing` catch — correct, no change needed.
- **On-device test** background GPS tracking.
- **Backend:** C1 HTTPS, H1/H2/M1 server enforcement, H5 push, BUG (`/shifts/current` null), L4, C2 — all in `BACKEND_REQUIREMENTS.md`.
- **M7** (release signing) deferred — needs keystore.

---

## 2026-06-24 — Background location tracking (H5 GPS half) + consolidated backend doc

### What this session worked on

Made GPS tracking run in the background (screen locked / app backgrounded), and created a single consolidated backend doc.

**H5 — background GPS (batch 3):**

- **`gps_service.dart`** rewritten from a foreground `Timer.periodic` to `Geolocator.getPositionStream` with background-capable platform settings:
  - Android: `AndroidSettings.foregroundNotificationConfig` → runs a **foreground service** with an ongoing "Shift tracking active" notification (keeps location alive when locked/backgrounded).
  - iOS: `AppleSettings(allowBackgroundLocationUpdates: true, showBackgroundLocationIndicator: true, pauseLocationUpdatesAutomatically: false)`.
  - A one-shot `getCurrentPosition` seeds the first ping; ping-posting extracted to `_postPing`.
- **Android manifest:** added `ACCESS_BACKGROUND_LOCATION`, `FOREGROUND_SERVICE`, `FOREGROUND_SERVICE_LOCATION`, `WAKE_LOCK`.
- **iOS Info.plist:** added `UIBackgroundModes: [location]`; widened the Always-usage string.
- **Permission flow:** `_startWithPermissions` now requests `Permission.locationAlways` (background) after foreground is granted (best-effort).
- `geolocator` re-exports `AndroidSettings`/`AppleSettings`/`ForegroundNotificationConfig`, so no extra deps were needed (tried adding the platform packages, then reverted — redundant).

**Still open for full H5:** the welfare/photo **poll** is still a foreground `Timer` — iOS suspends it in the background. Reliable background welfare needs **server push (FCM/APNs)** = backend work. Documented in `BACKEND_REQUIREMENTS.md` §H5. Background **location** is solved; background **welfare** is best-effort on Android, foreground-only on iOS.

**New doc:** `docs/BACKEND_REQUIREMENTS.md` — consolidated, priority-ordered list of every backend-owned item (C1 HTTPS, H1/H2/M1 enforcement, the BUG, L4, C2, reference, H5 push). Cross-linked from `SECURITY_AUDIT.md` and `BACKEND_SHIFT_END_SPEC.md`.

**Audit doc:** `SECURITY_AUDIT.md` made fully status-aware — Status column in the summary table, inline status line on every finding, batch-3 section, updated priority table + bottom-line progress note. **21/33 fixed in-app + H5 GPS half.**

### Files changed

- `lib/services/gps_service.dart` (rewrite), `lib/screens/home/home_screen.dart` (permission upgrade)
- `android/app/src/main/AndroidManifest.xml`, `ios/Runner/Info.plist`, `pubspec.yaml` (comment only, net no dep change)
- `guardmonitor/docs/BACKEND_REQUIREMENTS.md` (new), `SECURITY_AUDIT.md`, `BACKEND_SHIFT_END_SPEC.md`, `CLAUDE.md`

### State at end

- `flutter analyze` → **No issues**. `flutter test` → **22/22**. `flutter build apk --debug` → **success** (manifest merges cleanly).
- **Not device-verified:** background behaviour (lock screen, walk, confirm pings continue; Android foreground-service notification; iOS blue background indicator) needs a real device. Can't be exercised in the unit-test harness.

### Open / next steps

- **On-device test** the background tracking before relying on it.
- **Backend:** H5 welfare push, plus the rest of `BACKEND_REQUIREMENTS.md` (C1, H1, H2, M1-backend, BUG, L4, C2).
- **M7** (release signing) still deferred (needs keystore).

---

## 2026-06-24 — Medium/High audit fixes (batches 1 & 2) + backend enforcement spec

### What this session worked on

Worked through the recommended Medium/High app-side batches and updated the backend spec.

**Batch 1 (H3, H6, M2, M3, M6, M8):**

- **H3** — `api_client.dart`: wrapped `_retry()` in its own try/catch so a 4xx/5xx on the *retried* request no longer forces sign-out; only a refresh failure is terminal.
- **H6** — `photo_screen.dart`: added `_noHardware` flag; the 1×1 placeholder JPEG is now simulator-only. On a real device a denied/failed camera blocks the upload with a snackbar.
- **M2** — `wakefulness_overlay.dart`: `dispose()` now calls `reset()`, so any teardown path returns welfare to `idle` (no more silently-stalled checks).
- **M3** — `home_screen.dart`: poll reads `check_id`/`code`/`request_id` null-safely; malformed payloads are skipped, not thrown past the catch.
- **M6** — `ui_providers.dart` zone defaults to `2` (unknown); `end_shift_sheet.dart` maps `0→Active throughout`, `1→Left zone`, `2→Interrupted`.
- **M8** — `home_screen.dart`: sign-out no longer calls `end()` — can't silently close an active shift past early-end approval.

**Batch 2 (M5, M4, M1 app-half, H4):**

- **M5** — `photo_screen.dart`: one-shot `getCurrentPosition` (8s timeout) feeds lat/long into the upload; fails open (uploads without coords) rather than blocking.
- **M4** — `gps_service.dart` `startCapture()` now returns `bool`; new `locationDeniedProvider` (ui_providers) drives a persistent red `_LocationOffBanner` in the active screen. Set in `start()`/`resumeFromServer()`, cleared in `end()`.
- **M1 (app half)** — `shift_provider.dart` `fetch()` routes through `_withPreservedPendingLock()`: a local `pending` early-end lock survives polls that omit `early_end_request`; terminal signals (approved/rejected, completed/cancelled) are honoured. +3 regression tests.
- **H4** — `ios/Runner/Info.plist`: scoped `NSAppTransportSecurity → NSExceptionDomains` for the cPanel host (no arbitrary loads). **Temporary** — remove once backend is HTTPS (C1).

**Backend spec:** added **§4 (H1/H2/M1 enforcement)** to `BACKEND_SHIFT_END_SPEC.md` — server decides early-vs-normal from its own clock (H1), scores welfare + owns the tally (H2), echoes `early_end_request` on every poll (M1 backend half). Renumbered old §4/§5 → §5/§6; header note points to §4.

### Files changed

- `lib/services/api_client.dart`, `lib/services/gps_service.dart`
- `lib/overlays/wakefulness_overlay.dart`, `lib/overlays/end_shift_sheet.dart`
- `lib/screens/home/home_screen.dart`, `lib/screens/photo/photo_screen.dart`
- `lib/providers/ui_providers.dart`, `lib/providers/shift_provider.dart`
- `ios/Runner/Info.plist`
- `test/providers/early_end_pending_lock_test.dart` (new, 3 tests)
- `guardmonitor/docs/SECURITY_AUDIT.md`, `guardmonitor/docs/BACKEND_SHIFT_END_SPEC.md`

### State at end

- `flutter analyze` → **No issues**. `flutter test` → **22/22**.
- App not relaunched. M4 banner, H4 ATS, and H6 camera-block are best verified on a real device.

### Open / next steps

- **M7 (release signing)** — deliberately skipped (needs a real keystore + your passwords).
- **Server-side (the big ones):** H1, H2, M1-backend now fully specced in `BACKEND_SHIFT_END_SPEC.md §4` — hand to backend dev.
- **H5 (background execution)** — untouched; foreground-only GPS/welfare is still a known architecture gap.
- **C1 (HTTPS)** — backend; retires the temporary H4 ATS exception when done.
- **C2** — photo nonce/secret model; design decision pending.

---

## 2026-06-22 — Low-severity audit fixes, round 2 (deferred items worked through)

### What this session worked on

Went through the 9 previously-deferred Low findings one by one. Fixed 4 more in-app; consciously kept 5 (reviewed, not skipped). The entire Low tier is now resolved: **11 fixed, 5 intentionally left**.

**Fixed this round:** L6 (Android `FLAG_SECURE` — no screenshots/recording/recents thumbnail), L9 (`AuthNotifier.build()` returns `signedOut` on a storage error instead of `AsyncError`), L11 (photo uses the front camera for presence), L15 (privacy notice shows once per install + acceptance persisted in secure storage).

**Kept by decision:** L4 (backend must stamp authoritative time), L5 (inexact alarm fine — backend auto-close is the guarantee), L7 (reminder tap already foregrounds the active screen), L8 (reactive 401-refresh is the correct pattern), L10 (needs Inter `.ttf` assets — provide them and pubspec wiring follows).

### Files changed

- `lib/providers/auth_provider.dart` — L9 (storage-read guard).
- `lib/screens/photo/photo_screen.dart` — L11 (front camera).
- `lib/services/secure_storage_service.dart` — L15 (privacy-accepted key).
- `lib/screens/home/home_screen.dart` — L15 (`_maybeShowPrivacyNotice`).
- `android/app/src/main/kotlin/com/ironlock/guardmonitor/MainActivity.kt` — L6 (`FLAG_SECURE`).
- `guardmonitor/docs/SECURITY_AUDIT.md` — status section updated (11 fixed / 5 remaining).

### State at end

- `flutter analyze` → **No issues**. `flutter test` → **19/19** (privacy overlay only renders signed-in, so login tests unaffected).
- App not relaunched. `FLAG_SECURE` and the front-camera/privacy changes are best verified on a real Android/iOS device.

### Open / next steps

- Whole Low tier done. Next recommended code batch: **H3, H6, M2, M3, M6, M8**.
- L10 needs Inter font files; L4 + the by-design items are backend/decision, not code.

---

## 2026-06-22 — Low-severity audit fixes (7 of 16) + status update

### What this session worked on

Fixed the clean, low-risk app-side **Low** findings from `guardmonitor/docs/SECURITY_AUDIT.md`, re-audited, and recorded done/deferred status in the doc. No new loopholes introduced (each fix checked; idempotent teardown, graceful fallbacks).

**Fixed (7):** L1 + L12 (name-derivation `RangeError` guards), L2 (`signOut()` stops GPS + cancels reminder), L3 (nonce pool refills, no unsigned uploads), L13 (removed fabricated default alerts), L14 (honest status strip — 3-state GPS tile + aggregate gated on online+in-zone+battery), L16 (passcode field disables autocorrect/suggestions).

**Deferred (9), with reasons:** L4 (backend-owned), L5/L9 (acceptable by design), L6/L11/L15 (product/legal), L7 (needs nav architecture), L8 (future proactive refresh), L10 (needs Inter font assets).

### Files changed

- `lib/providers/auth_provider.dart` — L1, L12, L2.
- `lib/providers/shift_provider.dart` — L3 (nonce refill).
- `lib/providers/alerts_provider.dart` — L13 (removed `_defaultAlerts`).
- `lib/widgets/app_input.dart` — L16.
- `lib/screens/home/home_screen.dart` — L14 (status strip).
- `test/providers/guard_profile_from_email_test.dart` — new (5 cases).
- `guardmonitor/docs/SECURITY_AUDIT.md` — added "Remediation status — Low-severity pass" + inline status markers.

### State at end

- `flutter analyze` → **No issues**. `flutter test` → **19/19** (14 + 5 new).
- App not relaunched this session (logic/UI fixes only). L14's full no-fix honesty still needs **M6** (zone default), a Medium item.

### Open / next steps

- Critical/High/Medium untouched. Recommended next code batch: **H3, H6, M2, M3, M6, M8**.
- Backend items unchanged (HTTPS/C1, early-end + welfare enforcement, `early_end_request` echo).

---

## 2026-06-22 — Early-end now requires supervisor approval

### What this session worked on

Reworked the early-end flow. Previously, ending before `scheduled_end` ended the shift immediately (reason + note captured). Now the guard **requests** an early end and must **wait, locked, for a supervisor/admin to approve** before the END button unlocks and the shift can be ended. Per the user: the app talks to the **real backend** — the mock backend was deliberately left untouched; the deliverable is the app-side implementation plus an endpoint spec for the backend developer.

Design (confirmed with the user):

- On approval → END unlocks, **guard taps END to finish** (no auto-end).
- While waiting → **locked, no cancel**; shift stays active in the background (GPS/welfare continue).
- Decision delivery → **reuse the existing 20s `GET /shifts/current` poll** (no new loop); backend exposes an `early_end_request` object the app reads.

### Files changed

- `lib/config/api_config.dart` — `shiftEarlyEndRequest(id)` → `POST /shifts/{id}/early-end-request`.
- `lib/models/current_shift_model.dart` — parses `early_end_request {status,reason,note}` → `earlyEndStatus/Reason/Note` + `earlyEndPending/Approved/Rejected`; added `copyWith`.
- `lib/services/shift_service.dart` — `requestEarlyEnd(id, reason, note)` (submits request; does not end).
- `lib/providers/shift_provider.dart` — `CurrentShiftNotifier.requestEarlyEnd()` (optimistic `pending`) + `ShiftNotifier.requestEarlyEnd()` delegate.
- `lib/overlays/end_shift_sheet.dart` — status-driven sheet: reason capture→"Request Early End"; `pending`→read-only waiting notice (Close only); `approved`→approved notice + live "End Shift" reusing stored reason/note. Normal end unchanged.
- `lib/screens/home/home_screen.dart` — END circle **locks** (hourglass) while pending; hint reflects pending/approved/rejected; `_CircleEndButton` gained a disabled/`locked` state.
- `guardmonitor/docs/BACKEND_SHIFT_END_SPEC.md` — new **§0** (request endpoint, approve/reject, `early_end_request` on `/shifts/current`); §5 updated. **Send this to the backend dev.**
- `test/models/current_shift_model_test.dart` — +2 tests (pending parse, absent = no request).

### State at end

- `flutter analyze` → **No issues found**. `flutter test` → **14/14 passing**.
- App **not relaunched** this session — not yet exercised on a simulator/device. The flow is end-to-end inert until the backend builds §0 (the request POST will 404 and the status will never arrive).

### Open / next steps

- **Backend (blocking):** implement spec §0 — `POST /shifts/{id}/early-end-request`, supervisor approve/reject, and `early_end_request` on `GET /shifts/current`; enforce that `POST /end` with `ended_early:true` is rejected without an `approved` request.
- Once backend is live: verify request → locked wait → approve → END, plus the reject→re-request path, on a real device.
- Prior backend items still open: auto-close job, `/shifts/current` null-for-active bug, `reference` field deploy, HTTPS.

---

## 2026-06-22 — Context resumption + verification (Remember Me & server-authoritative times)

### What this session worked on

Session resumed from a context rollover. The plan (from the previous context's plan-mode design) for two bug fixes was already fully implemented:

1. **Remember Me** — `AuthNotifier.signIn({rememberMe})` skips saving the refresh token + email when unchecked; `LoginScreen.initState()` pre-fills the email field from secure storage; `_signIn()` passes `rememberMe: _rememberMe`.
2. **Server-authoritative shift times** — `ShiftService.startShift()` returns only `DateTime? actualStart`; `endShift()` returns a named record `{actualStart, actualEnd, durationHours}`. `CurrentShiftNotifier.start()` and `end()` merge the partial response into the existing full `CurrentShiftModel` from the last `GET /shifts/current` poll — no more `fromJson()` on a partial payload that lacks `scheduled_start`/`scheduled_end`.
   Tests for both features already exist (`test/providers/auth_remember_me_test.dart`, `test/screens/login_screen_test.dart`).

### Files changed

All changes already committed; this session was verification-only.

### State at end

- `flutter analyze` → **No issues found**. `flutter test` → **12/12 passing**.
- App not relaunched this session (no code changed).

### Open / next steps

Same as previous entry — the backend items remain the blocker:

- `docs/BACKEND_SHIFT_END_SPEC.md` to be implemented: `ended_early/reason/note` on `POST /end`, auto-close job, `end_type`.
- `GET /shifts/current` null-for-active bug fix, `reference` field deploy, HTTPS.
- Verify reminder + early-end on a real device.

---

## 2026-06-19 (later) — Early-end-with-reason + end-of-shift reminder

### What this session worked on

The shift never auto-ends (confirmed: `end()` only fires from the END button or Sign Out — there's **no** timer at `scheduled_end`, and the backend won't close a shift until the app POSTs `/end`). Built the **app half** of the fix: a guard can end early but must give a reason, and a reminder fires when the duration is up. The **guarantee** (auto-close) is backend work — spec written.

### Files changed

- **NEW `lib/services/notification_service.dart`** — local (not push) notifications via `flutter_local_notifications`. `scheduleShiftEnd(scheduledEnd, shiftRef)` fires a "your shift has ended, tap END to close it" reminder at the absolute end instant (built as `TZDateTime.from(end.toUtc(), tz.UTC)` so no device IANA-zone lookup is needed); `cancelShiftEnd()`; permission requested contextually. `AndroidScheduleMode.inexactAllowWhileIdle` (no exact-alarm permission needed).
- **`lib/overlays/end_shift_sheet.dart`** — now `ConsumerStatefulWidget`. When `isEarly` (`now < scheduled_end`): titled "End Shift Early?", shows reason chips (Incident/Emergency · Illness · Relieved early · Site closed · Other) + a required note (≥10 chars), confirm disabled until valid, with a live "what's missing" hint. New `_ReasonChip`. Wrapped in a scroll view + `viewInsets` padding so the keyboard doesn't cover the note.
- **`lib/services/shift_service.dart`** — `endShift(id, {endedEarly, reason, note})` sends `{ended_early, reason?, note?}` in the POST body (built as a plain map to avoid the `use_null_aware_elements` lint).
- **`lib/providers/shift_provider.dart`** — `CurrentShiftNotifier.end(...)` and `ShiftNotifier.end(...)` thread the reason params through. `start()` + `resumeFromServer()` schedule the reminder; `end()` cancels it.
- **`lib/screens/home/home_screen.dart`** — `_ActionButtons` shows a "Shift ends at HH:MM · ending now needs a reason" hint under the END button when early. New self-ticking `_OverdueBanner` (30s timer) shows once `now > scheduled_end` while active.
- **`lib/main.dart`** — `NotificationService.init()` at startup.
- **`android/app/src/main/AndroidManifest.xml`** — POST_NOTIFICATIONS / RECEIVE_BOOT_COMPLETED / VIBRATE perms + the plugin's `ScheduledNotificationReceiver` + `ScheduledNotificationBootReceiver`.
- **`pubspec.yaml`** — `flutter_local_notifications: ^22.0.1`, `timezone: ^0.11.0`.
- **`test/providers/current_shift_notifier_test.dart`** — `_FakeShiftService.endShift` updated to the new named-param signature.
- **NEW `docs/BACKEND_SHIFT_END_SPEC.md`** — spec for the backend dev (accept reason on `/end`, auto-close overdue shifts, `end_type`).
- **`guardmonitor/CLAUDE.md`** — services map + shift-lifecycle/end section updated.

### Design decisions (the "why")

- **Notification is a reminder, NOT a guarantee.** The phone can't be trusted to fire anything when off/backgrounded/killed/offline, so the real safety net is a **backend auto-close** job. The app sends `ended_early/reason/note`; the backend must store them and close overdue shifts (`end_type: guard | early | auto`).
- **Early end is gated, not blocked.** A lone-worker app must never trap a guard in an active shift — so early end is always available, just requires a reason (recorded + flagged for the supervisor). Mirrors how START is gated with a hint.
- **Local over push:** OS-held, fires when the app is killed, zero backend infra (push would need FCM/APNs you don't have).

### State at end

- `flutter analyze` → **No issues found**. `flutter test` → **12/12 passing**.
- Rebuilt with the new native plugin and **running on iPhone 17 Pro sim** (clean first build this time).
- iOS-sim caveat: notification permission/delivery is flaky on the simulator — verify on a real device.

### Open / next steps (mostly backend now)

- **Backend (blocking the guarantee):** implement `docs/BACKEND_SHIFT_END_SPEC.md` — accept `ended_early/reason/note` on `POST /end`; auto-close overdue active shifts with `end_type`. Plus the still-open `GET /shifts/current` null-for-active bug, `reference` deploy, and HTTPS.
- Verify the reminder + early-end on a **real device**.

---

## 2026-06-19 — Numeric passcode, UI/UX polish, and honest data (removed mock values)

### What this session worked on

1. **Numeric keypad for the passcode field** (guards use an 8-digit code, not a password).
2. **UI/UX pass** using the `ui-ux-pro-max` skill — safety, accessibility, and consistency.
3. **Zone card honesty** — wired to real data instead of hardcoded placeholders.
4. **App-wide mock-value audit + cleanup** — replaced fake values shown to the guard with real ones.

### Files changed

- **`lib/screens/login/login_screen.dart`**
  - Passcode field: `keyboardType: TextInputType.number` (numeric keypad); label → "Passcode", hint → "8-digit code".
  - `_MessageBox` `fontSize: 13` → `context.sp(13)` (responsive rule).
- **`lib/screens/home/home_screen.dart`** (the bulk of the UI/UX work)
  - **Sign Out** now shows a themed confirmation dialog + pressed-opacity feedback (was a single tap that ended the shift). `_SignOutButton` → `ConsumerStatefulWidget`.
  - **START button** shows a spinner and blocks double-taps during the permission+network call. `_ActionButtons` → `ConsumerStatefulWidget` with a `_starting` flag; `_CircleStartButton` gained a `loading` param.
  - **Haptics** (`HapticFeedback.mediumImpact()`) on START and END press.
  - **Emoji glyphs → Material icons**: zone card `✓ ⚠ ⊘` → check/warning/wifi-off; status chips `✓ ● ` → icon chips.
  - **Status strip** no longer color-only: battery/sync/GPS icons change *shape* when degraded, and each tile has a `Semantics` label for VoiceOver/TalkBack.
  - **Elapsed timer** uses `FontFeature.tabularFigures()` so digits don't jitter.
  - **Zone card** wired to real data: real `currentShift.site.name`; new `_ZoneUpdatedLabel` shows a live "Updated Xs ago" from a real timestamp, or "Awaiting first GPS fix…" when there's none (the simulator case); **removed** the fake 0.75 progress bar.
  - **Battery** indicator handles the new nullable provider (`battery_unknown` icon + "Battery level unknown" when null).
  - **Pre-shift status chips** now reflect real state: Online/Offline (`isOnlineProvider`) + Ready-to-start/Awaiting-window (`can_start`) — were hardcoded "GPS Active / Online / All synced".
  - Removed the fake battery `Timer` (provider self-refreshes now).
- **`lib/providers/ui_providers.dart`**
  - New `zoneUpdatedAtProvider` (`DateTime?`) — stamped only when a real `zone_status` arrives; reset on shift end.
  - **`batteryProvider` is now real** (`double?`): reads `battery_plus` on a 30s poll + charge-state stream; `null` = unknown.
- **`lib/services/gps_service.dart`** — pings now send the **real** battery as a 0–1 fraction (or `null`), via a `battery_plus` `_readBatteryFraction()`; was a hardcoded `0.8`.
- **`lib/providers/auth_provider.dart`**
  - Default `GuardProfile` is now neutral `"Guard"` instead of the baked-in demo `j.smith@ironlock.co.uk`.
  - On sign-in, if the login response lacks the guard profile, **fetch `/me`** before falling back to an email-derived name (Category-B fix — no more guessing a name when the real one is available).
- **`lib/widgets/app_chip.dart`** — optional leading `icon` param (vector icon instead of emoji-in-label).
- **`lib/overlays/end_shift_sheet.dart`** — resets `zoneUpdatedAtProvider` on confirmed end.
- **`pubspec.yaml`** — added `battery_plus: ^6.2.0`.
- **`guardmonitor/CLAUDE.md`** — updated quality-gate line (analyze + 12 tests), battery provider, `ui_providers` list, and `gps_service` notes.

### Mock-value audit result (what was found app-wide, by category)

- **A — fully fake → all fixed:** battery indicator, GPS-ping battery, always-on status chips.
- **B — real path w/ guessed fallback → fixed:** zone label honesty, neutral profile default, `/me` fetch on sign-in.
- **C — intentional simulator fallbacks → left as-is (correct):** simulated camera / 1×1 JPEG (labeled on screen, real on device).
- **D — non-contractual / interim, BACKEND-blocked → not app-fixable:** client-side nonce pool (no nonce endpoint), HMAC photo signature (non-contractual), `/welfare/pending` + `/photos/pending` polling (to be replaced by push).

### State at end

- `flutter analyze` → **No issues found**. `flutter test` → **12/12 passing**.
- Rebuilt (native `battery_plus` plugin) and **running on iPhone 17 Pro sim**. NOTE: first rebuild after adding the plugin hit a transient Xcode error 74 (pod/build race) — built clean on retry, no code change needed.
- Battery shows "unknown" on the simulator by design (iOS sim has no battery); real percentage on a device.

### Open / next steps

- **Backend (unchanged, still open):** `GET /shifts/current` returns `null` for an active shift after `scheduled_start`; deploy `reference`; move to HTTPS then remove the Android cleartext exception. Category-D items above also need backend work.
- **Verify on a real device:** real battery %, real GPS zone transitions, and that `/me` returns the true guard profile (so the email-name fallback never fires).
- Optional, still deferred: heavy HomeScreen widget test (polling timers + GPS = high friction).

---

## 2026-06-18 (later) — Test suite + layout hardening via Flutter skills

### What this session worked on

User installed the Flutter skills globally and asked to use them to improve the app.
Audited all four requested areas (layout, tests, responsive, architecture) and, after
greenlight, executed the two highest-value, lowest-risk ones: **automated tests** and
**layout hardening**. Architecture split and a tablet `maxWidth` clamp were proposed but
deferred (high churn / pending whether tablet is a target).

### Files changed

- **NEW** `test/models/current_shift_model_test.dart` — `fromJson` + `displayRef`
  (server `reference` vs UUID fallback) + tolerance of the lean live-backend payload.
- **NEW** `test/providers/current_shift_notifier_test.dart` — pins the partial-response
  **merge** in `CurrentShiftNotifier.start()/end()` (the parse-crash regression): status
  flips, `can_*` flags, `actual_start/end` merged, reference/site/scheduled preserved.
  Uses a `_FakeShiftService extends ShiftService(Dio())`.
- **NEW** `test/providers/auth_remember_me_test.dart` — rememberMe ON saves refresh
  token + email; OFF withholds both but keeps the access token. Mocks the
  flutter_secure_storage method channel with an in-memory map; overrides `authProvider`
  with a `_TestAuthNotifier` whose `build()` is a no-op (the real build() wires the
  forced-sign-out callback, which trips Riverpod's "modify another provider during init"
  assert under test — fine at runtime, noise in a unit test).
- **NEW** `test/screens/login_screen_test.dart` — real widget test: Remember-me checkbox
  toggles; saved email pre-fills on open. Sets `GoogleFonts.config.allowRuntimeFetching=false`.
- **EDIT** `lib/screens/home/home_screen.dart` — `maxLines`+`ellipsis` on guard name (1 line)
  and site name (2 lines) so long server-driven values can't shove the layout.
- **EDIT** `lib/screens/photo/photo_screen.dart` — converted 7 hardcoded sizes
  (SizedBox heights 8/16/16/4; fontSizes 10/16/13) to `context.s()/sp()` per the
  responsive rule.

### State at end

- `flutter analyze` → **No issues found**.
- `flutter test` → **12/12 passing** (incl. pre-existing "App launches").
- All changes are cross-platform; no app behaviour changed, only hardened + covered.

### Follow-up done same session (architecture split + tablet clamp)

- **Split `app_providers.dart`** (was 510 lines) into topic files, keeping
  `app_providers.dart` as a **barrel that `export`s them** so every existing
  `import '.../app_providers.dart'` resolves unchanged (zero consumer churn):
  `auth_provider.dart` (auth + guard profile), `shift_provider.dart`
  (CurrentShift*, Shift*, noncePool), `photo_provider.dart` (Photo*, PendingPhoto*),
  `ui_providers.dart` (zone/battery/privacy/activeTab). No cross-file cycle
  (auth → shift only). CLAUDE.md provider map updated to match.
- **Tablet/wide-screen clamp** in `main.dart` builder: content capped at
  `maxWidth: 560`, centred, letterboxed with `AppColors.bg`. By construction a
  **no-op on phones** (any screen <560 fills as before). NOTE: not visually
  verified on a real tablet/emulator — only the iPhone sim is connected. If
  tablet isn't a target this is harmless; if it is, eyeball it on a tablet.
- Re-ran gates after the split: `flutter analyze` clean, `flutter test` 12/12.

### Open / next steps

- Possible follow-up: a full HomeScreen widget test (heavier — polling timers/GPS).
- Still backend-side (unchanged): deploy `reference`; fix `GET /shifts/current` returning
  null for active shifts; move backend to HTTPS then remove the Android cleartext exception.

---

## 2026-06-18 — Shift start/login bugs, Remember Me, server-time, diagnostics

### What this session worked on

1. **Remember Me** wiring on the login screen.
2. **Server-authoritative shift times** (use backend `actual_start`, not device clock).
3. **"Shift starts in backend but not in the app"** — the main, recurring bug.
4. **Login auto-starting the shift** (a regression I introduced, then fixed).
5. Adding **diagnostic logging** and running the app on the iOS simulator to capture real backend responses.

### Changes made (all currently in the working tree, `flutter analyze` clean)

- **`lib/screens/login/login_screen.dart`**
  - `initState()` pre-fills the email field from `SecureStorageService.getSavedEmail()`.
  - `_signIn()` passes `rememberMe: _rememberMe`.
  - On `LOGIN_WINDOW_CLOSED` + `details.reason == 'expired'`, shows a second "contact supervisor, then tap Sign In again" message box (`_windowExpired`).
- **`lib/providers/app_providers.dart`**
  - `AuthNotifier.signIn(identifier, password, {bool rememberMe = true})` — only saves refresh token + email when `rememberMe` is true.
  - `CurrentShiftNotifier.start()/end()` — merge the **partial** start/end response into the existing full `CurrentShiftModel` (start/end responses only contain `{id,status,actual_start,(actual_end,duration_hours),can_end}`; they do NOT contain `scheduled_start/end`, so we must NOT re-parse a full model from them).
  - `CurrentShiftNotifier.fetch()` — guard: `if (result == null && shiftProvider.active) return;` so a `null` from the server doesn't wipe an in-progress shift.
  - `ShiftNotifier.start()` — robust: a successful POST (any 2xx) ALWAYS sets the local state active; only a `DioException` (real HTTP rejection like `409 SHIFT_NOT_STARTABLE`) propagates.
- **`lib/services/shift_service.dart`**
  - `startShift()` returns `DateTime?` (just `actual_start`); `endShift()` returns a record `{actualStart, actualEnd, durationHours}`.
  - `_extractShift()` + `_parseTime()` — fully defensive parsing; a 2xx NEVER throws on the body (handles non-string / missing fields). Uses `DateTime.tryParse`.
  - Debug logging: prints the POST `/start` response and every GET `/shifts/current` body (`kDebugMode` only).
- **`lib/screens/home/home_screen.dart`**
  - Auto-resume (`ref.listen` on `currentShiftProvider` AND the post-start reconcile in `_startWithPermissions`) now fires **only when `status == 'active'`** — never `checked_in`.
  - `_startWithPermissions` reconciles with the server after any start attempt and only shows an error if the shift truly didn't start.
  - Debug logging of the start() failure type.

### Key domain facts learned this session (see `~/.claude/.../memory/`)

- **Shift lifecycle: `scheduled → checked_in → active → completed`.** Login auto-moves the shift to `checked_in` (NOT started). `checked_in` means "logged in but hasn't pressed START yet" — the app must keep showing the enabled START button and must **never** auto-resume `checked_in` into the active screen. (This was the login-auto-start bug.)
- **Backend bug (flag to backend dev):** `GET /shifts/current` returns `{shift: null}` for a shift that is **active** on the backend once `scheduled_start` passes. The guide says it should return the active shift — this is a contract violation that can strand the app if local state is ever lost (relaunch/re-login mid-shift).
- Backend is the live cPanel Laravel host (`generous-yellow-jaguar.23-111-165-74.cpanel.site/api/mobile/v1`); the mock backend is no longer used.

### State at end of session

- All edits compile; `flutter analyze` → **No issues found**.
- App was rebuilt and **running on the iPhone 17 Pro simulator** (`F390E17F-385D-420C-89E9-E7CF933ADC99`) with the latest code.
- Confirmed via logs that `POST /shifts/{id}/start` returns a clean `200 {status: active, actual_start: ...}`, and that `GET /shifts/current` returns `{shift: null}` after `scheduled_start` (the backend bug above).

### Open / next steps

- **Verify the login fix** end-to-end: log in → should land on the **enabled START** button (not jump to END); tap START → goes active. (Was about to be tested when session ended — earlier failures were caused by testing a **stale build**; always full-restart the `flutter run` after code edits since the backgrounded run can't hot-reload.)
- A local **active-shift persistence** layer (save on start / restore on launch / clear on end) was prototyped to survive the backend `null` bug, then reverted. Re-add only if relaunch-mid-shift resume is actually needed.
- Consider removing the `[shift]` debug logging before release.
- Flag the `GET /shifts/current` null-for-active bug to the backend developer.

### How to run / verify

```bash
cd guardmonitor
flutter analyze                                   # must be clean
flutter run -d F390E17F-385D-420C-89E9-E7CF933ADC99   # iPhone 17 Pro sim
```

After ANY code edit, fully restart `flutter run` (the backgrounded process can't hot-reload). Watch console lines prefixed `[shift]` for the live backend responses.
