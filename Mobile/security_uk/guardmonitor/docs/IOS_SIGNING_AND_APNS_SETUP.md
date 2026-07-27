# iOS signing + APNs push setup (Organization Apple Developer account)

**App:** IronLock Guard Monitor
**Bundle ID:** `com.ironlock.guardmonitor`
**Date:** 2026-07-23
**Context:** the team now has an **Organization** Apple Developer Program account ($99/yr), held by
the CEO (Account Holder). This doc is the full, end-to-end setup to (a) sign + run on real devices,
(b) turn on **iOS push (APNs)** so the wakefulness/photo notifications finally deliver on iPhone, and
(c) ship to **TestFlight**.

---

## TL;DR — what's already done vs what's missing

Most of the iOS wiring is already in the repo. Only **two** things are missing, and the org account
is what unblocks them.

| Piece | Status |
|---|---|
| Bundle ID `com.ironlock.guardmonitor` | ✅ set in project |
| `DEVELOPMENT_TEAM = VK654TPZ55` | ⚠️ **verify** this is the *org* Team ID, not a leftover |
| `CODE_SIGN_STYLE = Automatic` (Xcode-managed) | ✅ |
| `UIBackgroundModes` = `location` + `remote-notification` | ✅ in `Info.plist` |
| Firebase `GoogleService-Info.plist` | ✅ present in `ios/Runner/` |
| `firebase_core` + `firebase_messaging` deps | ✅ in `pubspec.yaml` |
| **`aps-environment` entitlement** (`Runner.entitlements`) | ❌ **MISSING — Step 2** |
| **APNs Auth Key (`.p8`) uploaded to Firebase** | ❌ **MISSING — Step 3** |

Until the two ❌ items are done, iOS gets **no push** — the app falls back to the foreground
`/wakefulness/pending` + `/photos/pending` polls (which is why a backgrounded iPhone currently misses
manual/online checks). Once done, `PushMessaging.start()` obtains an APNs token, `isDelivering` flips
to `true`, and the local TOTP scheduler steps aside for real server push.

---

## Step 0 — Roles & access (do this first)

You are a **member** of the CEO's Organization team. What you can do depends on the role the Account
Holder assigns you (Apple Developer portal → **People**):

| Task | Minimum role |
|---|---|
| Build & run on a device (development signing) | **Developer** |
| Register the App ID + create the **APNs Auth Key** | **Admin** or Account Holder |
| Create the app record + manage **TestFlight** | **Admin** or **App Manager** |

**Ask the CEO to make you an `Admin`** on the team. Without it you can build to your own device but
**cannot** create the APNs key (Step 3) or the App Store Connect app (Step 4) — the CEO would have to
do those. Everything below assumes Admin; where a step is Account-Holder-only it's called out.

**You'll need:**

- Your Apple ID added to the org team (the CEO invites you via **People → Invite**).
- The org's **Team ID** — Apple Developer portal → **Membership** (a 10-char string like `VK654TPZ55`).
  Confirm it matches the `DEVELOPMENT_TEAM` already in the project; if not, you'll change it in Step 1.
- Access to both portals with that Apple ID:
  - Certificates/Identifiers/Keys: <https://developer.apple.com/account>
  - App Store Connect (TestFlight): <https://appstoreconnect.apple.com>
- A Mac with **Xcode** signed in to your Apple ID (Xcode → Settings → Accounts → **+**).

---

## Step 1 — Point Xcode at the org team

```bash
cd Mobile/security_uk/guardmonitor
open ios/Runner.xcworkspace          # ALWAYS the .xcworkspace, never .xcodeproj (CocoaPods)
```

In Xcode:

1. Select the **Runner** project → **Runner** target → **Signing & Capabilities** tab.
2. **Team** → choose the Organization.
   - If the org Team ID ≠ `VK654TPZ55`, selecting the org here updates it. (You can also set it in
     **Build Settings → Development Team**.)
3. Keep **Automatically manage signing** ✅ — it's already `Automatic`, so Xcode registers the App ID
   and creates the development provisioning profile for you.
