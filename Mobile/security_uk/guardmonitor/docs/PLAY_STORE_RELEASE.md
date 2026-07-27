```shell
flutter clean
flutter pub get
flutter build appbundle --release \
  --obfuscate --split-debug-info=build/symbols
```

# Play Store Release Guide — IronLock Guard Monitor (Android)

**App:** IronLock Guard Monitor (Flutter)
**Package (applicationId):** `com.ironlock.guardmonitor` — ⚠️ **cannot be changed after first upload**
**Date:** 2026-07-27
**Audience:** whoever ships the Android build.

---

## ⚠️ Current blocker (read first)

**UPDATE 2026-07-27 — the signing config is now wired up (step 3 is DONE in code).**
`android/app/build.gradle.kts` now uses a **release** signing config when
`android/key.properties` is present, and falls back to debug when it isn't (so local builds
still work). R8 shrink is on. **What's still missing is the keystore itself** — you must still
do **step 2** (generate `~/ironlock-release.jks` + create `android/key.properties`). Until that
file exists, `flutter build appbundle --release` produces a **debug-signed** bundle that **Google
Play will reject**. Create the keystore, then the same build command produces an
upload-ready, release-signed AAB automatically.

Verified: `flutter build appbundle --release --obfuscate --split-debug-info=build/symbols`
succeeds today (debug-signed fallback), so R8 + the ProGuard rules build cleanly.

Other pre-release facts about this project:

- `version: 1.0.0+1` in `pubspec.yaml` (the `+1` = versionCode).
- `google-services.json` is committed (FCM). Fine for Android; keep it.
- R8/minify is currently **off** — step 3 turns it on to shrink the app.
- Nothing is committed to git yet; iOS FCM/APNs is not set up (unrelated to Android release).

---

## 0. Prerequisites (one-time)

