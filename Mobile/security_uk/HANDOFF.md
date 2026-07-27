# Handoff

Running handoff log for the IronLock Guard Monitor work. **Most recent session at the top.**
Each entry: what changed, current state, what's verified, and what's still open.

---

## 2026-07-27 (cont. 2) — Wakefulness "3-digit code" — REAL CAUSE FOUND + FIXED: display clipping (prior "stale build" call was WRONG)

A device **confirmed updated** still showed a 3-digit code → disproves the stale-build conclusion below.
Re-read the overlay render path and found the actual bug — **it's cosmetic, in `_CodeDisplay`
(`wakefulness_overlay.dart`), not the logic.**

- The code was drawn as `code.split('').join('   ')` (triple-spaced) at `fontSize sp(40)` **+**
  `letterSpacing: 8` — a very wide string — in a `Container` with **no width cap**, `maxLines: 1`,
  **`overflow: TextOverflow.visible`**. That never shrinks/wraps: when wider than the screen it paints
  past both edges (center-aligned) and the parent **clips** it. On a **narrow device** the outer digit
  (usually the padded leading `0`) is cut off → `0472` looks like `472`, and the 4-cell pad can't complete.
- Fits every qualifier: "some devices" = narrow screens · overlay shows 3 · online AND offline (same
  widget) · **persists after update** (widget still in current code).
- **Fix:** wrapped the code `Text` in `FittedBox(fit: BoxFit.scaleDown)` + `Container width: double.infinity`
  → 4 digits always fit (scaled down if tight), can never be clipped. `flutter analyze` clean; TOTP
  vectors still pass.
- The TOTP-parity work (below) stands — it proved the logic was never the problem, which is what
  pointed at rendering. Keep Jerry's backend length-mismatch WARNING log as a monitor.

**State:** `flutter analyze` clean · TOTP vector suite passes. Investigation doc updated (real cause on
top; stale-build section marked superseded). **Ask the user to confirm on an affected narrow device.** Not committed.

---

## 2026-07-27 (cont.) — Wakefulness "3-digit code" — RESOLVED: stale app build, current code proven correct  ⛔️ SUPERSEDED (see entry above)

Field report: some devices show a **3-digit** wakefulness code (offline AND online, in the overlay).
Investigated (`docs/WAKEFULNESS_3DIGIT_CODE_OFFLINE_2026-07-27.md`); **not a current-code bug.**

- **Our offline TOTP is byte-for-byte identical to the backend** — ran Jerry's 13 vectors incl. all 6
  **leading-zero** cases (`58000004 → 0690`, `58000011 → 0104`, …); every one returns a 4-char code
  with the zero kept. New permanent guard: `test/services/totp_backend_vectors_test.dart`.
- Jerry confirmed: **`totp_digits` is always 4** (global, never per-site; clamped 4–8), and the server
  **never emitted a 3-char code** (`str_pad(..,4)`; tray body + `data.code` share one variable). So
  the tray-mismatch and per-site-digits theories are both eliminated.
- Current app pads on **both** paths (`trigger`/`triggerLocal` → `_normalizeCode`; overlay shows
  `state.code`); `472→0472` asserted by passing tests. The current build **cannot** render 3 digits.
- ⇒ **Cause = a stale app binary older than the 2026-07-21 padding fix.** Fix = update those devices.
  **Decisive evidence still wanted: the app version on an affected device.**
- Backend also widened code columns `varchar(4)→(8)` (a 5th char was 500-ing) + added a
  length-mismatch WARNING log (fires if a live device still drops a zero post-update). Online codes
  → 1000–9999 next deploy (no leading zero at all). Rollout NOT blocked.
- **Optional hardening (Jerry-endorsed, deferred):** respect `prov.digits` end-to-end so a future
  deliberate 5–8 digit config can't break the fixed-4 pad. Not urgent (digits always 4). Documented
  in the investigation doc's step 3; NOT implemented.

**State:** `flutter analyze` clean · **210 tests pass** (was 197; +13 TOTP vectors). Not committed.

---

## 2026-07-27 (cont.) — In-app Privacy Policy + Terms viewer, and app display name → "IronLock"

- **App display name → "IronLock"** (was "Guard Monitor"): `android:label` (AndroidManifest) +
  iOS `CFBundleDisplayName`. Left the pubspec package `name: guardmonitor` and the applicationId
  `com.ironlock.guardmonitor` untouched (package name must stay lowercase / matches imports;
  applicationId is FCM-bound and Play-locking). Native change → needs a full rebuild to show.
- **Legal docs bundled + in-app viewer** (user supplied the full UK-GDPR Privacy Policy + T&C):
  - `assets/legal/privacy_policy.md` + `assets/legal/terms.md` (NEW; registered under
    `flutter: assets:`). These are also the source to host at the Play privacy-policy URL.
  - `lib/screens/legal/legal_screen.dart` (NEW) — `LegalScreen` with Privacy/Terms tabs, renders
    the markdown via a tiny in-house block renderer (headings/bullets/paragraphs) using AppType/
    AppColors — **no new dependency** (no flutter_markdown). `LegalDoc` enum + `LegalScreen.open()`.
  - Entry points: first-run `privacy_notice_overlay` gained a "Read the full Privacy Policy &
    Terms" link; the `login_screen` footer now has tappable **Privacy Policy · Terms** links.
  - Test: `test/screens/legal_screen_test.dart` (assets load + carry expected content; enum shape).

**State:** `flutter analyze` clean · **197 tests pass** (was 195; +2). Not committed.
**Note:** the Privacy Policy still needs **hosting at a public URL** for the Play listing (the
bundled file is the source) — Play requires a URL, not just in-app text.

---

## 2026-07-27 (cont.) — Per-site verification settings (empty schedules) + pubspec name fix

Jerry shipped **per-site on/off + min/max gap** for photo & wakefulness checks
(`FLUTTER_HANDOFF — Per-Site Verification Settings`). No API shape change; the only new thing:
**`photos.schedule` / `wakefulness.schedule` can now be `[]` = that check is OFF for the shift.**
Audited the app against it and replied (`docs/BACKEND_REPLY_PER_SITE_SETTINGS_2026-07-27.md`).

- **Already correct:** empty **photo** schedule → `PhotoProvisioning.fromJson` returns null → not
  armed → nothing fires. Empty **wakefulness** schedule → parses with zero marks → `checkSchedule`
  no-ops + `scheduleWakefulnessChecks([])` registers nothing. Manual pushes still handled (pending
  polls + FCM are NOT gated on the schedule). iOS 64-notif cap already safe (`_maxScheduledPerType
  = 31` → 31·2+1 ≤ 64).
- **`totp_seed` is not the switch (his §3.2):** our seed check only decides "provision vs mock
  fallback poll," never "wakefulness on"; off-config (seed + empty schedule) fires nothing AND
  suppresses the `/welfare/pending` fallback. Locked in: `test/providers/wakefulness_provisioning_test.dart`.
- **Fixed (his §3.3):** the offline-photo **nonce prefetch** (`refillIfLow`) was unconditional;
  now gated on "photo schedule armed" at shift start + the 20s online top-up (skipped when photos
  off — a manual online request carries its own nonce, so nothing breaks). Resume-prime left
  unconditional (races the async restore; a lone wasted prefetch is harmless).