4. If you see a red signing error, it's usually "Apple ID not on the team yet" (finish the People
   invite) or "bundle ID taken" (it's registered under another team — see Troubleshooting).

> Do the same **Team** selection for the **RunnerTests** target if you run unit tests on-device
> (optional; not needed for `flutter test`, which runs on the host).

---

## Step 2 — Enable Push Notifications (adds the missing `aps-environment` entitlement)

Still in **Signing & Capabilities** (Runner target):

1. Click **+ Capability** (top-left of the tab).
2. Add **Push Notifications**.
   - Xcode creates `ios/Runner/Runner.entitlements` with `aps-environment` and wires
     `CODE_SIGN_ENTITLEMENTS` into the build settings. **This is the piece that was missing.**
   - It also enables the Push Notifications service on the App ID in the portal.
3. Click **+ Capability** again → add **Background Modes**, and tick:
   - ✅ **Location updates** (the app uses background GPS)
   - ✅ **Remote notifications** (lets a push wake the app in the background)

   These already exist in `Info.plist`; adding the capability keeps the two in sync.

**Verify** afterward: `ios/Runner/Runner.entitlements` exists and contains:

```xml
<key>aps-environment</key>
<string>development</string>
```

Xcode automatically switches this to `production` for a release/TestFlight/App Store build — you do
**not** hand-edit it per build.

> A pre-seeded `Runner.entitlements` may already have been committed to the repo. If so, adding the
> capability in Xcode will reuse it; just confirm the `CODE_SIGN_ENTITLEMENTS` build setting points at
> `Runner/Runner.entitlements` for **both** Debug and Release configurations.

---

## Step 3 — Create the APNs Auth Key and give it to Firebase (this delivers the push)

The backend/FCM path is already built and confirmed by Jerry; iOS just can't **receive** yet because
Firebase has no APNs credential. A single **APNs Auth Key (`.p8`)** fixes it for every environment
(dev + prod), and one key can serve all of the org's apps.

**3a. Create the key** (Admin / Account Holder):

1. <https://developer.apple.com/account> → **Certificates, Identifiers & Profiles → Keys → +**.
2. Name: e.g. `IronLock APNs`. Tick **Apple Push Notifications service (APNs)**. Continue → Register.
3. **Download the `.p8`** — ⚠️ you can only download it **once**. Store it in the team password
   manager, not the repo.
4. Record the **Key ID** (10 chars, e.g. `ABC123DEF4`) and the **Team ID** (from Membership).

**3b. Upload it to Firebase:**

1. Firebase console → the IronLock project → **Project settings** (gear) → **Cloud Messaging** tab.
2. Under **Apple app configuration** → find the iOS app (`com.ironlock.guardmonitor`) →
   **APNs Authentication Key** → **Upload**.
3. Provide the `.p8`, the **Key ID**, and the **Team ID**. Save.

That's it — no per-environment certs, no annual renewal (Auth Keys don't expire like the old APNs
certificates did).

**3c. Confirm the iOS Firebase app exists.** `GoogleService-Info.plist` is already in `ios/Runner/`,
so the iOS app is registered in Firebase. If you ever regenerate it, re-download from Firebase →
Project settings → **Your apps → iOS** and replace the file (keep it out of public repos — it contains
project identifiers, though not secrets).

---

## Step 4 — TestFlight (untethered device testing — the whole point)

TestFlight is the clean way to test **offline sync + push** on a real iPhone with no laptop tether
(killing Wi-Fi over USB-debug drops the VM Service and kills a debug build; a TestFlight build is
standalone).

**4a. Create the app record** (Admin / App Manager):

1. <https://appstoreconnect.apple.com> → **Apps → + → New App**.
2. Platform iOS, pick the bundle ID `com.ironlock.guardmonitor`, set a name + primary language + SKU.

**4b. Build & upload:**

