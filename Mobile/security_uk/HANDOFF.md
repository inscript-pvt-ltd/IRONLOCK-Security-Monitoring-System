# Handoff

Running handoff log for the IronLock Guard Monitor work. **Most recent session at the top.**
Each entry: what changed, current state, what's verified, and what's still open.

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