- [ ] **Google Play Developer account** — one-time $25 at [https://play.google.com/console](https://play.google.com/console).
- [ ] **Create the app** in the console; set package name `com.ironlock.guardmonitor`.
- [ ] **Privacy policy URL** — required (the app collects location + photos). Host it somewhere
  public and paste the URL into the console.

---

## 1. Version

Each upload needs a **higher versionCode** than the last. In `pubspec.yaml`:

```yaml
version: 1.0.0+1      # first release: versionName 1.0.0, versionCode 1
# next upload example:
# version: 1.0.1+2    # bump the +N every single upload
```

Flutter maps `+N` → Android `versionCode`, and the part before `+` → `versionName`.

## 2. Create a release keystore (do once, back it up forever)

```bash
keytool -genkey -v -keystore ~/ironlock-release.jks \
  -keyalg RSA -keysize 2048 -validity 10000 -alias ironlock
```

⚠️ **Back up `~/ironlock-release.jks` and its passwords in a safe place.** If you lose the
signing key you can **never publish an update** to the same app again.

**Strongly recommended: enrol in Play App Signing** (the console offers this on first upload).
Google then holds the *app signing key* and you keep only an *upload key* — if the upload key
is ever lost, Google can reset it. Without it, a lost key = a dead app.

Create **`android/key.properties`** (⚠️ **add it to `.gitignore` — never commit it**):

```properties
storePassword=YOUR_STORE_PASSWORD
keyPassword=YOUR_KEY_PASSWORD
keyAlias=ironlock
storeFile=/Users/sadukaathukorala/ironlock-release.jks
```

---

## 3. Wire signing (+ R8 shrink) into `android/app/build.gradle.kts` — ✅ DONE

**This is already implemented in the repo** (2026-07-27). The file now loads
`key.properties` if present, defines a `release` signing config from it, uses that for release
builds (falling back to debug when the file is absent), and enables R8 (`isMinifyEnabled` +
`isShrinkResources`) with `android/app/proguard-rules.pro`. No action needed here — creating the
keystore in step 2 is what activates it. The reference implementation is below for the record.

Near the top of the file, load the properties:

```kotlin
import java.util.Properties
import java.io.FileInputStream

val keystoreProperties = Properties()
val keystorePropertiesFile = rootProject.file("key.properties")
if (keystorePropertiesFile.exists()) {
    keystoreProperties.load(FileInputStream(keystorePropertiesFile))
}
```

Inside `android { … }`, add a `signingConfigs` block:

```kotlin
signingConfigs {
    create("release") {
        keyAlias = keystoreProperties["keyAlias"] as String?
        keyPassword = keystoreProperties["keyPassword"] as String?
        storeFile = (keystoreProperties["storeFile"] as String?)?.let { file(it) }
        storePassword = keystoreProperties["storePassword"] as String?
    }
}
```

Then change the **release** build type (replace the current debug line):

```kotlin
buildTypes {
    release {
        signingConfig = signingConfigs.getByName("release")   // was: getByName("debug")
        isMinifyEnabled = true        // R8 code shrink
        isShrinkResources = true      // strip unused resources
        proguardFiles(
            getDefaultProguardFile("proguard-android-optimize.txt"),
            "proguard-rules.pro"
        )
    }
}
```

> If R8 strips something the app needs at runtime, add keep-rules to
> `android/app/proguard-rules.pro`. Test the release build on a device before shipping.

---

## 4. Build the App Bundle (.aab)

Play requires an **`.aab`**, not an APK.

```bash
flutter clean
flutter pub get
flutter build appbundle --release \
  --obfuscate --split-debug-info=build/symbols
```

- Output: `build/app/outputs/bundle/release/app-release.aab`
- `--obfuscate --split-debug-info` shrinks the binary and produces symbols in `build/symbols`
  (keep these per version to de-obfuscate crash stack traces).
- Sanity-check size/contents: `flutter build appbundle --analyze-size` (optional).

Verify it's release-signed (not debug):

```bash
# from the extracted universal APK, or check the console's App integrity page after upload
keytool -list -printcert -jarfile build/app/outputs/bundle/release/app-release.aab | head
```

---

## 5. Play Console declarations specific to THIS app

These are the sensitive permissions this app uses — each needs a declaration in the console,
and several are manually reviewed (budget days, not minutes):

| Area                                         | Why this app needs it                                | Console action                                                                                           |
| -------------------------------------------- | ---------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| **Background location**                | Lone-worker GPS tracking during an active shift      | Location permissions declaration**+ usually a demo video** + written justification. The heaviest review. |
| **`USE_EXACT_ALARM`**                | On-time offline welfare/photo check reminders        | Alarms & Reminders policy declaration + justify as safety-critical timing                                |
| **Foreground service (location type)** | The GPS foreground service ("Shift tracking active") | Android 14 requires declaring the FGS**type** + justification                                      |
| **Camera**                             | Photo verification checks                            | Covered by the Data safety form                                                                          |
| **Data safety form**                   | Collects location, photos, device id                 | Declare what's collected, purpose, that it's encrypted in transit, and retention                         |
| **Target API level**                   | Play's minimum target                                | Confirm`targetSdk` is a recent API (Flutter 3.44 defaults are current)                                 |

Also complete: content rating questionnaire, app category, contact details, and the store
listing (title, short/full description, screenshots, feature graphic, app icon).

---

## 6. Upload & roll out (use the testing tracks first)

1. **Internal testing** — instant, up to 100 testers by email. Upload the `.aab` here first and
   verify on **real devices** (notifications, background location, camera all need a device —
   they can't be verified in an emulator or unit tests).
2. **Closed testing** → **Open testing** (optional wider rings).
3. **Production** — submit for review. First review + the background-location review can take
   several days.

Each track: upload the `.aab`, add release notes, then create/roll out the release.

---

## 7. Pre-flight checklist

- [ ] Release keystore created **and backed up**; `key.properties` created and **gitignored**
- [ ] `build.gradle.kts` uses the **release** signing config (not debug) + R8 on
- [ ] `versionCode` bumped higher than the last upload
- [ ] `flutter build appbundle --release --obfuscate --split-debug-info=build/symbols` succeeds
- [ ] Release build **tested on a physical device** (login, shift start, GPS, welfare + photo
  checks, notifications, offline→reconnect sync)
- [ ] Privacy policy URL live
- [ ] Data safety form + background-location declaration + USE_EXACT_ALARM justification submitted
- [ ] Store listing assets uploaded (icon, screenshots, descriptions)
- [ ] Symbols in `build/symbols` archived for this versionCode

---

## Notes / open items (from HANDOFF)

- The **release signing + R8 change (step 3)** is a code change not yet made — it's the one
  thing in this guide that touches the repo. Everything else is keystore + console work.
- iOS App Store is a **separate** process and is currently **blocked on the Apple Developer
  account** (FCM/APNs, Universal Links) — see `docs/IOS_PARITY_TODO.md`.
- The app is still **uncommitted**; commit before/while cutting a release so the shipped build
  maps to a known revision.

---

## Appendix A — Store listing copy (ready to paste)

Draft text for the Main store listing (Grow → Store presence → Main store listing). Edit to taste;
the character limits are Google's. The `pubspec.yaml` `description` is internal metadata only —
**this** is what users see on the Play Store.

**App name** (≤30 chars):

```
IronLock Guard Monitor
```

**Short description** (≤80 chars):

```
Lone-worker safety for security guards: shifts, GPS, welfare & photo checks.
```

**Full description** (≤4000 chars):

```
IronLock Guard Monitor keeps lone security guards safe and accountable throughout every shift.

Designed for professional security teams, the app pairs each guard with their control room:
sign in for a scheduled shift, and IronLock tracks the shift end to end — location, welfare, and
photo verification — with full support for areas that have poor or no signal.

KEY FEATURES

• Shift check-in and check-out — start and end shifts within your scheduled window, with a clear
  prompt if a shift is running over.
• Live location tracking — your position is shared with your control room while a shift is active,
  so help can reach you fast if something goes wrong. Tracking runs only during an active shift.
• Welfare (wakefulness) checks — respond to periodic prompts with a short code to confirm you're
  alert and safe. Missed checks alert your supervisor automatically.
• Photo verification — capture time-stamped, tamper-resistant proof-of-presence photos on request
  or on a schedule.
• Works offline — welfare and photo checks continue with no signal and sync automatically the
  moment you reconnect, so nothing is lost in low-coverage sites.
• Built for the field — clear, high-contrast interface that's fast to use one-handed, day or night.

IronLock is intended for use by security staff whose employer uses the IronLock platform. A valid
account issued by your organisation is required to sign in.

LOCATION DISCLOSURE
This app collects location data to enable live shift tracking and proof-of-presence for lone-worker
safety, and continues to do so in the background while a shift is active — even when the app is
closed or not in use — so your control room can locate you in an emergency. Location tracking stops
when your shift ends.
```

**Release notes** for v1.0.0 (`<en-US>…</en-US>` in the release):

```
Initial release: shift check-in/out, live GPS tracking, welfare checks, photo verification, and
offline sync for low-signal sites.
```

> The **LOCATION DISCLOSURE** paragraph is deliberate — Google's reviewers expect background-
> location use to be explained in the listing as well as via the in-app prompt (Step 7). Keep it.
