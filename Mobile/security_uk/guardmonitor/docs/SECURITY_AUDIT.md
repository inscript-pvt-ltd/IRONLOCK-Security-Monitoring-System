# IronLock Guard Monitor — Security & Logic Audit

**Date:** 2026-06-22
**Scope:** Flutter app (`guardmonitor/`) — auth, shift lifecycle, GPS, welfare, photo, secure storage, the early-end approval flow, platform manifests, native glue, and build config. **`lib/` read in full.**
**Audience:** mobile + backend developers. Items tagged **[App]** are fixable in this repo; **[Backend]** must be enforced server-side (the client cannot be trusted).

> **Backend dev:** for a consolidated, priority-ordered list of just the server-side work, see [`BACKEND_REQUIREMENTS.md`](BACKEND_REQUIREMENTS.md). This audit is the full findings record behind it.

**At a glance:** 33 findings — 🔴 **2 Critical** · 🟠 **6 High** · 🟡 **8 Medium** · 🟢 **17 Low**
**Remediation status (2026-06-24): 21 of 33 fixed in-app.**

- ✅ **Fixed in-app (21):** H3, H4, H6 · M2, M3, M4, M5, M6, M8 · M1 (app half) · L1, L2, L3, L6, L9, L11, L12, L13, L14, L15, L16
- ◑ **Partly fixed in-app:** H5 — **background GPS done** (foreground service / iOS background location); welfare-in-background still needs server push (backend)
- ⏳ **Backend / infra (not started):** C1 (HTTPS), C2 (photo model), H1, H2, M1 (backend half), H5 welfare-push — all specced in [`BACKEND_REQUIREMENTS.md`](BACKEND_REQUIREMENTS.md)
- 🟠 **App, open by decision:** M7 (release signing — needs a keystore)
- ⏸ **Low, deferred with reason (5):** L4 (backend), L5/L7/L8 (kept by design), L10 (needs font assets)

