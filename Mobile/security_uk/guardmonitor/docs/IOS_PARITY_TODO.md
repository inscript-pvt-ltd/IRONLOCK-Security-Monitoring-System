# iOS → Android parity — remaining ops steps

**Date:** 2026-06-26
**Goal:** bring the iOS build to full parity with Android. The **app-side** work is done;
what remains needs a **Firebase console** + an **Apple Developer account** (push only).

See `docs/ANDROID_VS_IOS.md` for the full difference list.

---

## ✅ Done in the app (this session)

| Item | What was done |
|---|---|
| iOS build | Verified `flutter build ios --no-codesign` succeeds (Firebase via CocoaPods; SPM disabled). |
| App name | Aligned to **"Guard Monitor"** on both platforms. |
| Screen-capture protection | Native iOS cover (parity with Android `FLAG_SECURE`) in `AppDelegate.swift` — covers the app-switcher snapshot and active screen recording/mirroring/share (`UIScreen.isCaptured`). |
| Push (app side) | `remote-notification` added to `UIBackgroundModes`; Dart already initialises Firebase gracefully when the iOS config is present. |

> **Screenshot note:** iOS has **no API to block a still screenshot** (Android `FLAG_SECURE`
> does). The iOS protection covers the app-switcher and live recording/mirroring/screen-share
> — the closest possible parity. A manual screenshot can still be taken on iOS; the system
> only lets us *detect* it after the fact, not prevent it.

---

## ✅ Done — iOS Firebase config file added (2026-06-26)

**1. `GoogleService-Info.plist` — DONE.**
- Placed at `ios/Runner/GoogleService-Info.plist` (bundle id `com.ironlock.guardmonitor`,
  project `ironlock-security-monitoring` — verified).
- **Registered in the Xcode project** (Runner group + Copy Bundle Resources) via the
  `xcodeproj` gem — confirmed it now lands in the built bundle
  (`build/ios/iphoneos/Runner.app/GoogleService-Info.plist`). Just dropping it in the folder
  is NOT enough; this step is what makes Firebase find it.
- Result: `Firebase.initializeApp()` (in `lib/services/push_messaging_service.dart`) will now
  **succeed on iOS** instead of being swallowed.

> ⚠️ **This alone does NOT make push work.** On iOS, FCM delivers **through APNs** — and even
> *getting* an FCM token requires an APNs token, which requires the Push Notifications
> capability + entitlement (the Apple-account steps below). So after this step Firebase
> *core* initialises, but **no pushes (and likely no token) until §2–§4 are done.**
>
> 🔧 **Git:** the plist is currently **untracked**. Decide the same as `google-services.json`
> (commit both, or git-ignore both + provide a `.example`) so iOS Firebase config isn't lost
> on a fresh clone/CI.

---

## 🔴 Remaining — needs an Apple Developer account (not available yet)

Background/locked-screen push on iOS **cannot work** without these — FCM delivers to iOS
**through APNs**.

**2. Create & upload the APNs auth key.**
- Apple Developer portal → Keys → **+** → enable **Apple Push Notifications service (APNs)**
  → download the **`.p8`** key (note the **Key ID** + your **Team ID**).
- Firebase console → Project Settings → Cloud Messaging → **iOS app** → upload the `.p8`
  with Key ID + Team ID.

**3. Add the Push Notifications capability + entitlement.**
- In Xcode → Runner target → Signing & Capabilities → **+ Capability → Push Notifications**.
  This creates `ios/Runner/Runner.entitlements` with **`aps-environment`** and wires
  `CODE_SIGN_ENTITLEMENTS` into the project.
- ⚠️ **Do NOT add the `aps-environment` entitlement before this** — without a provisioning
  profile that includes the Push capability, the build **fails to code-sign**. That's why it
  was intentionally left out this session.

**4. Provisioning.**
- Ensure the app's provisioning profile (automatic signing with a real Team, or a manual
  profile) includes the Push Notifications capability.

---

## Verification once 1–4 are done

1. `flutter clean && flutter pub get && cd ios && pod install`
2. Build/run on a **real iPhone** (push doesn't work on the simulator).
3. Register the device token (the app already calls `POST /devices/push-token` with
   `platform: ios`).
4. Trigger a `PHOTO_REQUEST` / `WAKEFULNESS_CHALLENGE` from the backend with the app
   backgrounded → the notification should arrive on the lock screen, matching Android.

---

## After all of the above, iOS == Android

The only intentional remaining asymmetry is that **a single still screenshot** can be taken
on iOS (OS limitation) but not on Android. Everything else — features, push, screen-capture
protection, app name — is at parity.