- **His §5 tight gaps — answered, no code change yet:** offline capture is guard-response-latency
  bound (camera can't fire backgrounded); worst-case ~15 min (`fireWindowMinutes`). Told Jerry to
  keep the **minimum gap ≥ ~20 min** for offline sites; offered an adaptive fire window if he wants
  tighter sites to fail-fast to "missed". Marks beyond 31/type rely on the foreground poll.

- **🔴 Caught while here: `pubspec.yaml` `name:` had been changed to `IronLock`** — invalid (Dart
  package names must be lowercase) and it broke **every** `package:guardmonitor/...` import →
  `flutter analyze` showed **507 errors**. **Reverted to `name: guardmonitor`** (matches all imports
  + the directory); `flutter pub get` + analyze clean again. (If a display name was wanted, that's
  the store listing / `android:label`, NOT the pubspec package name.)

**State:** `flutter analyze` clean · **195 tests pass** (was 192; +3). Not committed.

---

## 2026-07-27 — Android release signing + R8 wired for Play Store (build verified)

Prepped the app for a real Play Store upload (guide: `docs/PLAY_STORE_RELEASE.md`).
- **Was:** the release build signed with the **debug** key (`build.gradle.kts` →
  `signingConfigs.getByName("debug")`) — Google Play rejects that.
- **Now:** `android/app/build.gradle.kts` loads `android/key.properties` (already gitignored,
  alongside `*.jks`/`*.keystore`), defines a **release** signing config from it, and uses it for
  release builds — **falling back to debug when the file is absent** so local `flutter run
  --release` and CI without secrets still build. Also turned on **R8** (`isMinifyEnabled` +
  `isShrinkResources`) with a new `android/app/proguard-rules.pro` (keeps for
  `flutter_local_notifications`/Gson + a Play Core `-dontwarn`).
- **Verified it builds:** `flutter build appbundle --release --obfuscate
  --split-debug-info=build/symbols` → `✓ app-release.aab (67.8MB)` in ~177s (debug-signed
  fallback, since no keystore yet). So R8 + the ProGuard rules compile cleanly.
- **Still on the user (not code):** generate `~/ironlock-release.jks` + create
  `android/key.properties` (step 2) — then the same build command emits an upload-ready
  release-signed AAB automatically. Then the Play Console work: background-location declaration
  (+ demo video), `USE_EXACT_ALARM` + location-FGS justifications, data-safety form, privacy
  policy URL. Full checklist in `docs/PLAY_STORE_RELEASE.md`.
- **Note:** 67.8MB is the *universal* AAB (all ABIs + assets); Play's per-device split delivery
  ships far less. Non-fatal build warnings only (KGP plugin notice for battery_plus/camerax;
  DWARF-in-ELF from obfuscation).

**State:** release AAB builds; `key.properties`/keystore not created (intentional — user owns the
signing key). Nothing committed. iOS App Store still Apple-account-blocked (separate track).

---

## 2026-07-24 (cont.) — Offline photo NTP/time thread with Jerry — CLOSED, no app changes

Jerry sent `FLUTTER_HANDOFF — Offline Photo "Scheduled" vs "Capture" Time` (two times on the
timeline: `requested_at` = scheduled slot, `submitted_at` = true shutter — not a bug). Audited
our offline photo time path against it and replied
(`docs/BACKEND_REPLY_OFFLINE_PHOTO_TIME_2026-07-24.md`); **Jerry replied back and both open
questions resolved with NO app change:**

- **We already send `ntp_reference` + `elapsed_seconds` on offline photos** (exact names) —
  `PhotoService.submitOfflinePhotos`; values from `TimeAnchorService.capture()`, stored on the
  queue row, re-sent verbatim by the flush.
- **Q1 (our pre-projected form: `ntp_reference` = projected shutter instant, `elapsed_seconds`
  = 0) — ACCEPTED, keep as-is.** Jerry's `reconstructCaptureTime()` computes
  `ntp_reference + elapsed_seconds` and trusts it; `0` is NOT read as "no proof" (that's why
  SH-1049 verified). Do **not** switch to the raw last-sync+gap form — pure churn + regression
  risk. Bonus: our non-null anchor also gets the EXIF-vs-NTP clock-manipulation cross-check.
- **Q2 (add NTP fields to the ONLINE path) — Jerry RETRACTED the ask; do NOT.** Online is
  server-anchored (`server_received_at`); phone-NTP there would risk a false `TIMELINE_ANOMALY`
  hard-reject (capture time predating nonce issuance). **Online path stays exactly as-is.**
- **SH-1050 "empty NTP" = correct behaviour** — that phone never obtained an NTP anchor (offline
  from launch); we correctly OMIT `ntp_reference` rather than send an untrusted wall-clock value,
  so the server falls to `captured_at` + flags `NTP_UNAVAILABLE`. No payload fix possible (needs
  ≥1 online NTP sync); already mitigated by priming the anchor at start/resume/cold-start.
- **Drift (~7–10 min slot→capture) = guard response latency to the offline local notification**
  (camera can't fire backgrounded → notification → tap → poll opens camera → shutter). Expected,
  not a poll interval. Dashboard headlines the capture time + shows the slot as a note — truthful.
- **15-min `fireWindowMinutes` cap confirmed as the "too-late = missed" threshold** — interlocks
  with Jerry's new server-side Missed reconciliation. Keep it.
- **Only optional, non-blocking follow-up:** a separate `ntp_last_sync_at` **diagnostic** field
  so the backend could judge anchor age. Jerry won't consume it until agreed → **not
  implemented** (would be dead weight now).

**No code changed. No tests changed.** Thread closed on both sides. (Unrelated: the 90s
offline-capture expiry from the entry below is a real code change; this NTP thread is docs-only.)

---

## 2026-07-24 (cont.) — Offline scheduled photo: add a 90s expiry (overlay no longer lingers forever)

**Bug:** an OFFLINE scheduled photo capture opened with **no countdown and no expiry** — so
if the guard never took the shot, the camera overlay stayed open **forever** (no way for it
to close itself). Online requests already expire on the server's 90s window; offline had none.

**Fix:** the scheduled capture now runs the **same fixed 90s window** as an online request,
anchored to when the screen opens (there's no server issue-time offline). On expiry the
screen closes (after the existing ~3s "expired" beat) and records a **miss** locally for an
honest end-shift summary (the server also logs the missed scheduled check from the
reconstructed timeline). The captured photo is still validated server-side by its
**NTP-anchored timestamp**, not this client timer — the 90s only bounds the overlay, it does
NOT gate offline validity (pool nonce is still shift-long).
- `photo_provider.dart`: `openScheduled()` now opens a full `kPhotoWindowSeconds` (90s) window
  (was a no-countdown idle surface).
- `photo_screen.dart`: scheduled mode runs the per-second tick (was `return`-ing before it);
  the timer/progress bar now shows for scheduled too, with the "Offline — saved and uploaded
  when you reconnect" note kept **beneath** it; expiry records `recordPhoto(passed:false)`
  (scheduled only — online still leaves the verdict to the server).
- Tests: `test/providers/photo_seconds_remaining_test.dart` (+2: opens at 90s; ticks to expiry).

**State:** analyze clean · **192 tests pass** (was 190; +2). Not committed. Same-second
capture-vs-expiry race is safe: once the guard taps Use Photo the status goes `uploading`,
which the tick skips, so the countdown stops before it can double-fire a miss.

---

## 2026-07-24 (cont.) — Swipe-kill cold-start bugs: photo "out of nowhere" + missing site name

Two on-device bugs reported after removing the app from recents then reopening: (1) the
shift details don't show; refreshing then **triggers a photo verification out of nowhere**
and only then shows the offline-saved items; (2) the **site name is blank**. Both were the
same class of gap as the wakefulness fixes — **state that only lived in memory or on the
server, lost on a cold start.**

- **🟠 Photo "out of nowhere" — FIXED.** `PhotoScheduleNotifier._fired` (the offline
  scheduled-capture dedup) was **in-memory only** — the exact bug we'd just fixed for
  wakefulness, but for photos. After a swipe-kill, `restore()` re-armed the schedule with an
  **empty** `_fired`, so the next offline poll re-fired a scheduled mark the guard had
  already handled (or a still-in-window missed one) → the camera opened unprompted. Now
  persisted: `SecureStorageService.{save,get,clear}PhotoFired`; `_markFired` persists each
  fire; `restore()` loads the set **before** the first `checkSchedule`; cleared on shift end
  / sign-out / fresh provision. Test: `test/providers/photo_fired_persistence_test.dart`.
- **🟠 Missing site name / shift details on cold start — FIXED.** The site name, schedule,
  and overdue banner all read from `currentShiftProvider` (`CurrentShiftModel`), which was
  **never persisted** — on cold start it stayed null until `GET /shifts/current` succeeded,
  and offline (or the known null-for-active backend bug) it never did, so the card showed
  `—`. Now the shift object is snapshotted to disk while active: added `toJson()` to
  `CurrentShiftModel` + `ShiftSiteModel`/`ShiftGeofenceModel` (UTC-emit so
  `parseServerUtc(...).toLocal()` round-trips to the same instant); `CurrentShiftNotifier`
  persists on every mutation (`fetch`/`start`/`end`/`requestEarlyEnd`/`clear`, cleared on a
  completed/cancelled/missed/null status) and `restoreSnapshot()` rebuilds it on cold start
  **before** the server fetch (wired into `AuthNotifier.build`, ahead of
  `shiftProvider.restoreFromDisk()`). `fetch()`'s existing null-guard then preserves the
  restored snapshot on a bad poll. Tests: `test/models/current_shift_snapshot_test.dart`.
- **"Shows offline items only after" — explained, no separate fix.** That's just the
  `pendingSync` chip refreshing during the same poll; once the shift card renders from the
  snapshot and the fired-set no longer misfires, the sequence reads correctly.

**Files:** `models/current_shift_model.dart`, `services/secure_storage_service.dart`,
`providers/shift_provider.dart`, `providers/auth_provider.dart`,
`providers/photo_schedule_provider.dart`. **State:** analyze clean · **190 tests pass**
(was 186; +4). Not committed. **On-device check:** start a shift → swipe-kill → reopen →
confirm the card shows site name + schedule immediately (offline too) and NO photo screen
opens unprompted on refresh.

---

## 2026-07-24 (cont.) — Carried-over open items from HANDOFF: 3 app-side fixes + 1 backend ask

Swept the whole HANDOFF for still-open items we'd been carrying and cleared the
actionable app-side ones. (Apple-Developer-account iOS parity items left as-is, per
request; L1/L2 on-device verify + backfill-flag flip still parked as before.)

- **🟠 Captive-portal / dead-Wi-Fi blind spot — FIXED (was deferred "needs a call",
  2026-07-22 cont.5 #2).** `isOnlineProvider` is interface-based (`connectivity_plus`),
  so on a venue captive portal / associated-but-dead Wi-Fi it reported **online** while
  **no** API call got through — which suppressed the offline welfare/photo scheduler
  **and** failed every poll, so the guard saw **no prompt at all**. Added a real
  **server-reachability** signal: `serverReachableProvider` + a `_ReachabilityInterceptor`
  on the shared Dio (`api_client.dart`) — any HTTP response (even 4xx/5xx) ⇒ reachable;
  a null-response error (connection/timeout) ⇒ unreachable. `home_screen._pollBackend`
  now gates the offline scheduler on **interface-online AND server-reachable**, so a
  "connected but unreachable" phone falls back to the offline path and keeps prompting.
  **Not the old mislabel bug** (2026-07-22 cont.2): that was a genuinely-online guard
  tagged Offline via push-availability; this uses true reachability, and since the
  server really can't be reached, recording via the offline endpoint (→ tagged Offline)
  is *correct*. Test: `test/services/server_reachability_test.dart`.
- **🟢 Offline nonce pool not primed on resume — FIXED (low, carried since 2026-07-23).**
  `start()` primed the offline nonce pool + NTP anchor, but `resumeFromServer` and
  `restoreFromDisk` didn't — so a guard who resumed/relaunched a shift then dropped
  offline couldn't queue an offline photo until the first 20s poll. Extracted
  `_primeOfflineBuffers(shiftId)` (detached, fully guarded) and called it from all three
  paths (`shift_provider.dart`).
- **🟢 Fired-marks dedup lost on app-kill — FIXED (welfare-flow #6, carried since
  2026-06-25).** The schedule notifier's in-memory `_fired` set reset on a kill, so a
  cold-start restore could **re-challenge and double-count** a welfare mark the guard
  already answered. Now persisted: `SecureStorageService.{save,get,clear}WakefulnessFired`
  (wiped on shift end / sign-out / fresh provision); `WakefulnessScheduleNotifier`
  restores it in `restore()` **before** the first `checkSchedule`, and `_markFired`
  persists each mark as it fires. Test: `test/providers/wakefulness_fired_persistence_test.dart`.
- **🟢 Login "Signing in…" arc didn't spin — FIXED (cosmetic).** Was painted against
  `AlwaysStoppedAnimation(0)`; replaced with a `_SpinningLoader` (repeating controller +
  `RotationTransition`) in `login_screen.dart`. The other noted cosmetic — "Use Photo"
  flashing "Try Again" — was **already resolved** in code (the result block shows Try
  Again only for `PhotoStatus.failed`, never `flagged`), so no change.

**Backend ask (new deliverable):** `docs/BACKEND_ASKS_2026-07-24_LATE_OFFLINE_WINDOW.md`
— confirm the server accepts a **late** offline submission for an older window (no
max-age cutoff on `/wakefulness/offline`, and offline photos accepted on `captured_at`
regardless of flush delay). This got load-bearing *because* of the captive-portal fix:
a guard can now be "effectively offline" for a whole shift on a bad network, so offline
answers may be flushed much later. Idempotency + `window_reference`/`scheduled_at` are
already sent, so if there's no cutoff there's nothing to build.

**State:** `flutter analyze` clean · **186 tests pass** (was 181; +5). Nothing committed.
**Still open (unchanged):** L1/L2 on-device defunct-crash verify + remove the `main.dart`
diagnostic; flip `sendGpsBackfillFlag` after Jerry's OK; iOS FCM/APNs + Universal Links
(Apple Developer account); the 3 confirmations in `BACKEND_ASKS_2026-07-24.md`.

---

## 2026-07-24 — Full manual code audit + fixes (1 high, 5 medium, 8 low)

**Goal:** deep, file-by-file review of the whole app (not test-driven), documented in
`guardmonitor/docs/CODE_AUDIT_2026-07-24.md`, then fix the findings without breaking anything.

**Audit result:** 1 high, 5 medium, 8 low + a "reviewed & cleared" list and a coverage map.
**All fixed except L1/L2** (temp diagnostic in `main.dart` + the unverified defunct-element crash —
held together pending ONE on-device confirmation; do not remove the diagnostic until then).

**Fixes shipped:**
- **H1 (data loss)** — offline **GPS + photos were stranded** when a shift ended with a backlog
  (flush was gated on the *live* shift; wakefulness wasn't). Now GPS/photo flush is
  **shift-independent**: `dueGpsAll`/`duePhotosAll` + group by each row's own `shiftId` in
  `sync_flush_service.dart`. Regression tests added (drain with `currentShiftId == null`).
- **M1** — `totalPending()` now uses a COUNT() `customSelect` instead of loading every row each poll.
- **M2** — wakefulness code enforced to **exactly 4 digits**: `_normalizeCode` returns null for
  >4 (or empty); short values still zero-pad (leading-zero restore). Both `trigger`/`triggerLocal`
  skip a malformed code (stay idle → server raises its own miss). Tests added.
- **M3** — offline-photo `enqueueCapture` now **rolls back** a claimed nonce (`releaseNonce`) +
  deletes durable copies if persist/sign/enqueue throws.
- **M4/M5** — new `lib/utils/server_time.dart` `parseServerUtc` (zone-less string → UTC before
  localise), applied in `current_shift_model`, `shift_service`, `shift_access_link`; shift parse
  logs a clear error instead of an obscure cast; `fetch()` keeps last-good shift on a bad poll.
- **L3** — photo schedule sorted ascending on parse. **L4** — notification ids now use an
  isolate-stable FNV-1a hash in a wider band (fixes cross-isolate mismatch + collisions).
  **L5** — per-type notification cap 31 so wakefulness+photo+shift-end ≤ iOS's 64.
  **L6** — shift-end reminder now exact-with-inexact-fallback. **L7** — location gate now gated on
  `shift.active` (Sign Out reachable pre-shift). **L8** — in-memory seen-review set bounded.

**Backend follow-ups (non-blocking, all fixed defensively app-side):**
`guardmonitor/docs/BACKEND_ASKS_2026-07-24.md` — (1) always emit UTC datetimes with `Z`,
(2) `/shifts/current` never a partial shift with null `scheduled_*`, (3) wakefulness `code` always
4-char zero-padded.

**Offline END (same session):** the END button is now **disabled while offline** with the hint
"You're offline — reconnect to end your shift" (`_ActionButtons`, `home_screen.dart` — `locked =
pending || !online`). Ending is a server op (duration/early-end approval/auto-close), so a tap
offline only failed with an error before. Backend auto-close at scheduled_end+grace stays the net.
(Considered but NOT done: queueing the end offline like Phase-7 captures — would need a backend
`ended_at` backfill; parked. START has the same offline-fails property — left as-is for now.)

**Offline-flush data-loss fix + sync progress bar (same session):**
- **Root cause found** (guard reported: 3 wake + 1 photo offline → only the last wake synced): the
  enqueue-kick + 60s heartbeat I added earlier fire the flush *while still offline*, and every failed
  offline attempt incremented a row's `attempts` toward the 12-strike cap — so on a long offline
  stretch the OLDEST queued answers hit the cap and were auto-deleted before reconnect.
- **Fix:** (1) `_runCycle` skips entirely while `!_wasOnline` (wait for a real connection, then push);
  (2) a "no server response" failure (offline/timeout, `DioException.response == null`) now GATES the
  row (`gateGps`/`gateWakefulness`/`gatePhoto` — set next_attempt, no increment) instead of striking
  it. Only a real server 5xx counts toward the cap. New test: 5 offline flushes keep the row at
  attempts 0. Net: queued checks wait indefinitely for a connection; only an active server rejection
  can drop them.
- **Sync progress bar:** `pendingSyncProvider` now holds `SyncProgress {pending, total}` (high-water
  denominator). `_SyncStatusChip` shows a `LinearProgressIndicator` + "Uploading N of M… X%" that
  fills as rows drain; a 1.2s tick (online + backlog only) animates it. Offline shows the saved count.

**Shift survives a swipe-kill + sync progress-bar completion (same session):**
- **Bug:** removing the app from recents then reopening lost the whole active shift (elapsed time,
  schedules, counters). Cause: `ShiftState` was **in-memory only** — on cold start the app rebuilt the
  shift purely from `GET /shifts/current`, so a null-for-active response (known backend bug) or being
  offline wiped it.
- **Fix:** persist `ShiftState` to secure storage (`saveShiftState`/`getShiftState`/`clearShiftState`,
  added to `clearSession`). `ShiftState.toJson/fromJson`; `ShiftNotifier._persist()` mirrors every
  mutation (start/resume/record*/end/reconcile); `restoreFromDisk()` rebuilds the active shift +
  restarts GPS + re-arms schedules/reminder. Wired into `AuthNotifier.build()` on cold start, BEFORE
  the server fetch — and `fetch()`'s existing null-guard then preserves it. Server can still CLOSE it
  via `reconcileServerClosed`. Round-trip test added.
- **Progress bar:** was blinking out on a fast flush. `SyncProgress` gained a `completed` state; the
  chip now lingers ~3s at 100% green "All offline items synced ✓" before hiding (`visible` gate).

**State:** `flutter analyze` clean; **181 tests pass** (was 168). Nothing committed. **Open:** L1/L2
need one on-device run (trigger a welfare/photo overlay, confirm no defunct-element spam, then remove
the `FlutterError.onError` diagnostic from `main.dart`).

---

## 2026-07-23 (cont.) — Offline flush: priority ordering + faster triggers

**Goal:** stop compliance-critical data (welfare answers, proof photos) from arriving late on the
dashboard when a phone reconnects — they were queuing behind a long GPS backlog.

**Three changes (all in `sync_flush_service.dart` + `offline_queue_db.dart`):**
1. **Reordered flush** — was wakefulness → **GPS** → photos; now **wakefulness → photos → GPS**
   (compliance-critical before bulk telemetry). Server tolerates any order (`PHASE_7_SYNC_INTEGRITY.md
   §3`), so this is safe. Second benefit: a transient GPS failure `return`s out of the cycle, so
   putting photos *before* GPS means they're already sent before GPS can abort the run.
2. **Enqueue-kick** — `OfflineQueueDb` now exposes `onImportantEnqueue` (a broadcast stream fired by
   `enqueueWakefulness`/`enqueuePhoto`, **not** GPS). `SyncFlushService.start()` subscribes and kicks
   an immediate `flush()` so an important capture drains the instant signal is back instead of waiting
   for a connectivity edge. single-flight makes it safe to over-fire.
3. **60s heartbeat** — `SyncFlushService` runs `Timer.periodic(60s)` while started, as a backstop for
   the "online flag never flips but requests fail" soft-failure case. Empty queue = no-op.

`stop()` cancels the enqueue sub + heartbeat; `OfflineQueueDb.close()` closes the stream controller.

**Payloads/endpoints/idempotency unchanged** — only order + timing of the sends. Sent Jerry an FYI:
`docs/BACKEND_NOTE_FLUSH_PRIORITY_2026-07-23.md` (no backend action needed; flags slightly more
duplicate re-sends from the new triggers, already handled by idempotent responses).

**State:** `flutter analyze` clean · **168 tests pass** (was 166; +2: photos-before-GPS ordering,
`onImportantEnqueue` fires). Not committed.

**Jerry's reply (`BACKEND_NOTE_FLUSH_PRIORITY_2026-07-23.md` → his reply):** reorder **confirmed,
shipped**. Order-independence + idempotency verified server-side; keep GPS big-batch-last (don't split
into small requests — we already do). One optional guardrail: a `backfill:true` flag on reconnect-drain
GPS POSTs so a **>200-ping backlog** (~50+ min offline at 15s cadence) can't have its 2nd chunk misread
as a live tick and retroactively page. **Prepared app-side, held OFF:** `SyncFlushService.sendGpsBackfillFlag`
(false) → adds `backfill:true` to every `_flushGps` body when flipped. Every `_flushGps` request IS a
backfill by definition (live pings post direct; only failed ones queue). ⚠️ **Do NOT flip until Jerry
confirms the server honours the field** (he asked us not to send it yet). Reply sent:
`docs/BACKEND_REPLY_FLUSH_PRIORITY_2026-07-23.md` — asks him to wire it up + confirm placement/semantics,
then it's a one-line flip + redeploy. Also confirmed to him we do NOT send a position-first ping ahead of
the backlog (his corollary guardrail).

---

## 2026-07-23 (cont.) — Crash fix: deep-link double-handling (Flutter built-in vs app_links)

**🔴 Crash — FIXED.** Tapping/handling a shift-access SSO link threw
`Could not find a generator for route "/<64-hex-token>"` from `didPushRouteInformation`. Cause:
the app handles the `ironlock://shift-access/<token>` link itself via the **app_links** plugin
(`deep_link_service.dart`), but **Flutter's built-in deep-linking was also enabled** and tried to
push the link's path as a named route — the app has no router, so `MaterialApp` crashed.
**Fix:** disabled Flutter's automatic deep-linking on both platforms so app_links is the sole
handler (app_links uses a separate channel, unaffected):
- iOS `ios/Runner/Info.plist`: `FlutterDeepLinkingEnabled = false`.
- Android `AndroidManifest.xml`: `<meta-data flutter_deeplinking_enabled = false>` in the activity.

⚠️ **Native config change → needs a full rebuild** (stop `flutter run` with `q`, re-run), NOT a hot
restart. `flutter analyze` clean · 166 tests pass (Dart untouched). Not committed.

---

## 2026-07-23 (cont.) — Crash fix: `ref` used in dispose() + blanket HTTP debug logging

**🔴 Crash — FIXED.** On-device teardown threw `Bad state: Using "ref" when a widget is about to or
has been unmounted is unsafe` from `_WakefulnessOverlayState.dispose` (the `reset()` call). Riverpod
forbids `ref` in `dispose` once the element is unmounted during tree finalization. Fixed in TWO steps
(the first surfaced a SECOND Riverpod error — *"modified a provider while the widget tree was building"* —
because mutating a provider synchronously in dispose still runs during tree finalization):
- Capture the notifier in a field in `initState` (`late final WakefulnessNotifier _notifier` /
  `late final PhotoNotifier _photoNotifier`) so dispose never touches `ref`.
- **Defer the reset off the frame:** dispose now calls `Future.microtask(_notifier.reset)` /
  `Future.microtask(_photoNotifier.reset)` — runs after the frame, when provider mutation is safe.
  `wakefulness_overlay._close()` **no longer resets at all** — its `.then` runs during the pop's frame,
  so a direct reset there threw the same "modify during build" AND cascaded rebuilds into the defunct
  overlay (`'_lifecycleState != defunct'` assertions spamming). dispose's deferred microtask is the sole
  reset now.
- `photo_screen.dart`'s reset was introduced by *my* 2026-07-22 expiry-close change — same anti-pattern.
- Swept all `dispose()` in `lib/` for `ref.read/watch/listen` — none remain.

**Debug logging (dev visibility for on-device testing).**
- `api_client.dart`: added a **debug-only** `_DebugLogInterceptor` on the shared Dio — logs every
  request/response/error as `[http] → METHOD url` / `[http] ← status METHOD path` / `[http] ✗ status
  METHOD path code=…`. **Never logs bodies/headers** (they carry the password, JWTs, `hmac_secret`).
  Covers ALL backend calls in one place; only added when `kDebugMode`.
- `auth_service.login`: added `[auth]` request/OK/FAILED logs (identifier + status + error code, never
  the password/tokens) so a login failure's real reason (window/creds/network) is visible.

**State:** `flutter analyze` clean · **166 tests pass**. Not committed. On-device: hot-restart (R) to
pick up the Dio-interceptor change.

---

## 2026-07-23 (cont.) — Timezone: localize LOGIN_WINDOW_CLOSED (+ full time-display audit)

Jerry flagged a wrong-zone login message (an 11:25 shift showed as "05:55" — the tester's UTC+5:30
offset). Rule from backend: **every datetime is UTC (ISO-8601 `Z`); the app must localize on-device.**
For `LOGIN_WINDOW_CLOSED`, build the copy from the machine-readable `details` timestamps, NOT the
server's pre-rendered `message` (which renders in one fixed server zone).

**🟠 LOGIN_WINDOW_CLOSED message — FIXED.** Both login paths (password + SSO redeem) showed
`apiError.message`/`err.message` verbatim → the wrong-zone string. Added
`ShiftAccessException.loginWindowMessage(ApiError)` (+ `_localHHmm` helper) that switches on
`details.reason`: `too_early` → composes *"You can sign in from {window_opens_at, local} — 15 minutes
before your {next_shift_start, local} shift."* from the UTC `details` via `DateTime.parse(...).toLocal()`;
`expired` → "Your sign-in window has closed." (the login screen already shows the contact-supervisor
cue when `windowExpired`); `no_shift` → own copy; falls back to the server `message` only when `details`
is absent/unparseable. `login_screen._signIn` now routes LOGIN_WINDOW_CLOSED through it (switch on code);
`shift_access_link.fromDio` uses it too — one shared localizer for both paths.

**Full time-display audit — clean.** Every other wall-clock render already uses a DateTime that's
localized at the source: `CurrentShiftModel.fromJson` does `.toLocal()` on scheduled/actual start/end;
`ShiftState.startTime` = `actualStart` (localized) or `DateTime.now()` (local); the manual `_fmtHHmm`/
`_formatTime`/`_fmt` formatters read `.hour`/`.minute` off those already-local values. The login-window
hint `opensAt` derives from `cs.scheduledStart` (local). Durations/deadlines (nonce `expires_at`,
wakefulness `issued_at + response_seconds`) compare UTC instants directly — no zone needed. So the only
raw-UTC-to-screen leak was the login message; now fixed.

**Files:** `lib/services/shift_access_link.dart` (localizer + helper),
`lib/screens/login/login_screen.dart` (route LOGIN_WINDOW_CLOSED through it + import),
`test/services/shift_access_link_test.dart` (updated expired test + 3 new: too_early localization,
missing-timestamp fallback, no_shift).

**State:** `flutter analyze` clean · **166 tests pass** (was 163, +3). Not committed.
**Note:** backend is also fixing its `message` to render UK time, but device-localization is the real
fix and is zone-correct anywhere. In dev, an admin browser and a test device in different zones will
legitimately differ by the offset — expected; prod is all-UK.

---

## 2026-07-23 (cont.) — Backend reply reconciliation (nonce TTL + wakefulness issued_at)

Jerry answered `docs/BACKEND_ASKS_2026-07-23.md` — all 3 handled server-side. Reconciled the app.

**🔴 #1 Offline-photo nonce TTL (the blocker) — server-fixed, app decoupled.** Pool nonces now stay
valid the whole shift (server keys off each nonce's own `expires_at` + a grace margin). The app
**already** stored and enforced per-nonce `expires_at` in the `NoncePool` table, so the "can't save the
photo" (`NONCE_EXPIRED`) path is fixed with no structural change. **BUT** Jerry's change also flips the
aggregate `offline_nonce_ttl_minutes` (start `photos` block) from a fixed `15` to shift-length (~500 for
8 h). `PhotoProvisioning.dueMark` was reusing that field as the *offline-photo fire window*, so doing
nothing would have ballooned "fire a due mark within 15 min" into "…within ~8 h" — firing wildly-late
captures that miss the mark the server matches by timestamp. **Fixed by decoupling:** new fixed
`PhotoProvisioning.fireWindowMinutes = 15` constant drives `dueMark`; `offlineNonceTtlMinutes` is now
display/round-trip metadata only. Added a regression test locking this in (a 500-min TTL must NOT widen
the fire window).

**🟡 #2 `issued_at` on the wakefulness push — server-added, app already handles it.** `push_router`
already parsed `data.issued_at` and `_dispatch` already used it, so a tapped live challenge now opens
instantly. Kept the stale-tap guard (`!confirmReceipt && issuedAt == null → drop`) as defence-in-depth
for the deploy-rollout window / malformed payloads; updated its "remove once backend sends issued_at"
comment. Updated `push_router` payload doc.

**🟡 #3 Expired photo-pending pruning + CRITICAL — confirmed in code.** No app change (our defensive
handling was already correct). Re-test on device after Jerry's next deploy + `config:cache`.

**Files:** `lib/providers/photo_schedule_provider.dart` (decouple fire window),
`lib/services/push_messaging_service.dart` (comment), `lib/services/push_router.dart` (payload doc),
`lib/services/nonce_pool_service.dart` (doc: shift-spanning TTL), `test/providers/photo_schedule_test.dart`
(+regression test), `docs/BACKEND_ASKS_2026-07-23.md` (resolved banner).

**State:** `flutter analyze` clean · **163 tests pass** (was 162, +1). Not committed.
**Still owed by backend:** #1/#2 land on their next release; #3 re-test post-deploy. The nonce-TTL ask
is now closed.

---

## 2026-07-23 (cont.) — Deep interaction audit (client-readiness) + iOS notif fixes

Function-level audit of the logic-bearing code focused on *interactions/clashes* (provider lifecycles,
async races, shared state, ID collisions), for a client deliverable. Found + fixed 2 real bugs; the
crypto/TOTP/time/DB/HMAC layers remain clean.

**🔴 Notification ID collision — FIXED.** `showPhotoReview` used id `2000 + (hash & 0xFFF)` → range
[2000, 6095], which **overlapped** the wakefulness reminders (3000-3063), photo reminders (4000-4063),
and photo requests (`5000 + (hash & 0xFFF)` = [5000, 9095]). A review notification could land on the
same OS id as a **scheduled welfare-check reminder and cancel it** → a guard silently misses the
prompt. Fixed: reviews → `100000 + (hash & 0xFFF)`, requests → `200000 + (hash & 0xFFF)` — distinct
high ranges clear of everything.

**🟠 Cross-session state leak on sign-out — FIXED.** The single app-root ProviderScope survives
sign-out, so providers not explicitly invalidated carried the previous guard's state to the next guard
on the same device (shift handover). `alertsProvider` (previous guard's welfare-miss alerts),
`pendingPhotoProvider`/`photoProvider` (stale/expired photo request), `photoReviewProvider` (seen-set),
`zoneProvider`/`zoneUpdatedAtProvider`, `activeTabProvider` are now all invalidated in
`AuthNotifier.signOut()` alongside the shift/wakefulness ones.

**iOS notification foreground presentation — FIXED.** All four notifications used empty
`DarwinNotificationDetails()`, so on iOS nothing showed while the app was foregrounded. Added a shared
`_darwin` config (`presentAlert/Badge/Sound/Banner: true`). Also documented the iOS 64-pending-notif
cap. (iOS does NOT have the Android Doze problem — its scheduled notifications fire on time.)

**Audited clean:** main.dart lifecycle wiring, auth build/persist/signOut ordering, shift start/end/
resume/reconcile (the end vs auto-close race is correctly gated by end_type), SSO token extraction
(strict 64-hex), battery timer/subscription disposal, deep-link handling, photo-review dedup.

**Still open (unchanged):** offline-photo 15-min nonce TTL (backend), dead-Wi-Fi/captive-portal #2
(decision), `USE_EXACT_ALARM` Play-policy (decision), nonce pool not primed on resumeFromServer (low).

**Files:** `lib/services/notification_service.dart`, `lib/providers/auth_provider.dart`.

**Deeper round — parsing/model robustness (client data safety).** Read the models + auth/shift
services + login + camera lifecycle line-by-line. Two fragility bugs where imperfect server data would
break a core flow, both FIXED:
- **`GuardProfileModel.fromJson`** hard-cast every required string → a guard record with any null field
  (e.g. null `last_name`) threw mid-parse and **failed the whole login** with a cryptic TypeError. Now
  defaults each string (`as String? ?? ''`).
- **`CurrentShiftModel.fromJson`** parsed `site`/`geofence` with hard casts (`coordinates as List`) → a
  malformed geofence threw out of the whole shift parse, **dropping the shift on every 20s poll** and
  stranding the guard on a disabled START. Now parses nested objects via a try/catch `_parseNested`
  helper (bad sub-object → null, shift still loads).
Confirmed clean: login error handling (catches Dio + generic), camera controller lifecycle (releases
before re-open, handles dispose-during-init), shift-start server reconciliation. Minor cosmetic noted
(login "Signing in…" loader uses AlwaysStoppedAnimation so the arc doesn't spin — visual only).

**Files (deeper round):** `lib/models/guard_profile_model.dart`, `lib/models/current_shift_model.dart`.
**162 tests pass · analyze clean.** Not committed.

---

## 2026-07-23 — Device-test bugs: offline notifications (Android Doze) + offline photo (nonce TTL)

Two real on-device failures reported (Android): (1) offline welfare/photo reminder notifications only
appeared on reconnect/unlock, never at the due time; (2) offline photo capture failed "couldn't save
the photo," and the flush "wasn't working."

**#1 Offline notifications — FIXED (Android Doze / inexact alarms).** Root cause: no exact-alarm
permission was declared, so `exactAllowWhileIdle` threw and `_scheduleChecks` silently fell back to
`inexactAllowWhileIdle` — which Doze batches until the device wakes. Fix: declared
`SCHEDULE_EXACT_ALARM` (maxSdk 32) + `USE_EXACT_ALARM` in AndroidManifest, and
`NotificationService.requestPermission()` now calls `requestExactAlarmsPermission()` (Android 12
runtime grant). `_scheduleChecks` already tries exact first, so it now fires on time in Doze.
⚠️ **Play-policy note:** `USE_EXACT_ALARM` is store-reviewed — justified as core lone-worker safety
timing, but the listing must declare it. Also: aggressive OEM battery managers (Xiaomi/Samsung/etc.)
can still kill alarms, so FCM push remains the reliable delivery path; exact local alarms are the
offline fallback.

**#2 Offline photo "can't save" — DIAGNOSED, needs a BACKEND change.** The offline photo path draws a
prefetched pool nonce valid **15 min**, but offline photo marks are **50–70 min apart** and the pool
only refills while online — so a guard offline > ~15 min always has an expired pool → `draw()` null →
"couldn't save," and nothing queues (so the flush has nothing to drain — the "flush not working" is a
symptom). Unlike wakefulness (shared TOTP seed, works offline indefinitely), photo has no offline-
durable credential. **Backend ask:** issue offline-pool nonces with a TTL covering realistic offline
stretches (hours), or move offline photo to a seed/HMAC model. No app-side fix possible (can't
prefetch while offline).

**Diagnostics added (so the next test is conclusive):** the offline-photo failure now names the cause
+ shows pool depth (`photo_screen.dart`); `NoncePoolService.refillIfLow` logs prefetch parse
count/failure instead of swallowing silently (distinguishes a broken endpoint from TTL expiry).

**Files:** `android/app/src/main/AndroidManifest.xml`, `lib/services/notification_service.dart`,
`lib/services/nonce_pool_service.dart`, `lib/screens/photo/photo_screen.dart`.
**162 tests pass · analyze clean.** Not committed.

---

## 2026-07-22 (cont. 5) — Full-app bug audit + 5 fixes

Audited every layer for correctness bugs (offline sync, time-anchor, TOTP, SQLCipher/DB, auth
refresh, GPS, photo capture/upload, schedules, connectivity, sign-out). Most of the app is clean;
fixed 5 real issues. **#2 deliberately deferred** (needs a product call — see below).

**#1 🔴 Refresh interceptor concurrency (api_client.dart).** `_pendingRetries` was drained with a
`for-in` loop containing `await`s; a concurrent `401 TOKEN_EXPIRED` (the poll + GPS fire several at
once) appending mid-drain raised a `ConcurrentModificationError` → caught by the outer catch → a
**spurious forced sign-out** mid-shift; items added after the loop were `clear()`ed unresolved →
**hung requests**. Fixed: pop-based drain (`_drainPending`, `while removeAt(0)`) that tolerates
concurrent adds, plus `_failPending` so every queued request's handler resolves on the
refresh-failure paths too (no more hangs). Verified no orphan window exists (no awaits between the
drain and the `finally`).

**#3 🟡 Photo transport failure double-counted (photo_screen.dart).** A network blip on an online
upload recorded `recordPhoto(passed:false)`, then a successful Try Again recorded `passed:true` — one
request counted as both a miss and a pass. Now a transport failure shows "Upload failed — tap Try
Again" and records **nothing** (the server owns the real missed-photo verdict); only a genuine server
rejection counts as a miss.

**#4 🟡 Flush didn't stop on a mid-flush network drop (sync_flush_service.dart).** `_flushWakefulness`
/ `_flushPhotos` kept looping every due row on a transient failure (burning a Dio timeout each), unlike
`_flushGps` which returns early. Both now `return` on a `retry` decision (a per-row 4xx is still a
`drop`, so it doesn't stop the loop).

**#5 🟢 Offline pool-dry capture not counted (photo_screen.dart).** A scheduled offline capture that
can't queue (dry pool / no key) is discarded and won't re-fire, but wasn't recorded as a miss — now it
is, so the end-shift summary is honest.

**#6 🟢 Hardcoded digit count (wakefulness_provider.dart).** `s.entry.length != 4` → `!=
kWakefulnessDigits`.

**#2 ⏸️ DEFERRED (needs a call).** `isOnlineProvider` is interface-based (`connectivity_plus`), so on
dead-Wi-Fi / captive portal `online==true`: the offline TOTP scheduler is gated off AND the online
poll fails → the guard sees no welfare challenge (server still records the miss + alerts). A fix needs
a reachability signal, but done wrong it re-introduces the "online check tagged Offline" mislabel we
fixed in cont. 2. Left for a product decision.

**Files:** `services/api_client.dart`, `services/sync_flush_service.dart`,
`screens/photo/photo_screen.dart`, `providers/wakefulness_provider.dart`.
**162 tests pass · analyze clean.** Not committed. No interceptor unit test added (would need a new
mock-adapter dep + plugin-channel mocking — verified by reasoning + existing suite instead).

---

## 2026-07-22 (cont. 4) — Offline-sync test findings: 4 Flutter-owned fixes

Implemented the app-side items from `docs/TEST_FINDINGS_TRIAGE_2026-07-22.md` +
`docs/FLUTTER_APP_TASKS_2026-07-22.md` (backend owns the rest: reconnect clustering, DELAYED_UPLOAD,
offline badge). Full root-cause report was produced first; these are the fixes.

**#4 caveat — empty nonce pool on immediate offline (shift_provider.dart).** Shift start now primes
the offline nonce pool + NTP anchor (`refillIfLow` + `ensureFresh`), not only the 20s poll — so a
guard who drops offline in the first seconds can still queue an offline photo. Detached + fully
guarded (await inside try/catch, `.ignore()`) so it can never block/fail shift start.

**#1 — online/manual photo request raised no notification.** Root cause: nothing ever `.show()`s for
an incoming photo *request* (only scheduled reminders + review outcomes existed); the wakefulness
"heads-up" seen in the test was the *scheduled* local reminder coinciding, not a push. Added
`NotificationService.showPhotoRequest(requestId)` (stable id base 5000). Called from
`_backgroundHandler`'s `PHOTO_REQUEST` case (so a data-only push becomes visible once APNs lands;
works on Android now) **and** when the home listener first surfaces a new online request (parity with
wakefulness).

**#2a — stale tapped wakefulness got a full fresh window.** The FCM `WAKEFULNESS_CHALLENGE` payload
carries no `issued_at`, so `trigger()` couldn't date a tapped-old push → granted a full window for a
dead check whose barrier then stranded the screen. `push_messaging_service._dispatch` now drops a
TAP-delivered (`!confirmReceipt`) wakefulness with no `issued_at`; a genuinely-live one re-surfaces
via the `/wakefulness/pending` poll. **Backend ask filed** (added to `BACKEND_ASKS_2026-07-22.md`):
include `issued_at` in the wakefulness push so the guard can be dropped for the right reason.

**#3 + #2b — simultaneous wake+photo raced; cold-start listener miss.** New pure-Dart
`ChallengeQueue` (services/challenge_queue.dart) serialises full-screen challenge presentation.
`home_screen` now funnels BOTH the wakefulness overlay and the photo screen through it (one FIFO,
one at a time) instead of two independent `ref.listen`s racing `showDialog` vs `Navigator.push`.
Listener bodies extracted to `_onWakefulnessState`/`_onPendingPhotoState` + presenter helpers, and a
post-frame `_presentPendingChallenges()` covers the cold-start case where a push tap set state before
the listeners registered (ref.listen won't replay the current value). `_wakefulnessPresenting` guard
prevents double-enqueue.

**Files:** `providers/shift_provider.dart`, `services/notification_service.dart`,
`services/push_messaging_service.dart`, `services/challenge_queue.dart` (NEW),
`screens/home/home_screen.dart`; tests: `test/services/challenge_queue_test.dart` (NEW, 5 cases).

**162 tests pass · analyze clean.** Not committed. #4 offline photo confirmed already working
(pool-nonce capture, no internet at capture); the prefetch closes the only remaining gap.

---

## 2026-07-22 (cont. 3) — Backend reply reconciled (schedules / tagging / expired photo-pending)

Jerry replied to `docs/BACKEND_ASKS_2026-07-22.md`. **Net: no app code changes needed** — every
point is either a confirmation or already handled. Reconciliation:

- **§1 `schedule: []` was NOT a bug.** A 30-min test shift is mathematically always empty: first
  welfare mark = start + 30–45 min, first photo mark = start + 50–70 min — both past a 30-min
  `scheduled_end`. He declined to special-case short shifts (keeps production cadence honest).
  → **To observe checks on-device, use a ~2-hour test shift.** Welfare guaranteed with a window
  **>45 min**, photos with **>70 min**.
- **Schedule model:** welfare every 30–45 min, photos every 50–70 min (random draw once at start,
  ≤64 marks); **fixed at start** — trust the start payload for the whole shift. ⚠️ There is
  currently **no re-fetch endpoint** — only `POST /start` returns the arrays and start can't be
  re-called on an active shift. We already cache them on-device at start, so fine; but a mid-shift
  reinstall/clear can't recover them. Jerry will add `GET /shifts/{id}/schedule` if we need it.
- **§2 Offline tagging = endpoint-based** — `/respond` → Online, `/wakefulness/offline` → Offline.
  **Caveat:** `/respond` still flips to Offline if the body carries `window_reference` or
  `is_offline`. Verified our `WakefulnessService.respond()` body is clean (`code` + `responded_at`
  only) — nothing to fix.
- **§2b expired photo-pending = real backend gap, fix queued** (poll now filters on the live
  deadline, not just status). Our defensive handling (`_seenPhotoRequestIds` + auto-close) stays.
  Confirmed **expiry raises the CRITICAL "missed" alert server-side on its own** — the app correctly
  does nothing on expiry but close the screen.
- **§3 manual welfare trigger** appears on `/wakefulness/pending` with all five fields → our
  iOS-without-push path is confirmed working.

**Edge case to watch:** if a guard reconnects and their GPS ping lands **before** their offline
flush, the minute-cron may push an online check for a mark already answered offline → one ONLINE +
one OFFLINE row for that mark. Rare, both recorded truthfully. If seen on the dashboard, send Jerry
the two check IDs.

**No code changed. `docs/BACKEND_ASKS_2026-07-22.md` checklist marked resolved.** Not committed.

---

## 2026-07-22 (cont. 2) — Fix: expired photo request re-opened into a dead capture loop

**Symptom:** a missed photo verification, then pull-to-refresh, showed "request expired — new
request in 30s"; after 30s the camera re-opened, but capturing then failed "try again / timed out" —
in a loop.

**Root cause (three parts):**
1. The server keeps a **missed/expired** request marked `pending`, and the poll/pull-to-refresh
   re-opened it every cycle (`_handlingPhotoRequestId` only guarded while a screen was open).
2. `PhotoNotifier.tick()` **auto-reset the expired state to a fresh idle 90s window** — re-opening a
   live camera for a request whose server nonce/window was already dead.
3. So a capture into that fake-fresh window hit `NONCE_EXPIRED` → `failed` → "Try Again" → repeat.
   The `_UploadStatus` text even promised "new request in 30s", which the app can't do.

**Fix:**
- **home_screen:** added `_seenPhotoRequestIds` (bounded) — a request id opened once (completed OR
  missed) is never re-opened; only a genuinely new id opens. Poll + navigation both check it.
- **photo_provider:** `tick()` no longer resets expired → idle (never re-opens a live window); added
  `PhotoNotifier.reset()`.
- **photo_screen:** on expiry, drop any held batch and **auto-close after ~3s** (show "Verification
  window expired — closing…") instead of reopening; `reset()` the provider on `dispose` so a terminal
  state can't block the next request / the offline scheduler. Removed the misleading
  "new request in Ns" copy + the now-unused `expireCountdown` UI param.

**157 tests pass · analyze clean.** Not committed.

---

## 2026-07-22 (cont.) — Fix: online welfare check was recorded as "Offline" on iOS

**Symptom:** answered a welfare check while online, but the dashboard tagged it **Offline**.
**Cause:** the dashboard tags a check Offline when the answer hits `/wakefulness/offline`. The home
poll ran the local TOTP scheduler whenever push wasn't delivering (`!online || !isDelivering`) — and
on **iOS without APNs** `isDelivering` is always false, so the scheduler fired **even when online**
and answered via the offline endpoint → mislabelled.
**Fix:** now that `GET /wakefulness/pending` gives a proper online delivery path (answers via
`/respond` → recorded Online), gate the local scheduler on **`!online`** only. So: online → push or
the pending-poll (`/respond`, Online); offline → local scheduler (`/wakefulness/offline`, Offline).
One-liner in `home_screen._pollBackend`. Added debug logs in `wakefulness_provider._report`
(`[wakefulness] answer via ONLINE/OFFLINE endpoint …`) to make the path visible.
**157 tests pass · analyze clean.** Not committed.
> Note: current test shift returns empty `schedule: []` for both wakefulness + photos, so no
> scheduled checks fire at all (online or offline) until the backend populates them.

---

## 2026-07-22 — On-screen sync indicator (verify offline flush untethered) + flush debug logs

**Why:** offline can't be tested in **debug** — killing Wi-Fi/data drops the laptop's VM-Service
tether and the debug app dies. So verification has to work on a **standalone** build with no logs.

**Added an on-device sync indicator (release-safe):**
- `OfflineQueueDb.totalPending()` — count of all buffered rows (GPS + wakefulness + photos).
- `pendingSyncProvider` (`PendingSyncNotifier`) — refreshed by the home poll + pull-to-refresh.
- Home screen: a `_SyncStatusChip` in the content — offline → "N items saved offline — will upload
  when online"; online → "Syncing N…" with a spinner. When the backlog drains to 0, a snackbar
  "Offline data synced (N items)" confirms it. Lets you watch offline→reconnect flush **with no
  laptop**: go offline (chip counts up) → reconnect (chip → syncing → snackbar → gone).
- **Debug-only flush logs** in `SyncFlushService` (`[sync] … → POST <endpoint> → success/retry/drop`
  + the base URL) so a *tethered* debug run also shows each POST hitting the branded host.

**How to verify untethered:** `flutter run --release --dart-define-from-file=config/prod.json` once
over cable → unplug → toggle Airplane mode. Confirm on the chip/snackbar (and cross-check the
dashboard "Offline" tag). Release strips the `[sync]` logs — the chip/snackbar is the on-device
signal.

**157 tests pass · analyze clean.** Not committed.

---

## 2026-07-21 (cont. 3) — Offline welfare/photo check notifications (were never scheduled)

**Bug:** the guard got **no offline notifications** for welfare/photo checks. Root cause: the app
never scheduled any — `NotificationService` only had the shift-end reminder + photo-review toast.
The offline checks were only surfaced by the in-app 20s poll (`checkSchedule`), which requires the
app **foregrounded**. Per `FLUTTER_HANDOFF_WAKEFULNESS_OFFLINE_AND_UX.md` §0, offline prompts must be
**local notifications the app schedules at shift start** (a push can't reach an offline device).

**Fix — schedule OS-level local notifications at each schedule mark:**
- `NotificationService`: new `scheduleWakefulnessChecks(marks)` / `schedulePhotoChecks(marks)` +
  `cancelWakefulnessChecks()` / `cancelPhotoChecks()`. One notification per **future** mark (id
  ranges 3000+/4000+, capped 64), replaced on re-provision. Tries an **exact** alarm, falls back to
  **inexact** if the OS withholds exact-alarm permission. Entire path is best-effort (never throws —
  a notification failure must not break shift start).
- Wired in `WakefulnessScheduleNotifier` + `PhotoScheduleNotifier`: schedule on `provisionFromJson`
  (shift start) and `restore` (relaunch/re-arm); cancel on `clear` (shift end / reconcile). Also
  cancelled explicitly in `AuthNotifier.signOut` (invalidate disposes the notifiers without running
  `clear`).
- The OS fires these even backgrounded/killed/offline; tapping one opens the app and the existing
  scheduler raises the challenge/capture for the due mark.

**Notes / tradeoffs:**
- **Timing:** `SCHEDULE_EXACT_ALARM` isn't declared (Play-Store exact-alarm policy), so scheduling
  uses the **inexact** fallback → a reminder can fire a little late in Doze. If exact welfare timing
  is needed, add `USE_EXACT_ALARM`/`SCHEDULE_EXACT_ALARM` to the Android manifest.
- **Online double-notify:** when online **and** FCM is delivering, the server also pushes at the same
  mark → the guard may see two banners (the in-app challenge is still deduped by `check_id`, so only
  one prompt appears). Not an issue on iOS (no APNs) or offline. Follow-up if annoying: cancel the
  local mark-notification once its push/challenge is handled.
- **iOS:** local notifications need the notification permission (already requested at shift start);
  iOS caps ~64 pending — fine for a realistic shift's mark count.

**157 tests pass · analyze clean.** Not committed. Open: on-device verify a scheduled reminder fires
while backgrounded/offline and opens the right check.

---

## 2026-07-21 (cont. 2) — Backend confirmed live; parser pinned to confirmed envelope

Jerry replied (`docs/BACKEND_REPLY_2026-07-21.md`) to `docs/BACKEND_ASKS_2026-07-21.md`: **all four
items confirmed live on the branded host.** Reconciled the app to the confirmed contract:
- `GET /wakefulness/pending` envelope pinned to **`data.challenges[]`** (empty array = nothing
  pending; no `pending:false` field). `extractPendingWakefulness` already handled it; added 2 tests
  using Jerry's exact payload, and tightened the doc comment. **157 tests pass · analyze clean.**
- `POST /wakefulness/offline`: duplicates → 200 `ALREADY_RESOLVED`, wrong code → 200
  `{result:FAILED, reason:OFFLINE_CODE_MISMATCH}` — both non-4xx, so our "any 200 = dequeue" flush
  is correct. Success body is enveloped (`data.result`/`data.reason`); we don't parse it (a no-throw
  200 is enough), so no change needed.
- Code padding: **no backend change** — the wire already carries `"0472"`; the lost zero was an
  app-side parse, covered by our zero-pad-on-receipt (`_normalizeCode`). Verified `push_router`
  keeps `code` a string (`s('code')`), so nothing drops it upstream now.
- **App side is contract-complete.** Only the on-device verification pass remains (offline answer →
  reconnect → "Offline" on the timeline/Welfare Report; pending-poll raising the sheet on iOS).

---

## 2026-07-21 (cont.) — Field fixes: wakefulness digit entry, location-off gate, pull-to-refresh

Three device-reported issues, all app-side. **154 tests pass (was 151) · `flutter analyze` clean.**
Not committed.

### 🐞 Wakefulness code could only be partially entered
Guard reported a **4-digit** code in the notification but the app let them type only 3 (and OK
never enabled). Root cause: the server push `data.code` dropped a leading zero (`472` where the
tray body shows `0472`), against a fixed 4-cell pin — the 4th cell could never fill.
**Codes are always exactly 4 digits (online + offline)**, so the fix **normalises every incoming
code to 4**: `WakefulnessNotifier._normalizeCode` strips non-digits and zero-pads to
`kWakefulnessDigits` (4), applied in `trigger()` and `triggerLocal()`. The pin stays fixed at 4 —
a 3-digit code is **never** shown or accepted; `472` is displayed and matched as `0472`.
(Earlier this session I'd made the pin adaptive to the code length; reverted — we only ever want 4.)
- Files: `lib/providers/wakefulness_provider.dart`, `lib/overlays/wakefulness_overlay.dart`.
- ⚠️ **Backend ask:** send the wakefulness push `data.code` already **zero-padded to 4** so it
  matches the notification body.

### 📍 Location turned off mid-shift no longer leaves the app blind
When the guard switches off Location Services (Control Center / swipe-down), GPS silently produces
nothing but the app kept working. Now a **non-dismissible full-screen gate blocks the app until
location is switched back on**.
- New `locationServiceEnabledProvider` (`lib/services/gps_service.dart`): seeded optimistically
  (`true`, no cold-start flash), corrected by `Geolocator.isLocationServiceEnabled()`, kept live by
  `getServiceStatusStream()` (instant on a foreground toggle), and re-checked each 20s poll (catches
  a toggle made while backgrounded).
- New `lib/overlays/location_required_overlay.dart` — `PopScope(canPop:false)`, "Open Location
  Settings" (`Geolocator.openLocationSettings()`), auto-clears when location returns. Shown from
  `home_screen` when `!locationOn`. Distinct from the existing **permission-denied** banner (this is
  the OS master toggle, not app authorisation).

### 🔄 Pull-to-refresh on the home screen
Both the active-shift scroll and the pre-shift scroll are wrapped in `RefreshIndicator` →
`_pollBackend` (with `AlwaysScrollableScrollPhysics`), so a guard can force a shift-state / check /
location refresh instead of waiting for the 20s tick. Files: `lib/screens/home/home_screen.dart`.

### Tests / open
- +3 regression tests (`test/providers/wakefulness_code_length_test.dart`: 3/4/6-digit entry).
- Open: on-device verify — Control-Center location toggle raises/clears the gate; a short (3-digit)
  code is enterable + submittable; pull-to-refresh feels right on both screens.

---

## 2026-07-21 — Offline wakefulness flush endpoint + wakefulness/pending poll (Jerry's 2026-07-06 handoff)

Implemented the two backend deltas from `docs/FLUTTER_HANDOFF_WAKEFULNESS_OFFLINE_AND_UX.md`
(+ the matching bits of the updated `FLUTTER_API_GUIDE (1).md`). Reviewed the API guide against
the code first: the **early-end approval flow, checked_in/missed statuses, photo schedule, reviews,
push routing were already implemented** — the only real gaps were the two wakefulness items below.
**151 tests pass (was 143) · `flutter analyze` clean.** Not committed.

### 🔴 FIXED — offline wakefulness answers were being silently dropped
A schedule-fired (TOTP) challenge has **no server `check_id`** — the app invents `totp-<window>`.
Both `submitOffline` and the online `respond(isOffline:true)` path were POSTing that synthetic id to
`/wakefulness/{checkId}/respond`, which **404s on the real backend** → `classifyFlush` treats 4xx as
terminal → **the answer was dropped** (exactly the gap Jerry reported). Now routed to the new
**`POST /shifts/{id}/wakefulness/offline`** (`{window_reference, code, scheduled_at?, responded_at?}`),
which the server materialises + records (pass or fail). Idempotent per (shift, window_reference) →
`ALREADY_RESOLVED` on a 200.
- `api_config.dart`: `wakefulnessOffline(shiftId)` + `wakefulnessPending(shiftId)`.
- `wakefulness_service.dart`: `submitOffline` now targets the offline endpoint keyed on **shiftId**
  (not checkId) with the doc's body; **`respond()` cleaned up** to online-only (dropped the
  `isOffline`/`windowReference` workaround params).
- `wakefulness_provider.dart` `_report`: a schedule-fired challenge (`isOffline`, has
  `windowReference`) **never** calls `respond`. **Online → POST the offline endpoint immediately;
  offline/failed → enqueue for the reconnect flush.** Real online (push/pending-poll) challenges with
  a real `check_id` keep `respond`. Added `scheduledAt` to `WakefulnessState`/`triggerLocal` so the
  flush sends the exact schedule mark.
- `offline_queue_db.dart`: `WakefulnessQueue` gained **`shiftId`** (so a flush survives the shift
  ending mid-backlog) + **`scheduledAt`**. `schemaVersion 1→2` with a **destructive** `onUpgrade`
  (drop+recreate all tables) — the queue is a session-scoped, droppable buffer, so losing it is safe.
  Regenerated `offline_queue_db.g.dart` (`dart run build_runner build`, native-assets on).
- `sync_flush_service._flushWakefulness`: flushes via `submitOffline(shiftId: row.shiftId, …,
  scheduledAt: row.scheduledAt)`.

### 🟠 NEW — `GET /shifts/{id}/wakefulness/pending` poll (push-miss fallback)
Twin of the photo pending-poll — makes the in-app code-entry sheet reliable when the FCM push is
missed (notably **iOS with no APNs**). Added to `home_screen._pollBackend` (online + idle branch):
tolerant `extractPendingWakefulness()` parser → `confirmReceived()` (fire-and-forget) → `trigger()`.
No collision with the offline TOTP scheduler: that uses `totp-<win>` ids and only runs when
offline/push-down; this surfaces server-initiated challenges (real uuids); the notifier's `check_id`
dedup covers the overlap. A `DioException` (older backend without the endpoint) is swallowed.

### §3 foreground UX — already covered
The foreground `onMessage` handler already routes wakefulness→`trigger`→overlay and
photo→`setPending`→PhotoScreen via `ref.listen`, deduped by id — so a foregrounded push already
raises the sheet in-app. The missing reliability piece was the pending-poll above (done). No new code.

### Tests
- `wakefulness_offline_enqueue_test.dart` rewritten: offline→queued (with shiftId/scheduledAt),
  **online→recorded immediately, not queued**, online-push→uses `/respond`.
- `wakefulness_verdict_test.dart`: `respond` override signature updated (online-only).
- `sync_flush_service_test.dart`: wakefulness flush now asserts the **offline endpoint path + body**
  (window_reference/code/scheduled_at); `_seedWake` carries shiftId/scheduledAt.
- `offline_queue_db_test.dart`: `enqueueWakefulness` seed carries shiftId.
- NEW `extract_pending_wakefulness_test.dart` (7 cases: shapes, empty, missing code, expires_at
  back-compute).

### Open / next
- 🔴 **On-device verification** (the only thing left): offline shift → answer a TOTP challenge →
  reconnect → confirm it lands on the timeline/report tagged "Offline"; and a missed push → the
  `/wakefulness/pending` poll raises the sheet in-app on iOS.
- 🟡 **Mock backend** (`mock-backend/server.js`) does **not** serve `/wakefulness/offline` or
  `/wakefulness/pending` — the home poll swallows the 404, so it's harmless locally, but add them if
  you want to exercise these flows against the mock.
- Carry-over: iOS APNs, EXIF↔NTP ≤30s, cert pinning, obfuscation (unchanged from Phase 7).

---

## 2026-06-30 (cont. 2) — Phase 7 offline sync: CODE-COMPLETE + docs consolidated

Phase 7 (offline capture → flush-on-reconnect) is now **feature-complete in code and unit-tested**
across all three capabilities. Verified against **all three backend contracts** (the Flutter
responsibilities doc, `PHASE_7_SYNC_INTEGRITY.md`, and the API guide) — every §5 Definition-of-Done
item that's implementable in code is done; the retry table matches `classifyFlush` row-for-row.
**143 tests pass · `flutter analyze` clean.** 8 commits on `saduka` (`110f64e`…`e18a8dd`).

**The single source of truth for what's left is now `guardmonitor/docs/PHASE_7_REMAINING_WORK.md`.**
Short version — everything remaining is **on-device / dashboard verification** (not new code):
- 🔴 On-device: SQLCipher opens on Android+iOS (native-assets build); force a shift offline >60 s →
  reconnect → confirm GPS batch flush + the dashboard "offline band" (with Jerry); offline
  wakefulness replay + offline scheduled photo land.
- 🔴 EXIF↔NTP ≤30 s on a real camera capture (stamp EXIF ourselves if the plugin doesn't).
- 🟡 Confirm `elapsed_seconds:0` projection with Jerry; honor `max_photos_per_capture`; (pre-existing)
  iOS APNs, Universal Links, cert pinning, obfuscation, prod-host confirm.

**Docs touched this session:** `PHASE_7_IMPLEMENTATION_PLAN.md` (status/DoD),
`PHASE_7_OFFLINE_PHOTO_TRIGGER_QUESTION.md` (RESOLVED → Option A),
`PHASE_7_REMAINING_WORK.md` (new), this HANDOFF, and `CLAUDE.md` (architecture map + test count +
an Offline Sync subsection). Full per-stage detail in the two entries below.

---

## 2026-06-30 (cont.) — Phase 7 Stage 7: offline-photo trigger (schedule) wired

Backend answered the one open Phase 7 question (`PHASE_7_OFFLINE_PHOTO_TRIGGER_QUESTION.md`):
**Option A — a photo schedule**, analogous to wakefulness TOTP. Built the trigger + the offline
capture UI. **143 tests pass · analyze clean.**

- **`PhotoProvisioning` + `PhotoScheduleNotifier`** (`photo_schedule_provider.dart`): parse/persist
  the new `photos` block from `POST /shifts/{id}/start` (`schedule`, `response_seconds`,
  `offline_nonce_ttl_minutes`, `max_photos_per_capture`); restore on relaunch; clear on
  end/reconcile/sign-out. `shift_service.startShift` now returns `photos` too.
- **Fires only when OFFLINE** (online marks arrive as a server `PHOTO_REQUEST` — one schedule,
  no double-fire). Run from the active-shift home poll next to the wakefulness scheduler.
- **⛔ Clock-tamper hardening (per request):** due-ness is judged against
  **`TimeAnchorService.trustedNow()`** — the NTP anchor projected by a monotonic `Stopwatch`, not
  `DateTime.now()`. Changing the device clock can't dodge or force a scheduled photo. The capture
  itself already uses the same NTP projection for `ntp_reference`/`captured_at`.
- **`PhotoScreen.scheduled()`** — reuses the camera/review widgets; **no countdown** (shows an
  "Offline — saved and uploaded when you reconnect" hint); on submit calls
  `OfflinePhotoService.enqueueCapture` (draw pool nonce → sign → persist → queue) and pops with a
  "Saved" snackbar. The **online request path is byte-for-byte unchanged** (guarded by the
  `scheduled` flag).
- Tests: `PhotoProvisioning.fromJson` (+defaults/empty), `dueMark` decision incl. **back-dated-clock
  tamper case**, and `checkSchedule` gating (offline-fires / online-suppresses / no-double-fire).

Phase 7 is now **feature-complete on-device**. Remaining = **on-device verification** (Android +
iOS native-assets build, SQLCipher opens, force-offline a shift → reconnect → dashboard offline
band with Jerry) + the **EXIF/NTP ≤30s** check on a real camera capture.

---

## 2026-06-30 — Phase 7 Offline Sync (Stages 1–6 built; one trigger open)

Built the **offline capture → flush-on-reconnect** subsystem per
`guardmonitor/docs/PHASE_7_IMPLEMENTATION_PLAN.md`. Server side was already done + idempotent;
this is all the on-device half. **131 tests pass · analyze clean.** Committed in 6 staged commits
on branch `saduka`.

**Storage decision (changed from the plan's literal wiring):** Drift + **SQLCipher**, but via
sqlite3 3.x **build hooks** (`hooks.user_defines.sqlite3.source: sqlcipher` in pubspec) — the old
`sqlcipher_flutter_libs` / `open.overrideFor` path was removed in sqlite3 3.0, and the analyzer-8
toolchain forces sqlite3 3.x. Requires `flutter config --enable-native-assets` (done on this
machine). **Verified on host: `PRAGMA cipher_version` → 4.16.0 community** (guard test
`test/data/cipher_probe_test.dart`).

What landed:
- **`OfflineQueueDb`** (`lib/data/offline_queue_db.dart`): encrypted Drift DB, 4 tables
  (GpsQueue/WakefulnessQueue/PhotoQueue/NoncePool) + typed CRUD w/ backoff gate. Cipher key in
  secure storage (`db_cipher_key`), wiped on sign-out; stale/undecryptable file dropped on open.
- **`sync_retry.dart`**: `classifyFlush()` = the §4 retry table (success/retry/drop;
  ALREADY_RESOLVED & NONCE_ALREADY_USED = success), `backoffDelay()` exp+jitter cap 5m, max 12.
- **`SyncFlushService`**: single-flight, connectivity false→true trigger, ordered
  wakefulness→GPS→photos, best-effort. Started on sign-in (drains backlog + on each reconnect),
  app-resume flush, stopped + `clearAll()` + photo-file purge on sign-out.
- **GPS**: a ping the live POST can't deliver is queued (was dropped) → batch `pings[]` flush,
  chunked ≤200.
- **Wakefulness**: an offline TOTP answer that can't reach the server is queued (window_reference
  preserved) → replayed via `submitOffline`.
- **Photos**: `NoncePoolService` (prefetch/draw OFFLINE_POOL nonces), `TimeAnchorService` (NTP
  anchor projected to shutter via monotonic clock — tamper-proof, EXIF-aligned),
  `OfflinePhotoService.enqueueCapture` (sign + persist + queue), `PhotoService.submitOfflinePhotos`
  (re-sends stored signature **verbatim**). Home poll tops up pool + anchor while online.

⚠️ **OPEN — the offline-photo CAPTURE TRIGGER is not wired.** All photos today are server-initiated
(online PHOTO_REQUEST, 90s request nonce). Offline photos need a pool-nonce, no-request_id capture —
but there's no offline trigger in the app, and whether a pool-nonce photo should *answer* a missed
PHOTO_REQUEST vs be a *standalone scheduled* offline capture is a **product/backend decision**. The
machinery is complete and tested for whichever path; only the UI entry point + product rule remain.

Other open items (unchanged from before): confirm the new HTTPS host serves the API on-device;
device-verify the dashboard "offline band" appears after a GPS backlog flush (with Jerry); iOS APNs
(FCM) still pending.

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