```bash
cd Mobile/security_uk/guardmonitor
flutter clean && flutter pub get
flutter build ipa --release
#   → build/ios/ipa/*.ipa

# Then either open the archive in Xcode Organizer and Distribute:
open build/ios/archive/Runner.xcarchive
#   Xcode → Distribute App → App Store Connect → Upload

# …or upload the .ipa headlessly with an App Store Connect API key:
xcrun altool --upload-app -f build/ios/ipa/*.ipa -t ios \
  --apiKey <KEY_ID> --apiIssuer <ISSUER_ID>
```

**4c. Distribute to testers:**

1. App Store Connect → your app → **TestFlight**.
2. Add **Internal Testers** (you + CEO, up to 100, no review needed) → they install via the
   **TestFlight** app on their iPhone.
3. External testers (up to 10,000) need a short Beta App Review first.

Processing a new build takes a few minutes after upload; you'll get an email when it's testable.

---

## Build & run reference

```bash
# List devices (incl. a wirelessly-paired iPhone)
flutter devices

# Debug run on a physical device (needs Step 1 signing)
flutter run --release -d <device-id>     # release survives losing the network (offline testing)

# One-time: enable native assets for the SQLCipher offline queue (see BUILD_COMMANDS.md)
flutter config --enable-native-assets

# Reset iOS Simulator permissions after denying camera/location
xcrun simctl privacy <sim-id> reset all com.ironlock.guardmonitor
```

Full build matrix (APK, IPA, App Bundle) lives in [`BUILD_COMMANDS.md`](BUILD_COMMANDS.md).

---

## After push is live — app-side follow-ups

Once Steps 2–3 land and a real device shows `isDelivering == true`:

1. **End-to-end verify:** background the app, fire a manual welfare check + photo request from the
   dashboard, confirm both raise a heads-up notification on the locked iPhone and open the right
   screen on tap.
2. **Double-notify suppression:** when push is delivering, the local scheduled reminder and the server
   push could both fire for the same scheduled mark. Watch for it; if it doubles, gate the local
   reminder on `!PushMessaging.isDelivering` for scheduled checks. (Foreground poll already dedups by
   `check_id` / `request_id`.)
3. **Stale-tap parity:** we drop a *tapped* wakefulness push with no `issued_at`; once the backend adds
   `issued_at` to `WAKEFULNESS_CHALLENGE` (asked for in `BACKEND_ASKS_2026-07-22.md` §4), a tapped
   live challenge will open instantly instead of waiting for the poll.

---

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| Xcode: *"No account for team VK654TPZ55"* | Your Apple ID isn't on the org team yet, or the ID is stale — finish the **People** invite and re-select the org **Team**. |
| Xcode: *"Failed to register bundle identifier"* | `com.ironlock.guardmonitor` is registered under a **different** team. Either transfer it, or (last resort) change the bundle ID — but that breaks the existing Firebase app + `GoogleService-Info.plist`, so prefer transfer. |
| Push never arrives on device | (1) APNs key not uploaded to Firebase (Step 3); (2) built in Debug with `aps-environment=development` but expecting prod, or vice-versa — TestFlight/Release uses `production`; (3) notification permission denied on device; (4) no network. |
| `flutter build ipa` fails signing | Run `open ios/Runner.xcworkspace` and let Xcode fix automatic signing once, then retry the CLI. |
| `getToken()` throws `apns-token-not-set` | Expected until Steps 2–3 are done — `isDelivering` stays false and the local scheduler covers welfare checks. |
| Pods / SPM warning about `flutter_secure_storage` | Harmless — that plugin doesn't advertise SwiftPM yet; it still builds. |

---

## Security notes

- The **`.p8` APNs key** and any **App Store Connect API key** are secrets — store in the team
  password manager, never in the repo. The `.p8` can't be re-downloaded; losing it means revoking +
  reissuing.
- `GoogleService-Info.plist` contains project identifiers (not signing secrets) but keep it out of any
  public mirror of the repo.
- APNs **Auth Keys don't expire** (unlike the legacy `.p12` push certificates), so there's no annual
  renewal chore.
