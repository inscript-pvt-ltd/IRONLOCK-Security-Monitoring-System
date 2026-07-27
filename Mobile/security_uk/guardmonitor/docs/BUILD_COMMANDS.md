# Build commands — Android APK & iOS

All commands run from the app root: `Mobile/security_uk/guardmonitor/`.

```bash
cd Mobile/security_uk/guardmonitor
```

> **Phase 7 one-time setup (required).** The offline queue is Drift + SQLCipher, and sqlite3 3.x
> selects the cipher engine via a native build hook. Enable native assets **once** before building:
>
> ```bash
> flutter config --enable-native-assets
> ```

---

## 0. Pre-build hygiene (run before any release build)

```bash
flutter pub get                 # sync dependencies
dart run build_runner build --delete-conflicting-outputs   # regen Drift .g.dart (offline queue)
flutter analyze                 # must be zero issues
flutter test                    # must be green
```

---

## 1. Android — APK

```bash
# Release APK (single fat APK — easiest to sideload / share)
flutter build apk --release
#   → build/app/outputs/flutter-apk/app-release.apk

# Smaller, per-ABI split APKs (arm64 is the one most modern phones need)
flutter build apk --release --split-per-abi
#   → build/app/outputs/flutter-apk/app-arm64-v8a-release.apk
#   → build/app/outputs/flutter-apk/app-armeabi-v7a-release.apk
#   → build/app/outputs/flutter-apk/app-x86_64-release.apk

# Debug APK (larger, debuggable — do NOT use to test offline sync; the debug
# VM-Service tether drops when Wi-Fi/data is killed and the app dies)
flutter build apk --debug
```

**Android App Bundle** (for Play Store upload, not sideloading):

```bash
flutter build appbundle --release
#   → build/app/outputs/bundle/release/app-release.aab
```

**Install the APK on a connected device:**

```bash
flutter install --release              # installs to the connected/selected device
# or directly with adb:
adb install -r build/app/outputs/flutter-apk/app-release.apk
```

---

## 2. iOS

> Requires macOS + Xcode. A **device** build (not the simulator) needs a valid signing team.

```bash
# Build the .app for the simulator (no signing needed)
flutter build ios --simulator --debug

# Release build for a physical device — needs code signing configured in Xcode
flutter build ios --release
#   → build/ios/iphoneos/Runner.app

# Produce a shareable .ipa (archive + export). Signing must be set up first.
flutter build ipa --release
#   → build/ios/ipa/*.ipa
#   → open build/ios/archive/Runner.xcarchive in Xcode → Distribute App for App Store / Ad-Hoc
```

**Signing (one-time, in Xcode):** open `ios/Runner.xcworkspace`, select the `Runner` target →
Signing & Capabilities → pick your Team + a valid bundle id (`com.ironlock.guardmonitor`).

**Run on a wireless / untethered device** (to test offline flush without the laptop tether killing
the app — kill Wi-Fi/data on-device, watch the on-screen sync chip drain):

```bash
flutter devices                                   # list attached/wireless devices + their ids
flutter run --release -d <device-id>              # release run survives losing the network
```

---

## 3. Quick reference — simulator / dev run

```bash
flutter run -d F390E17F-385D-420C-89E9-E7CF933ADC99   # iPhone 17 Pro simulator
flutter run                                            # first available device

# Reset iOS simulator permissions after denying camera/location
xcrun simctl privacy F390E17F-385D-420C-89E9-E7CF933ADC99 reset all com.ironlock.guardmonitor
```

---

### Notes

- **Offline sync can't be tested in a debug build** — killing Wi-Fi/data drops the laptop's
  VM-Service tether and the debug app dies. Use a **release** build (`--release`) on-device and
  watch the on-screen sync chip.
- `flutter build apk --release` bundles everything; `--split-per-abi` produces smaller ABI-specific
  files — share `app-arm64-v8a-release.apk` for most modern Android phones.
- iOS `.ipa` distribution needs a signing team + a distribution profile; without an Apple Developer
  account you can only run on a personal device via a free 7-day provisioning profile.
