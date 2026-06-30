# iOS work blocked on an Apple Developer account

**Single checklist of everything we must do once the Apple Developer account exists.**
Until then these are hard-blocked — they need signing entitlements / capabilities that Apple
only issues under a paid Developer account. Detailed steps live in the linked docs; this is the
master list so nothing is forgotten.

Last updated: 2026-06-26.

---

## Prereqs (do once, first)

- [ ] Enrol in the **Apple Developer Program** (paid). Note the **Team ID** (Membership page).
- [ ] In Xcode → Runner target → Signing & Capabilities → sign in with the account and select the
      **Team**. Confirm **Automatically manage signing** produces a valid provisioning profile
      for bundle id `com.ironlock.guardmonitor`.

---

## 1. 🔔 Push notifications (APNs) — unblocks iOS wakefulness + photo on lock screen

**Why it matters:** on iOS, FCM delivers **through APNs**. Right now there's no APNs token
(`apns-token-not-set` in logs), so:
- Background/locked **photo** + **wakefulness** pushes don't arrive.
- A **manual/online wakefulness** check **cannot reach iOS at all** (there is no poll fallback
  for it — it's push-only). Scheduled TOTP wakefulness + foreground photo polling already work
  without push; everything else needs this.

Steps (full detail in [`IOS_PARITY_TODO.md`](IOS_PARITY_TODO.md) §2–§4):
- [ ] Apple Developer → **Keys → +** → enable **Apple Push Notifications service (APNs)** →
      download the **`.p8`** key. Record the **Key ID** + **Team ID**.
- [ ] Firebase console → Project Settings → Cloud Messaging → **iOS app** → upload the `.p8`
      with Key ID + Team ID.
- [ ] Xcode → Runner → Signing & Capabilities → **+ Capability → Push Notifications**. This
      creates `ios/Runner/Runner.entitlements` with **`aps-environment`** and wires
      `CODE_SIGN_ENTITLEMENTS`. ⚠️ Do **not** add `aps-environment` manually before this — it
      breaks code-signing without the matching provisioning profile.
- [ ] Ensure the provisioning profile includes the Push Notifications capability.
- [ ] `flutter clean && flutter pub get && cd ios && pod install`, then run on a **real device**
      (push doesn't work on the simulator).
- [ ] Verify: trigger a `PHOTO_REQUEST` / `WAKEFULNESS_CHALLENGE` with the app **backgrounded** →
      it should arrive on the lock screen, matching Android.

> App side is already done: `remote-notification` background mode, FCM init, token registration
> (`POST /devices/push-token` with `platform: ios`), and `PushMessaging.isDelivering` gating so
> the local scheduler steps aside once push actually delivers.

---

## 2. 🔗 Universal Links for Shift Access (SSO) — unblocks the raw https link opening the app

**Why it matters:** the SSO custom scheme `ironlock://` already works, but the **https** link a
supervisor sends only opens the app directly with Universal Links (otherwise it relies on the
backend's redirect page — see `SHIFT_ACCESS_BACKEND_REQUIREMENTS.md` §1).

Steps (full detail in [`SHIFT_ACCESS_SSO.md`](SHIFT_ACCESS_SSO.md) §later):
- [ ] Xcode → Runner → Signing & Capabilities → **+ Capability → Associated Domains** → add
      `applinks:dashboard.ironlock.co.uk`.
- [ ] Give the backend dev our **Team ID** so they can serve
      `/.well-known/apple-app-site-association` with `appID` = `<TeamID>.com.ironlock.guardmonitor`,
      path `/m/shift-access/*` (JSON, no extension, `application/json`, HTTPS, no redirect).
- [ ] Test: tap the real **https** link on a device → app opens straight to the redeem flow.
- [ ] (Android counterpart — `assetlinks.json` + `autoVerify` — is **not** Apple-gated; do it when
      we cut a signed release build and have the signing SHA-256.)

---

## 3. 📦 Distribution — TestFlight / App Store (when ready to ship to the client)

Not needed for dev testing, but Apple-account-gated:
- [ ] Create the **App ID** + **distribution certificate** + **App Store provisioning profile**.
- [ ] Register the app in **App Store Connect**.
- [ ] Archive a **release** build and upload to **TestFlight** for the client to install without a
      cable (replaces the current `flutter run --release` over USB).
- [ ] Re-confirm **Push capability** + **Associated Domains** are present on the *distribution*
      profile too (not just the development one), or items 1–2 silently break in the shipped build.

---

## Quick map: what each unlock gives us

| Apple step | Unblocks |
|---|---|
| APNs key + Push capability (#1) | iOS background/locked wakefulness + photo pushes; **manual wakefulness on iOS at all** |
| Associated Domains (#2) | The **https** SSO link opening the app directly (no redirect page) |
| Distribution cert/profile (#3) | TestFlight / App Store delivery to the client |

After #1 and #2, the only intentional remaining iOS≠Android gap is that a single **still
screenshot** can be taken on iOS (OS limitation; Android `FLAG_SECURE` blocks it) — see
`ANDROID_VS_IOS.md`.