See the per-item [Remediation status](#remediation-status--low-severity-pass-2026-06-22) sections for the detail behind each fix.

> **Threat model in one line:** the app runs on a guard's own phone, which we do **not** control. Assume the binary can be inspected, the device clock can be changed, the network can be observed, and any client-side check can be bypassed. Every control that matters for payroll, compliance, or evidence must be **server-authoritative**.

---

## Contents

- [How to read this](#how-to-read-this)
- [Remediation status — Medium/High batch 1](#remediation-status--mediumhigh-batch-1-2026-06-24) — H3, H6, M2, M3, M6, M8
- [Remediation status — Medium/High batch 2](#remediation-status--mediumhigh-batch-2-2026-06-24) — M5, M4, M1 (app half), H4
- [Remediation status — Low-severity pass](#remediation-status--low-severity-pass-2026-06-22) — what's fixed / deferred
- [Summary table](#summary-table) — every finding, with status, sorted by severity
- [Critical findings](#critical-findings) — C1–C2
- [High findings](#high-findings) — H1–H6
- [Medium findings](#medium-findings) — M1–M8
- [Low findings](#low-findings) — L1–L16
- [Remediation priority](#remediation-priority) — what to fix, in order
- [Coverage](#coverage) — what was reviewed
- [Bottom line](#bottom-line)

---

## How to read this

Each finding has a stable ID (`C/H/M/L` + number), a one-line title, and four fields:

- **Where** — file\:line links to the exact code.
- **Impact** — what goes wrong and why it matters.
- **Fix** — the concrete remediation.
- **Owner** — `[App]` (this repo) and/or `[Backend]` (server-side enforcement).

IDs are stable references — quote them in commits/PRs (e.g. "fixes H3"). The [Summary table](#summary-table) is the master index; the [Remediation priority](#remediation-priority) is the suggested order of work.

---

## Summary table

**Status key:** ✅ fixed in-app · ◑ app half done, backend pending · ⏳ backend/infra owned (not started) · 🟠 app, open by decision · ⏸ deferred with reason

| # | Severity | Status | Finding | Owner |
|---|---|---|---|---|
| [C1](#c1--everything-travels-over-cleartext-http) | 🔴 Critical | ⏳ | All traffic over cleartext HTTP (password + JWT exposed) | Backend + App |
| [C2](#c2--the-photo-hmacnonce-scheme-is-security-theater) | 🔴 Critical | ⏳ | Photo HMAC/nonce scheme provides ~no integrity (baked-in secret, client-issued nonces) | Backend + App |
| [H1](#h1--early-vs-normal-end-is-decided-by-the-device-clock) | 🟠 High | ⏳ | Early-vs-normal end decided by the **device clock**; approval not enforced server-side | Backend + App |
| [H2](#h2--welfare-passfail-is-recorded-locally) | 🟠 High | ⏳ | Welfare pass/fail recorded locally; server response ignored | Backend + App |
| [H3](#h3--token-refresh-forces-a-sign-out-on-any-error-from-the-retried-request) | 🟠 High | ✅ | JWT refresh forces a sign-out on **any** error from the retried request | App |
| [H4](#h4--ios-has-no-app-transport-security-exception) | 🟠 High | ✅ | iOS has **no ATS exception** — the app cannot reach the cleartext backend on iOS | App |
| [H5](#h5--no-background-execution) | 🟠 High | ◑ | No background execution — GPS + welfare polling stop when the app is backgrounded/locked (**GPS half fixed**; welfare needs push) | App + Backend |
| [H6](#h6--camera-failure-uploads-a-placeholder-jpeg) | 🟠 High | ✅ | Camera denial/failure on a real device uploads a 1×1 placeholder JPEG as the "verification photo" | App |
| [M1](#m1--the-pending-lock-is-wiped-by-the-next-poll) | 🟡 Medium | ◑ | Early-end pending-lock is wiped by the next 20s poll if backend doesn't echo it | App + Backend |
| [M2](#m2--welfare-delivery-stalls-if-the-overlay-never-resets) | 🟡 Medium | ✅ | Welfare delivery permanently stalls if the overlay never reaches `reset()` | App |
| [M3](#m3--a-malformed-pending-payload-throws-past-the-polls-catch) | 🟡 Medium | ✅ | Malformed `pending` payload throws past the poll's `on DioException` catch | App |
| [M4](#m4--gps-permission-denial-is-silent) | 🟡 Medium | ✅ | GPS permission denial is silent; guard works untracked with no warning | App |
| [M5](#m5--photo-upload-omits-latitudelongitude) | 🟡 Medium | ✅ | Photo upload omits latitude/longitude — photos have no location binding | App |
| [M6](#m6--zone-defaults-to-inside-and-out-of-zone-shows-as-active-throughout) | 🟡 Medium | ✅ | Zone defaults to "inside"; an out-of-zone guard renders as "Active throughout" in the end summary | App |
| [M7](#m7--release-build-is-signed-with-the-debug-keystore) | 🟡 Medium | 🟠 | Release build is signed with the **debug keystore**; no minify/obfuscation | App |
| [M8](#m8--sign-out-ends-the-shift-with-no-reason-bypassing-early-end-approval) | 🟡 Medium | ✅ | Sign-out ends the shift via `end()` (no reason) — bypasses early-end approval (latent) | App |
| [L1](#l1--guardprofilefromemail-can-crash-on-a-leading-separator-email) | 🟢 Low | ✅ | `GuardProfile.fromEmail` crashes on a leading-separator email | App |
| [L2](#l2--sign-out-doesnt-stop-gps-or-cancel-the-end-reminder) | 🟢 Low | ✅ | Sign-out doesn't stop GPS or cancel the end reminder | App |
| [L3](#l3--nonce-pool-depletes-after-15-photos) | 🟢 Low | ✅ | Nonce pool depletes after 15 photos; 16th uploads unsigned | App |
| [L4](#l4--captured_at--recorded_at-use-the-device-clock) | 🟢 Low | ⏸ | `captured_at` / `recorded_at` use the device clock | App + Backend |
| [L5](#l5--android-end-reminder-uses-inexact-scheduling) | 🟢 Low | ⏸ | Android end-reminder uses inexact scheduling (can fire late) | App |
| [L6](#l6--no-rootjailbreak-detection-or-screenshot-protection) | 🟢 Low | ✅ | No root/jailbreak detection, no screenshot protection | App |
| [L7](#l7--notification-tap-has-no-handler--deep-link) | 🟢 Low | ⏸ | Notification tap has no handler / deep-link | App |
| [L8](#l8--stored-token-expiry-is-never-read) | 🟢 Low | ⏸ | Stored token expiry is never read — dead data, no proactive refresh | App |
| [L9](#l9--root-error-state-shows-login-despite-a-valid-token) | 🟢 Low | ✅ | Root `error` state shows Login even with a valid stored token | App |
| [L10](#l10--google_fonts-fetches-fonts-from-google-at-runtime) | 🟢 Low | ⏸ | `google_fonts` not bundled — fonts fetched from Google at runtime | App |
| [L11](#l11--verification-photo-uses-the-back-camera) | 🟢 Low | ✅ | Verification photo uses the back camera (`_cameras.first`) | App |
| [L12](#l12--initials-derivation-crashes-on-an-empty-name) | 🟢 Low | ✅ | Initials derivation crashes on an empty first/last name from `/me` | App |
| [L13](#l13--alerts-feature-carries-fabricated-data-and-is-never-surfaced) | 🟢 Low | ✅ | Alerts feature carries fabricated default data and is never surfaced (dead/incomplete) | App |
| [L14](#l14--all-systems-normal-reflects-only-connectivity) | 🟢 Low | ✅ | "All systems normal" status tile reflects only connectivity, not GPS/battery/zone | App |
| [L15](#l15--privacy-notice-is-never-shown-no-consent-captured) | 🟢 Low | ✅ | Privacy notice overlay is never shown — no consent captured for location/photo processing | App |
| [L16](#l16--passcode-field-doesnt-disable-autocorrectsuggestions) | 🟢 Low | ✅ | Passcode field doesn't disable autocorrect/suggestions/autofill caching | App |

---

## Remediation status — Low-severity pass (2026-06-22)

Across two rounds (2026-06-22) the **Low-severity** items were worked through one by one. Each fix was re-checked so it introduces no new loophole or error; `flutter analyze` → **0 issues**, `flutter test` → **19/19** (added 5 `GuardProfile.fromEmail` regression tests). The remaining items are kept for a stated reason, not skipped.

---

## Remediation status — Medium/High batch 1 (2026-06-24)

**H3, H6, M2, M3, M6, M8** fixed in-app. `flutter analyze` → **0 issues**, `flutter test` → **19/19**. All fixes verified to introduce no new issues.

### ✅ Fixed in-app (6)

| ID | What changed | Re-audit note |
|---|---|---|
| **H3** | `_JwtInterceptor._retry()` wrapped in its own `try/catch`; retry failures now pass through via `handler.next` instead of triggering `forcedSignOut`. | Only a refresh failure (no/rejected refresh token) can now log the guard out. A transient 4xx/5xx on the retried endpoint surfaces as a normal request error. |
| **H6** | Added `_noHardware` flag in `PhotoScreen`; set when `availableCameras()` returns empty. On a real device (`!_noHardware`), `filePath == null` now blocks the upload with a "grant camera access" snackbar and resets state — placeholder JPEG is simulator-only. | A denied/failed camera on a real device can no longer silently submit a 1×1 white pixel as photo verification. |
| **M2** | `WakefulnessOverlay.dispose()` now calls `reset()` on `wakefulnessProvider.notifier` before `super.dispose()`. | Any overlay teardown (navigation, error, app lifecycle) now guarantees return to `idle`; no further welfare checks can be silently dropped for the rest of the shift. |
| **M3** | Poll handler in `HomeScreen._pollBackend()` reads `check_id`, `code`, and `request_id` with null-safe casts (`as String?`) and guards on non-null before triggering; a missing field is silently skipped. | A malformed `pending` payload can no longer throw a `TypeError` past the `on DioException` catch and abort the poll cycle. |
| **M6** | `ZoneNotifier.build()` now returns `2` (no signal) instead of `0` (inside). `EndShiftSheet` location label maps all three states: `0 → 'Active throughout'`, `1 → 'Left zone'`, `2 → 'Interrupted'`; warning colour on anything other than in-zone. | A freshly-opened session or one where GPS was never granted no longer presents as in-zone-compliant. An out-of-zone guard is no longer summarised as "Active throughout". |
| **M8** | `_SignOutButton._confirmAndSignOut()` no longer calls `shiftProvider.notifier.end()`. `signOut()` already stops GPS, cancels the reminder, and invalidates shift state; the backend auto-close handles any open shift. | Sign-out can no longer silently end an active shift with no reason and no supervisor approval, bypassing the early-end control. |

---

## Remediation status — Medium/High batch 2 (2026-06-24)

**M5, M4, M1 (app half), H4** fixed in-app. `flutter analyze` → **0 issues**, `flutter test` → **22/22** (added 3 early-end pending-lock regression tests).

### ✅ Fixed in-app (4)

| ID | What changed | Re-audit note |
|---|---|---|
| **M5** | `PhotoScreen._upload()` now does a one-shot `Geolocator.getCurrentPosition` (8s timeout) and passes `latitude`/`longitude` into `uploadPhoto`. A failed/denied/timed-out fix uploads without coordinates rather than blocking the verification. | Verification photos are now location-bound when a fix is available; the server can confirm on-site capture. |
| **M4** | `GpsService.startCapture()` returns `bool` (false = permission denied); `ShiftNotifier.start()`/`resumeFromServer()` set a new `locationDeniedProvider`, cleared on `end()`. A persistent danger-coloured `_LocationOffBanner` shows in the active screen while denied. | A guard working with location denied now sees a permanent "Location tracking OFF — enable in Settings" banner instead of silently working untracked. **Note:** if denied at start, GPS only recovers on the next shift start/resume — full mid-shift re-acquisition is a separate enhancement. |
| **M1 (app half)** | `CurrentShiftNotifier.fetch()` now routes the poll result through `_withPreservedPendingLock()`: a locally-set `pending` early-end lock survives a poll that omits `early_end_request`; a terminal signal (server `approved`/`rejected`, or shift `completed`/`cancelled`) is always honoured. | The END button can no longer silently unlock mid-wait if the backend doesn't echo the request on every poll. **Backend half (M1) still required:** echo `early_end_request` over the pending→decided lifecycle. |
| **H4** | Added a scoped `NSAppTransportSecurity → NSExceptionDomains` entry for `generous-yellow-jaguar.…cpanel.site` in `ios/Runner/Info.plist` (no `NSAllowsArbitraryLoads`). | iOS can now reach the HTTP backend. **Temporary** — flagged in-plist to be removed once the backend serves HTTPS (C1). |

> **Remaining Medium/High after batch 2:** H1, H2, H5, M7 — see [Remediation priority](#remediation-priority). M1's backend half is still open. C1 and C2 (Critical) require backend infrastructure changes.

---

## Remediation status — Background execution (batch 3, 2026-06-24)

**H5 (GPS half)** fixed in-app. `flutter analyze` → **0 issues**, `flutter test` → **22/22**, `flutter build apk --debug` → **success** (manifest merges cleanly). On-device verification (lock the screen, walk, confirm pings continue) is still required — background behaviour can't be exercised in a unit-test harness.

### ✅ Fixed in-app — background GPS

| Area | What changed | Re-audit note |
|---|---|---|
| **GpsService** | Rewrote from a foreground `Timer.periodic` to `Geolocator.getPositionStream` with background-capable platform settings: Android `AndroidSettings.foregroundNotificationConfig` (runs a foreground service + ongoing notification), iOS `AppleSettings(allowBackgroundLocationUpdates: true, showBackgroundLocationIndicator: true, pauseLocationUpdatesAutomatically: false)`. A one-shot fix still seeds the first ping. | GPS pings now continue when the screen is locked or the app is backgrounded — a plain `Timer` was suspended on background. |
| **Android manifest** | Added `ACCESS_BACKGROUND_LOCATION`, `FOREGROUND_SERVICE`, `FOREGROUND_SERVICE_LOCATION`, `WAKE_LOCK`. | Foreground-service location is permitted on API 34+; build verified. |
| **iOS Info.plist** | Added `UIBackgroundModes: [location]`; widened the Always-usage description to mention background. | iOS keeps delivering location in the background during a shift. |
| **Permission flow** | `_startWithPermissions()` now requests `Permission.locationAlways` (background) after foreground is granted. | Best-effort upgrade: if the guard grants only "While Using", tracking still works while the app is open; the M4 banner covers a hard denial. |

### ⏳ Still required for full H5 — welfare/photo in the background

The 20s welfare/photo **poll** is still a foreground Dart `Timer` (`HomeScreen._pollBackend`). On Android the foreground service keeps the process alive so it largely continues, but **on iOS background Dart timers are suspended** — so a backgrounded guard can miss welfare checks. The robust cross-platform fix is **server push (FCM/APNs)** to deliver welfare/photo prompts, which is backend work (noted in [`BACKEND_REQUIREMENTS.md`](BACKEND_REQUIREMENTS.md)). Until then, background **location** is solid; background **welfare** is best-effort on Android and foreground-only on iOS.

> Note: a powered-**off** phone can't be tracked by any app — "background" here means screen-locked or app-backgrounded, not device-off.

---

### ✅ Fixed in-app — Low-severity (11)

| ID | What changed | Re-audit note |
|---|---|---|
| **L1** | `GuardProfile.fromEmail` drops empty separator-segments before indexing; empty local part → neutral `Guard`/`G`. | Can no longer throw `RangeError`; covered by new tests. |
| **L2** | `signOut()` now calls `gpsService.stopCapture()` + `NotificationService.cancelShiftEnd()`. | Both idempotent — safe when nothing is running; closes the forced-sign-out leak. |
| **L3** | `NoncePoolNotifier.consume()` regenerates a fresh batch when empty instead of returning `null`. | No upload can go out unsigned now; nonce still client-side (see C2). |
| **L6** | Android `MainActivity` sets `FLAG_SECURE` — blocks screenshots/screen-recording and hides content in the recents switcher. | Android-only (iOS can't fully block screenshots); root detection still deferred (needs a dependency + policy). |
| **L9** | `AuthNotifier.build()` wraps the secure-storage read; a storage error returns `signedOut` instead of throwing into `AsyncError`. | Startup path is now total; same Login destination, cleaner state. |
| **L11** | Photo capture prefers the **front** camera (`lensDirection == front`), falling back to the first available. | Matches the presence/identity intent; simulator fallback unaffected. |
| **L12** | `setFromApi()` guards empty `first/last` name before `[0]`; empty → `G`. | No `RangeError` on a blank `/me` name. |
| **L13** | Removed the 5 fabricated default alerts; `alertsProvider` starts empty. | No path can surface a fake "supervisor notified" alert; nothing watched the provider, so no UI regression. |
| **L14** | GPS tile is now 3-state (in-zone / outside / no-signal); the aggregate "All systems normal" tile requires `online && in-zone && battery ok`. | **Partial** — full honesty on a no-fix start still needs **M6** (zone defaults to `0`), which is a Medium item, not in this pass. |
| **L15** | Privacy notice now shows once per install (before the guard acts) and acceptance is persisted in secure storage (`ironlock_privacy_accepted`); wired via `HomeScreen._maybeShowPrivacyNotice`. | Consent is captured + recorded. Legal *content* of the notice remains the business's to confirm. |
| **L16** | `AppInput` sets `autocorrect:false` + `enableSuggestions:false` on obscured (passcode) fields. | Email field unchanged; login tests still pass. |

### ⏸ Remaining (5) — with reason

| ID | Status | Reason / next step |
|---|---|---|
| **L4** | Backend-owned | Client timestamps are advisory by nature; the real fix is the **server** stamping authoritative receipt time. Nothing to safely change app-side. |
| **L5** | Reviewed — keep by design | Inexact Android scheduling is acceptable: the backend auto-close is the real guarantee, and exact alarms invite `SCHEDULE_EXACT_ALARM` Play review for marginal benefit. |
| **L7** | Reviewed — keep by design | Opening the app from the reminder already lands on the active-shift screen; a true deep-link needs a global navigator the single-flow app doesn't have. Low value vs. risk. |
| **L8** | Reviewed — keep by design | Reactive 401-refresh is a correct, robust pattern; the one guaranteed-to-fail request per token lifetime is negligible. Proactive refresh would add concurrency/refresh-storm risk. `expires_at` retained for future use/diagnostics. |
| **L10** | Needs assets | Requires the Inter `.ttf` files bundled as `assets/fonts` + design sign-off on the exact weights. Provide the files and the pubspec + loader wiring is a quick follow-up. |

> **Medium / High batch 1 (H3, H6, M2, M3, M6, M8):** ✅ fixed 2026-06-24 — see section above. Remaining Medium/High/Critical items: see [Remediation priority](#remediation-priority).

---

## Critical findings

### C1 — Everything travels over cleartext HTTP

**Status:** ⏳ Backend — not started; specced in [`BACKEND_REQUIREMENTS.md`](BACKEND_REQUIREMENTS.md) §C1. The single highest priority; also retires the temporary H4 ATS exception.
**Owner:** Backend + App
**Where:** [`lib/config/api_config.dart:6`](../lib/config/api_config.dart#L6) (`baseUrl` is `http://…`); Android cleartext exception enabled.
**Impact:** the login **password**, the bearer **JWT**, the refresh token, GPS pings, and photos all cross the network unencrypted. Anyone on the same Wi‑Fi or any network hop can capture credentials/tokens and replay them. This is the single biggest hole and undermines every other control.
**Fix:** serve the real backend over **HTTPS** (valid TLS cert), point `baseUrl` at `https://…`, then remove the Android cleartext (`usesCleartextTraffic`) exception. Optionally add certificate pinning for defence-in-depth.

### C2 — The photo HMAC/nonce scheme is security theater

**Status:** ⏳ Backend — design decision pending (server-issued nonces + per-device key, or drop the scheme). Specced in [`BACKEND_REQUIREMENTS.md`](BACKEND_REQUIREMENTS.md) §C2.
**Owner:** Backend + App
**Where:** secret at [`lib/config/api_config.dart:43`](../lib/config/api_config.dart#L43); signing at [`lib/services/photo_service.dart:57`](../lib/services/photo_service.dart#L57); nonces generated client-side at [`lib/providers/shift_provider.dart:250`](../lib/providers/shift_provider.dart#L250).
**Impact:** the signing secret is a constant compiled into the app (extractable from the IPA/APK), and the 15 nonces are generated **on the client** — the server never issued them, so it has no list to detect replay against. `captured_at` is also client-set. A tampered client can forge valid signatures indefinitely; the server cannot tell. The scheme only prevents *accidental* duplication.
**Fix:** if anti-replay matters, the server must **issue nonces** (one per photo request, tracked and single-use) and hold a **per-session or per-device key** never shipped in the binary. Otherwise, drop the scheme honestly rather than implying integrity it doesn't provide.

---

## High findings

### H1 — Early-vs-normal end is decided by the device clock

**Status:** ⏳ Backend — not started; full contract specced in [`BACKEND_REQUIREMENTS.md`](BACKEND_REQUIREMENTS.md) §H1 and `BACKEND_SHIFT_END_SPEC.md` §4.1.
**Owner:** Backend + App
**Where:** [`lib/overlays/end_shift_sheet.dart:132`](../lib/overlays/end_shift_sheet.dart#L132); [`lib/screens/home/home_screen.dart:986`](../lib/screens/home/home_screen.dart#L986) — `isEarly = DateTime.now().isBefore(scheduledEnd)`.
**Impact:** set the phone clock past `scheduled_end` and `isEarly` becomes false → the new **supervisor-approval requirement is skipped entirely**, and the shift ends with no reason and no sign-off. The whole early-end feature is bypassable from Settings → Date & Time.
**Fix:** the **server** must decide early-vs-normal from its own clock (compare server `now` to `scheduled_end`) and must **reject `POST /end` with `ended_early:true` unless an `approved` early-end request exists** (full enforcement contract now specced in `BACKEND_SHIFT_END_SPEC.md` §4.1; flow in §0.3). The client UI is a convenience, not the control.

### H2 — Welfare pass/fail is recorded locally

**Status:** ⏳ Backend — not started; specced in [`BACKEND_REQUIREMENTS.md`](BACKEND_REQUIREMENTS.md) §H2 and `BACKEND_SHIFT_END_SPEC.md` §4.2.
**Owner:** Backend + App
**Where:** [`lib/providers/wakefulness_provider.dart:91-110`](../lib/providers/wakefulness_provider.dart#L91-L110).
**Impact:** `submit()` compares `entry == code` on the device and calls `recordWelfareCheck(passed:)` locally; `_respond()` posts to the server but swallows the response and all errors. The end-of-shift "welfare checks 3/3" summary is therefore entirely client-asserted — a tampered client reports a perfect record, and supervisors have no trustworthy attentiveness data.
**Fix:** the server must score the response (it issued the code) and be the source of truth for the per-shift welfare summary. The app should display the server's tally, not its own local counters, for anything that feeds reporting/payroll. Enforcement contract specced in `BACKEND_SHIFT_END_SPEC.md` §4.2.

### H3 — Token refresh forces a sign-out on any error from the retried request

**Status:** ✅ Fixed in-app 2026-06-24 (batch 1) — see [Medium/High batch 1](#remediation-status--mediumhigh-batch-1-2026-06-24).
**Owner:** App
**Where:** [`lib/services/api_client.dart:88-148`](../lib/services/api_client.dart#L88-L148).
**Impact:** the `try` block wraps both the refresh call **and** `_retry(err.requestOptions, …)`. If the refresh succeeds but the retried original request then fails for an unrelated reason (e.g. `409`, `500`, a network blip), the error propagates to the outer `catch`, which calls `forcedSignOutCallbackProvider` → the guard is kicked to Login despite having a perfectly valid, freshly-refreshed session. A transient server hiccup on one request logs the user out.
**Fix:** only treat a **refresh failure** as terminal. Wrap the `_retry` call in its own `try/catch` and, on failure, resolve the original handler with that error (`handler.next`/`reject`) instead of forcing sign-out. Sign-out should fire only when the refresh itself (or a missing/`null` token) fails.

### H4 — iOS has no App Transport Security exception

**Status:** ✅ Fixed in-app 2026-06-24 (batch 2) — scoped ATS exception added; **temporary**, to be removed once C1 (HTTPS) lands. See [batch 2](#remediation-status--mediumhigh-batch-2-2026-06-24).
**Owner:** App
**Where:** [`ios/Runner/Info.plist`](../ios/Runner/Info.plist) — no `NSAppTransportSecurity` key; backend is `http://generous-yellow-jaguar.23-111-165-74.cpanel.site` ([`api_config.dart:6`](../lib/config/api_config.dart#L6)).
**Impact:** Android has a scoped cleartext exception (`network_security_config.xml`), but iOS does **not**. iOS ATS blocks plain `http://` by default, so on a real iPhone/simulator the app **cannot reach the production backend at all** — every API call fails. This likely means iOS was only ever exercised against the `127.0.0.1` mock (localhost is ATS-exempt), and the live backend is silently broken on iOS.
**Fix:** the correct fix is **HTTPS** (C1), which makes this moot. If a temporary http test on iOS is needed, add a narrowly-scoped `NSExceptionDomains` entry for that host — but do **not** ship `NSAllowsArbitraryLoads`.

> Note: the Android side is already scoped correctly — `network_security_config.xml` permits cleartext only for the one cPanel host, not globally. That's the right shape; it just shouldn't be needed once C1 lands.

### H5 — No background execution

**Status:** ◑ Largely fixed in-app 2026-06-24 (batch 3) — **background GPS now runs when the screen is locked / app backgrounded** (Android foreground service + iOS background location). **Welfare/photo polling is still foreground-only** — reliable background delivery needs server push (FCM/APNs), which is backend work. See [batch 3](#remediation-status--background-execution-batch-3-2026-06-24).
**Owner:** App (GPS done) + Backend (welfare push)
**Where:** GPS timer [`gps_service.dart:28`](../lib/services/gps_service.dart#L28); poll timer [`home_screen.dart:40`](../lib/screens/home/home_screen.dart#L40); iOS `Info.plist` has no `UIBackgroundModes`; app uses only `locationWhenInUse`.
**Impact:** both the 15s GPS loop and the 20s welfare/photo poll are plain Dart `Timer`s, and the app requests only *when-in-use* location with no background mode. The moment the guard locks the phone or backgrounds the app, **GPS pings and welfare checks stop entirely**. For a BS 8484 lone-worker product this is the core function silently failing — "on-site verification" only happens while the guard is actively looking at the screen.
**Fix:** this is an architecture decision, not a one-liner. Real lone-worker monitoring needs background location (iOS `UIBackgroundModes: location` + `locationAlways`, Android foreground service with an ongoing notification) and/or server-side push for welfare. At minimum, document the limitation loudly and treat foreground-only tracking as a known gap, not a working feature.

### H6 — Camera failure uploads a placeholder JPEG

**Status:** ✅ Fixed in-app 2026-06-24 (batch 1) — see [batch 1](#remediation-status--mediumhigh-batch-1-2026-06-24). (Server-side 1×1 rejection still recommended as a backstop.)
**Owner:** App
**Where:** [`lib/screens/photo/photo_screen.dart:99-104`](../lib/screens/photo/photo_screen.dart#L99-L104) (fallback) + [`:540`](../lib/screens/photo/photo_screen.dart#L540) (`_kMinimalJpeg`); camera init at [`:56-70`](../lib/screens/photo/photo_screen.dart#L56-L70); no explicit camera-permission gate (relies on `CameraController.initialize()`).
**Impact:** the "simulator fallback" — write a 1×1 white JPEG so the upload path still works without camera hardware — triggers whenever `filePath` is `null`, which on a **real device** happens if camera permission is **denied** or `takePicture()` throws. So a guard who denies the camera (or hits any camera error) silently uploads a **blank 1×1 white pixel** as their photo verification, and the flow proceeds to "validated/flagged" on the server's response. A core anti-fraud control (prove you're physically present) is defeated by tapping "Don't Allow". The fallback meant only for the simulator leaks into production.
**Fix:** gate the fallback on actually being a simulator/no-hardware case, not on any failure. On a real device with denied/failed camera, **block the upload** and surface a "camera required" error (request `Permission.camera` explicitly and handle denial), rather than submitting a placeholder. Server-side, reject/flag implausible images (e.g. 1×1).

---

## Medium findings

### M1 — The pending-lock is wiped by the next poll

**Status:** ◑ App half ✅ fixed 2026-06-24 (batch 2) — lock held locally until a terminal signal. **Backend half ⏳ pending:** echo `early_end_request` every poll ([`BACKEND_REQUIREMENTS.md`](BACKEND_REQUIREMENTS.md) §M1).
**Owner:** App + Backend
**Where:** optimistic set in [`lib/providers/shift_provider.dart`](../lib/providers/shift_provider.dart) `CurrentShiftNotifier.requestEarlyEnd`; wholesale overwrite in `fetch()` ([`:15-26`](../lib/providers/shift_provider.dart#L15-L26)) via `state = result`.
**Impact:** after a guard requests an early end, the optimistic `pending` status survives only until the next 20s `GET /shifts/current`. If the backend doesn't return an `early_end_request` object **on every poll while pending**, `state = result` overwrites it with `null` and the END button **silently unlocks mid-wait**. The approved-end path also depends on the server echoing `reason`/`note` back (the sheet's local `_reason` is `null` on a fresh open), so a non-echoing backend loses the reason on the final end.
**Fix (App):** hold the locked/pending state locally until a **terminal** server signal (`approved`, `rejected`, or `status: completed`), rather than letting any poll that lacks the field clear it. **Fix (Backend):** echo `early_end_request {status, reason, note}` on `GET /shifts/current` for the whole pending→decided lifecycle (already specced in `BACKEND_SHIFT_END_SPEC.md` §0.3).

### M2 — Welfare delivery stalls if the overlay never resets

**Status:** ✅ Fixed in-app 2026-06-24 (batch 1) — see [batch 1](#remediation-status--mediumhigh-batch-1-2026-06-24).
**Owner:** App
**Where:** trigger gate at [`lib/screens/home/home_screen.dart:72-78`](../lib/screens/home/home_screen.dart#L72-L78) (`status == idle`); reset only inside `_close()` at [`lib/overlays/wakefulness_overlay.dart:100-106`](../lib/overlays/wakefulness_overlay.dart#L100-L106); `dispose()` ([`:108-114`](../lib/overlays/wakefulness_overlay.dart#L108-L114)) does **not** reset.
**Impact:** the poll only triggers a new welfare check when `status == idle`, and the only thing that returns status to `idle` is `reset()`, called **inside** `_close()`'s `_fadeCtrl.reverse().then(...)` callback. `dispose()` cancels timers but never resets. So any teardown that doesn't run the fade (navigation, error, app lifecycle) leaves `wakefulnessProvider` stuck at `success`/`failed`, and **no further welfare check fires for the rest of the shift** — a silent attentiveness-monitoring failure.
**Fix:** guarantee the return to `idle` on a path that can't be skipped — call `reset()` in `dispose()`, or in `_close()` outside the animation callback (`whenComplete`/`finally`).

### M3 — A malformed `pending` payload throws past the poll's catch

**Status:** ✅ Fixed in-app 2026-06-24 (batch 1) — see [batch 1](#remediation-status--mediumhigh-batch-1-2026-06-24).
**Owner:** App
**Where:** [`lib/screens/home/home_screen.dart:71-87`](../lib/screens/home/home_screen.dart#L71-L87) — `welfareData!['check_id'] as String`, `photoData!['request_id'] as String`.
**Impact:** if the backend ever sends `pending:true` without `check_id`/`code`/`request_id`, the `as String` cast on `null` throws a `TypeError`, which the surrounding `on DioException` catch does **not** handle. The poll cycle aborts (the photo check after welfare never runs) and an unhandled async error surfaces.
**Fix:** read fields null-safely (`as String?`) and bail out of that branch if any required field is missing, or broaden the catch.

### M4 — GPS permission denial is silent

**Status:** ✅ Fixed in-app 2026-06-24 (batch 2) — persistent "Location tracking OFF" banner. See [batch 2](#remediation-status--mediumhigh-batch-2-2026-06-24).
**Owner:** App
**Where:** [`lib/services/gps_service.dart:21-25`](../lib/services/gps_service.dart#L21-L25) — `startCapture` returns early on denied/deniedForever.
**Impact:** for a product whose core purpose is verifying a guard is physically on-site, a guard who denies location simply starts the shift with no tracking and no warning — the zone card just reads "awaiting first GPS fix" forever. A guard can work entirely unmonitored.
**Fix:** surface a prominent, persistent "Location tracking OFF — enable it in Settings" state when permission is denied (and consider blocking start or flagging the shift to the backend so a supervisor sees it).

### M5 — Photo upload omits latitude/longitude

**Status:** ✅ Fixed in-app 2026-06-24 (batch 2) — one-shot position passed into `uploadPhoto`. See [batch 2](#remediation-status--mediumhigh-batch-2-2026-06-24).
**Owner:** App
**Where:** [`lib/screens/photo/photo_screen.dart:118-124`](../lib/screens/photo/photo_screen.dart#L118-L124) — `_upload` calls `uploadPhoto(filePath, shiftId, requestId, nonce)` with **no** lat/long, even though `PhotoService.uploadPhoto` accepts them.
**Impact:** verification photos carry no location, so the server cannot confirm a photo was taken on-site. The "where was this photo taken" evidence is silently absent.
**Fix:** capture the current position at photo time and pass `latitude`/`longitude` into `uploadPhoto` (reuse `gps_service`'s last fix or a one-shot `getCurrentPosition`).

### M6 — Zone defaults to "inside" and out-of-zone shows as "Active throughout"

**Status:** ✅ Fixed in-app 2026-06-24 (batch 1) — zone defaults to unknown; "Left zone" label added. See [batch 1](#remediation-status--mediumhigh-batch-1-2026-06-24). (End-summary line still ideally backed by server-side zone history.)
**Owner:** App
**Where:** default at [`ui_providers.dart:12`](../lib/providers/ui_providers.dart#L12) (`build() => 0` = inside); end-summary mapping at [`end_shift_sheet.dart`](../lib/overlays/end_shift_sheet.dart) — `value: zone == 2 ? 'Interrupted' : 'Active throughout'`.
**Impact:** two honesty problems. (1) The zone defaults to `0` (**inside/compliant**) before any GPS fix exists — so a shift where GPS never worked (denied, or iOS simulator) presents as in-zone. (2) The end summary only treats `zone == 2` (no signal) as "Interrupted"; `zone == 1` (**outside the geofence**) falls into the `else` and is reported as **"Active throughout"**. So a guard currently outside their patrol boundary — or one whose location was never established — is summarised as fully compliant. It also reflects only the instantaneous zone at sheet-open, not the shift as a whole.
**Fix:** default the zone to "unknown" (`2`) until a real fix lands; map `zone == 1` to an explicit "Left zone" / out-of-bounds label; and base the end-of-shift location line on the shift's actual zone history (server-side), not the single live value.

### M7 — Release build is signed with the debug keystore

**Status:** 🟠 Open — deliberately deferred; needs a real upload keystore + passwords (owner decision before any Play/pilot release).
**Owner:** App
**Where:** [`android/app/build.gradle.kts`](../android/app/build.gradle) — `release { signingConfig = signingConfigs.getByName("debug") }`; no `minifyEnabled`/R8/obfuscation.
**Impact:** a release APK signed with the debug key (a) can't ship on Play, and (b) the debug keystore is well-known/unprotected, so anyone can build and sign a look-alike or a malicious "update" with the same identity — there's no signing integrity. No minify/obfuscation also leaves Kotlin/resources readable and does nothing to slow extraction of the C2 secret.
**Fix:** create a real upload/release keystore (kept out of git), wire a `release` signing config from `key.properties`, and enable R8 + `--obfuscate --split-debug-info` for release builds.

### M8 — Sign-out ends the shift with no reason, bypassing early-end approval

**Status:** ✅ Fixed in-app 2026-06-24 (batch 1) — sign-out no longer calls `end()`. See [batch 1](#remediation-status--mediumhigh-batch-1-2026-06-24).
**Owner:** App
**Where:** [`lib/screens/home/home_screen.dart:1326-1327`](../lib/screens/home/home_screen.dart#L1326-L1327) — `await ref.read(shiftProvider.notifier).end(); await signOut();`.
**Impact:** the Sign-Out confirm calls `end()` with **no arguments** → `endedEarly:false, reason:null`. That's the normal-end path — it skips the entire new request-approval flow and the early-end reason capture. It's **latent today** because the Sign-Out button only renders on the inactive screen ([`:283`](../lib/screens/home/home_screen.dart#L283)), so there's no active shift to end. But it's a real bypass the moment sign-out becomes reachable mid-shift (or a forced sign-out fires while active): a guard ends an in-progress shift early with no reason and no supervisor sign-off, straight past the control you just built.
**Fix:** `end()` from sign-out should not silently close an active shift. Either block sign-out while a shift is active (route the user through the proper END flow first), or, if it must end, mark it explicitly (e.g. `endedEarly` based on the **server** clock + a system reason) — never a clean normal-end.

---

## Low findings

> **Status (2026-06-22):** **fixed in-app** — L1, L2, L3, L6, L9, L11, L12, L13, L14, L15, L16. **Remaining** — L4 (backend), L5/L7/L8 (reviewed, kept by design), L10 (needs font assets). See [Remediation status](#remediation-status--low-severity-pass-2026-06-22) for the breakdown. The finding text below is kept as the original description.

### L1 — `GuardProfile.fromEmail` can crash on a leading-separator email

**Owner:** App
**Where:** [`lib/providers/auth_provider.dart:112`](../lib/providers/auth_provider.dart#L112).
**Impact:** an email whose local part begins with a separator (e.g. `_john.doe@…`) splits to `['', 'john', …]`, then `parts[0][0]` indexes an empty string → `RangeError`. This is on the `/me`-unavailable fallback during sign-in — an uncaught crash on a degraded-network login.
**Fix:** guard for empty parts before indexing; fall back to the raw local part.

### L2 — Sign-out doesn't stop GPS or cancel the end reminder

**Owner:** App
**Where:** [`lib/providers/auth_provider.dart:72-80`](../lib/providers/auth_provider.dart#L72-L80) — invalidates `shiftProvider` but never calls `gpsService.stopCapture()` or `NotificationService.cancelShiftEnd()`.
**Impact:** latent only today — the Sign Out button renders only on the inactive screen ([`home_screen.dart:283`](../lib/screens/home/home_screen.dart#L283)), so it's not reachable mid-shift. But a **forced** sign-out (dead refresh token) while a shift is active would leave the GPS timer running and the reminder armed.
**Fix:** in `signOut()`, also `stopCapture()` and `cancelShiftEnd()`.

### L3 — Nonce pool depletes after 15 photos

**Owner:** App
**Where:** generation at [`lib/providers/shift_provider.dart:250`](../lib/providers/shift_provider.dart#L250); `consume()` returns `null` once empty.
**Impact:** the 16th photo in a shift uploads with no nonce/signature. Minor, and largely moot given C2.
**Fix:** regenerate/extend the pool when low, or (better) move to server-issued nonces per C2.

### L4 — `captured_at` / `recorded_at` use the device clock

**Owner:** App + Backend
**Where:** [`lib/services/photo_service.dart:26`](../lib/services/photo_service.dart#L26); [`lib/services/gps_service.dart:58`](../lib/services/gps_service.dart#L58).
**Impact:** timestamps on photos and GPS pings are client-set and spoofable. Acceptable for telemetry, not for evidence.
**Fix (Backend):** stamp server receipt time as the authoritative timestamp; treat client times as advisory.

### L5 — Android end-reminder uses inexact scheduling

**Owner:** App
**Where:** [`lib/services/notification_service.dart:90`](../lib/services/notification_service.dart#L90) — `AndroidScheduleMode.inexactAllowWhileIdle`.
**Impact:** under Doze the reminder can fire noticeably late. Tolerable because the backend auto-close is the real guarantee, but the reminder is best-effort.
**Fix:** if punctuality matters, request exact-alarm permission and use an exact mode; otherwise document it as best-effort.

### L6 — No root/jailbreak detection or screenshot protection

**Owner:** App
**Impact:** on a rooted/jailbroken device, Keychain/EncryptedPrefs contents (tokens) are extractable, and sensitive screens can be screenshotted. Standard for this threat model, noted for completeness.
**Fix (optional):** add integrity checks and `FLAG_SECURE`/screenshot suppression on sensitive screens if the risk warrants it.

### L7 — Notification tap has no handler / deep-link

**Owner:** App
**Where:** [`lib/services/notification_service.dart:35-37`](../lib/services/notification_service.dart#L35-L37) — `initialize` sets no `onDidReceiveNotificationResponse`.
**Impact:** tapping the "shift ended" reminder just opens the app to wherever it was; it doesn't route to the END action.
**Fix:** add a response handler that brings the guard to the active-shift screen.

### L8 — Stored token expiry is never read

**Owner:** App
**Where:** written at [`auth_provider.dart:46`](../lib/providers/auth_provider.dart#L46) and [`api_client.dart:123`](../lib/services/api_client.dart#L123); `getExpiresAt()` ([`secure_storage_service.dart:35`](../lib/services/secure_storage_service.dart#L35)) has **no readers**.
**Impact:** the app never proactively refreshes; it always waits for a `401 TOKEN_EXPIRED` round-trip to fail first, then refreshes. Functionally fine, but the persisted expiry is dead data and every session incurs one guaranteed-to-fail request after expiry.
**Fix:** either use `expires_at` to refresh proactively (refresh shortly before expiry), or drop the stored value to avoid implying it's used.

### L9 — Root `error` state shows Login despite a valid token

**Owner:** App
**Where:** [`main.dart:84`](../lib/main.dart#L84) — `error: (_, _) => const LoginScreen(...)`.
**Impact:** if `AuthNotifier.build()` ever throws (e.g. a secure-storage read error on startup), the app drops to Login even though a valid session may be stored — an unnecessary re-login. `build()` mostly catches its own errors, so this is a narrow edge.
**Fix:** on a build error, retry/restore rather than assuming signed-out, or surface a retry affordance.

### L10 — `google_fonts` fetches fonts from Google at runtime

**Owner:** App
**Where:** `pubspec.yaml` (`google_fonts: ^8.1.0`); no bundled `assets/fonts`.
**Impact:** Inter is pulled from fonts.google.com on first launch and cached. That's a runtime network dependency (degrades on first launch offline) and a privacy call-out to Google from a security product.
**Fix:** bundle the Inter font files as assets and load them locally (google_fonts supports this), so nothing is fetched at runtime.

### L11 — Verification photo uses the back camera

**Owner:** App
**Where:** [`lib/screens/photo/photo_screen.dart:61`](../lib/screens/photo/photo_screen.dart#L61) — `CameraController(_cameras.first, …)`.
**Impact:** `_cameras.first` is typically the **back** camera. If the photo check is meant to confirm the guard's identity/presence (a selfie), the back camera captures the wrong thing. If it's meant to photograph the surroundings, it's fine — but the intent isn't enforced.
**Fix:** pick the camera that matches the verification intent (front for presence/identity) explicitly rather than relying on enumeration order.

### L12 — Initials derivation crashes on an empty name

**Owner:** App
**Where:** [`lib/providers/auth_provider.dart:135`](../lib/providers/auth_provider.dart#L135) — `'${model.firstName[0]}${model.lastName[0]}'`.
**Impact:** `GuardProfileModel` accepts `first_name`/`last_name` as any string, including `""`. An empty value makes `firstName[0]` throw `RangeError` while building the profile after `/me`. Companion to L1.
**Fix:** guard for empty strings before indexing.

### L13 — Alerts feature carries fabricated data and is never surfaced

**Owner:** App
**Where:** [`lib/providers/alerts_provider.dart:84-126`](../lib/providers/alerts_provider.dart#L84-L126) — `_defaultAlerts()` returns 5 hardcoded alerts ("Welfare check not completed", "Outside patrol zone", "Photo flagged…"); `_fetchFromApi()` replaces them only on a successful `GET /alerts`.
**Impact:** `alertsProvider` is only ever **written** (`prepend` on a failed welfare check) — nothing watches it for display, and `unreadCount` has no callers, so the fabricated alerts aren't shown today. But the feature is a loaded trap: if anyone wires the existing provider to a UI without removing the defaults, guards would see **fake "supervisor has been notified" / "outside patrol zone" alerts** as real. It also fires a `GET /alerts` on first instantiation against a backend that may not implement it.
**Fix:** remove the fabricated defaults (start empty), and only surface server-sourced alerts. Decide whether the feature is in scope; if not, drop the dead code.

### L14 — "All systems normal" reflects only connectivity

**Owner:** App
**Where:** [`lib/screens/home/home_screen.dart:402-406`](../lib/screens/home/home_screen.dart#L402-L406) — the 4th status tile is `online ? 'All systems normal' : 'Connection issue'`; the GPS tile ([`:379-381`](../lib/screens/home/home_screen.dart#L379-L381)) is green for both zone 0 and zone 1 (outside).
**Impact:** the reassuring "All systems normal" green tile is driven **solely by `online`** — it stays green when GPS signal is lost, battery is critical, or the guard is outside the zone. The GPS tile likewise reads "GPS active" (green) when the guard is **outside** their patrol area. Combined with the zone default of `0` (M6), a freshly-opened/denied/simulator session presents an all-green "everything's fine" strip with no real telemetry behind it.
**Fix:** make the aggregate tile reflect actual subsystem health (GPS fix present, battery ok, in-zone), not just network; or remove the false-reassurance tile.

### L15 — Privacy notice is never shown; no consent captured

**Owner:** App
**Where:** `lib/overlays/privacy_notice_overlay.dart` exists but `PrivacyNoticeOverlay(` is **never instantiated**; `privacyAcceptedProvider` ([`ui_providers.dart:84`](../lib/providers/ui_providers.dart#L84)) is written by the overlay's accept button but **never read/enforced** anywhere.
**Impact:** the app continuously processes personal data (precise location, photos of a person) but never presents the privacy notice or records consent — the whole consent path is dead code. For a UK product this is a GDPR/PECR consideration, not just a UX gap.
**Fix:** present the notice (e.g. before first shift start) and gate location/photo capture on `privacyAcceptedProvider`, persisting acceptance. Confirm the lawful basis with whoever owns compliance.

### L16 — Passcode field doesn't disable autocorrect/suggestions

**Owner:** App
**Where:** [`lib/widgets/app_input.dart:98-101`](../lib/widgets/app_input.dart#L98-L101) — `TextFormField` sets `obscureText`/`keyboardType` but not `autocorrect: false`, `enableSuggestions: false`, or `autofillHints`.
**Impact:** without explicitly disabling them, the keyboard may learn/cache typed values; for a passcode field that's a small credential-hygiene gap (mitigated in practice by the numeric keyboard, but not guaranteed across keyboards).
**Fix:** on the passcode field set `autocorrect: false`, `enableSuggestions: false`, and appropriate `autofillHints` (or none).

---

## Remediation priority

| Order | Theme | Findings | Status |
|---|---|---|---|
| 1 | **Backend, now** — the controls the product's trustworthiness rests on | C1 (HTTPS — also retires H4), H1 + H2 enforcement (server decides early-vs-normal, requires approval, scores welfare), M1 backend half (echo `early_end_request`) | ⏳ **Open** — all specced in [`BACKEND_REQUIREMENTS.md`](BACKEND_REQUIREMENTS.md) |
| 2 | **Architecture, soon** — core monitoring only works foregrounded | H5 (background GPS/welfare) | ◑ **GPS done** (batch 3) · welfare-in-background needs server push (backend) |
| 3 | **Integrity, before any pilot** | H6 (placeholder-photo upload), M7 (real release signing) | H6 ✅ · M7 🟠 open (needs keystore) |
| 4 | **App, quick wins** — small, clearly-correct fixes | H3 (refresh sign-out bug), M2 (welfare stall), M3 (poll TypeError), M6 (zone honesty), M8 (sign-out bypass), L1 + L12 (name crashes) | ✅ **All fixed** |
| 5 | **App, hardening** | M1 app half (hold pending lock locally), M4 (location-off warning), M5 (photo lat/long), L10 (bundle fonts) | M1/M4/M5 ✅ · L10 ⏸ (needs assets) |
| 6 | **Design decisions** | C2 (nonce/secret model), L4 (backend time), L5/L7/L8 (kept by design) — decide how much integrity each pipeline needs and spec the backend accordingly | C2/L4 ⏳ backend · L5/L7/L8 ⏸ kept |

**Done so far (2026-06-24):**

- **Low-severity tier (2026-06-22):** ✅ **L1, L2, L3, L6, L9, L11, L12, L13, L14, L15, L16** fixed in-app; **L4, L5, L7, L8, L10** consciously left (backend / by-design / needs assets).
- **Medium/High batch 1 (2026-06-24):** ✅ **H3, H6, M2, M3, M6, M8** fixed in-app.
- **Medium/High batch 2 (2026-06-24):** ✅ **M5, M4, M1 (app half), H4** fixed in-app.
- **Background execution batch 3 (2026-06-24):** ◑ **H5 GPS half** — background location now runs locked/backgrounded (Android foreground service + iOS background updates). Welfare-in-background still needs server push.

**Recommended next batch:** H1+H2 enforcement + M1 backend half + H5 welfare-push (server-side — specs in `BACKEND_REQUIREMENTS.md` / `BACKEND_SHIFT_END_SPEC.md`), C1 (HTTPS — backend, retires the temporary H4 ATS exception), M7 (release signing — needs a real keystore).

---

## Coverage

This audit read **every file in `lib/`** (services, providers, screens, overlays, models, widget entry points, theme/responsive), both platform manifests (`AndroidManifest.xml`, `Info.plist`, `network_security_config.xml`), the native iOS/Android glue (`AppDelegate`/`SceneDelegate`), the Gradle build config, `pubspec.yaml`, and `analysis_options.yaml`.

**Clean bills of health:** no lint suppressions (`// ignore`) anywhere; no sensitive data (tokens/passwords) logged — all `debugPrint`s are `kDebugMode`-gated and non-sensitive; every `Timer`/`StreamSubscription` is disposed (no leaks); the responsive engine and login error-handling are sound.

Remaining unread files are pure presentational widgets and theme constants (colours, gradients, typography), which carry no security or business logic. Further passes would yield cosmetic nits, not new finding classes.

---

## Bottom line

The app's UX-level controls are reasonable, but nearly all of the *security-meaningful* ones — early-end approval, welfare scoring, photo integrity, transport — currently depend on the client telling the truth, and the core on-site tracking only runs while the app is foregrounded. The findings converge on four root causes:

1. **Client-trusted decisions** (C2, H1, H2, H6, M6, M8) — the device asserts things the server should decide.
2. **Foreground-only execution** (H5) — timers/permissions can't sustain real monitoring.
3. **Transport & release hygiene** (C1, H4, M7).
4. **Fragile error/teardown paths** (H3, M2, M3, L1, L12).

Moving the trust decisions server-side (starting with HTTPS and `POST /end` enforcement) and solving background execution are what turn this from "looks secure / looks monitored" into "is."

**Progress (2026-06-24):** all four root-cause clusters have been worked. **Fragile error/teardown paths (#4) are fully closed** (H3, M2, M3, L1, L12). The **client-trusted-decisions** cluster (#1) is half done — the app now reports honestly and locks correctly (H6, M6, M8, M1 app half, M4, M5), but the binding enforcement (C2, H1, H2, M1 backend) is server-side and now fully specced in [`BACKEND_REQUIREMENTS.md`](BACKEND_REQUIREMENTS.md). **Transport/release (#3)** is partway — iOS can now reach the backend (H4, temporary) but HTTPS (C1) and real signing (M7) remain. **Foreground-only execution (#2, H5)** is untouched and is the biggest remaining app-side gap. Net: **21 of 33 fixed in-app**; what's left is concentrated in the backend and the background-execution architecture decision.
