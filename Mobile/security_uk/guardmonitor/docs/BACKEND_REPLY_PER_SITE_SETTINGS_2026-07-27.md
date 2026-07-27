# Flutter Reply — Per-Site Verification Settings (empty schedules)

**From:** the Flutter app developer.
**To:** backend/dashboard (Jerry).
**Date:** 2026-07-27.
**Re:** your `FLUTTER_HANDOFF — Per-Site Verification Settings (schedules can now be empty)`.

---

## TL;DR

Audited the app against all three action items + §5. **The app already handles empty schedules
correctly** — an off check fires nothing, and manual pushes still work. One small efficiency fix
made (skip the nonce prefetch when photos are off). Your three questions answered at the bottom.
No breaking issues.

---

## §3.1 — Empty schedule ⇒ fire nothing ✅ already correct

- **Photo:** `PhotoProvisioning.fromJson` returns **null** when `schedule` is `[]`, so the photo
  schedule provider is **not armed** → no offline timers, no local notifications, no "next check"
  UI. Confirmed by an existing test (`{'schedule': []}` → null).
- **Wakefulness:** parses to a provisioning with **zero marks**, so `checkSchedule` iterates
  nothing and `scheduleWakefulnessChecks([])` registers zero notifications → nothing fires.
- The two are independent providers, so all four on/off combinations work. Added a test for the
  wakefulness-off case (`test/providers/wakefulness_provisioning_test.dart`).

## §3.2 — `totp_seed` is NOT the switch ✅ we branch on the schedule

Important detail on our side, so you know we're safe: our wakefulness parser *does* still key
"did we get a provisioning at all" on the seed (a seed-less payload = the local mock, which falls
back to a `/welfare/pending` poll). **But that does not fire any check.** With wakefulness OFF you
send seed + `schedule: []`, so we parse a provisioning with **empty marks** and `checkSchedule`
fires nothing. So:
- We never treat "seed present" as "wakefulness on" in a way that raises a challenge.
- Bonus: because a seed-bearing (but empty) provisioning still counts as "armed", we also
  **suppress the legacy `/welfare/pending` fallback poll** for an off site — which is what you'd
  want. Net behaviour is exactly your contract.

## §3.3 — Skip nonce prefetch when photos off ✅ fixed

`POST /shifts/{id}/nonces/prefetch` was being called unconditionally. Now gated on "photo
schedule armed": if `photos.schedule` is empty we **skip the prefetch** (at shift start and on the
20s online top-up). A manual/online photo request carries its **own** nonce (not a pool nonce), so
skipping the pool prefetch never blocks a supervisor-triggered capture. (The one-shot prime on an
app-relaunch resume is left unconditional — it races the async schedule-restore, and a single
wasted prefetch there is harmless per your note.)

## §3.4 — Still handle pushes when schedule empty ✅ already correct

We do **not** gate push / pending-poll handling on the schedule. The photo pending-poll
(`GET /shifts/{id}/photos/pending`), the wakefulness pending-poll
(`GET /shifts/{id}/wakefulness/pending`), and the FCM push router all run regardless of whether the
schedule is empty — a `TYPE_MANUAL` request with a `request_id`/`check_id` goes straight down our
online path and shows the prompt. No "empty schedule ⇒ drop pushes" shortcut exists.

## §5 — Variable gaps ⚠️ mostly fine, one honest limit

- **No hard-coded 50–70 / 30–45 assumptions** drive behaviour — the app reads the marks verbatim
  and fires against them; the old ranges only ever lived in comments.
- **iOS 64-notification cap: already handled.** We cap scheduled local notifications at **31 per
  type**, so photo (≤31) + wakefulness (≤31) + the shift-end reminder (1) = **≤63 < 64**. A schedule
  with more than 31 future marks gets its first 31 as OS notifications; the rest are driven by the
  foreground 20s poll. So very short gaps won't blow the iOS budget, but marks beyond 31 rely on the
  app being foregrounded (see Q1 caveat).
- **Offline dispatch lag is the real constraint** — see Q1.

---

## Answers to your three questions

### Q1 — Worst-case lag between a scheduled mark and the actual offline capture/prompt

Honest answer: **it's bounded by a fixed 15-minute fire window, but the *actual* shutter/prompt is
guard-response latency, which I can't drive below human reaction time.**

- Offline, a scheduled photo/wakefulness prompt is opened by the **20s foreground poll** if the app
  is open, **or** by the guard tapping the **local notification** we scheduled at the mark. The
  camera/challenge **cannot** fire in the background — it needs the app foregrounded.
- Our `dueMark` only fires a mark within **15 min** of it (`fireWindowMinutes = 15`); older marks
  are skipped (→ you record them missed). So the worst-case *attributable* lag is ~15 min, and in
  practice it's the ~7–10 min guard-response latency we measured on 24 July.
- **Recommendation for admins:** keep the **minimum gap ≥ ~20 min** for offline reliability. Below
  that, a late offline capture can spill into the next slot (you match "latest mark ≤ capture+90s"),
  and marks beyond the 31st don't get a pre-scheduled notification. **Sub-15-min gaps are not safe
  for offline** — the capture is inherently guard-driven.
- If you need tighter gaps for **online-only** sites, that's fine — online delivery is push-driven
  and prompt. The lag caveat is purely an **offline** limitation.
- I can make `fireWindowMinutes` **adaptive** (e.g. `min(15 min, gap/2)`) if you want tighter sites
  to fail-fast into "missed" rather than fire late — tell me your intended minimum gap and I'll tune
  it. Not changed yet (needs your target).

### Q2 — Does the app branch on `totp_seed` presence anywhere?

Yes, but safely (see §3.2): the seed decides "provision at all vs fall back to the mock poll," **not**
"is wakefulness on." With your off-config (seed + empty schedule) we provision with zero marks and
fire nothing, and we suppress the fallback poll too. No change needed; locked in with a test.

### Q3 — Per-app local-notification limits before admins allow very short gaps

Yes: **iOS caps pending local notifications at 64 per app.** We already cap at **31 per type**
(photo + wakefulness) + 1 shift-end = ≤63, so we won't hit the ceiling. The trade-off: with a very
short gap producing >31 marks, only the **first 31** per type get OS-level notifications; the rest
fire via the foreground poll. So for short-gap sites, backgrounded/offline delivery is reliable only
for the first ~31 checks of each type. Another reason to advise a sensible minimum gap.

---

## Changes made this pass

- `shift_provider.dart` + `home_screen.dart`: gate the offline-photo **nonce prefetch** on
  "photo schedule armed" (§3.3).
- `test/providers/wakefulness_provisioning_test.dart`: new — locks in wakefulness on/off by
  schedule, not seed (§3.2).
- Unrelated but noticed & fixed while here: the package `name` in `pubspec.yaml` had been changed to
  `IronLock` (invalid — Dart package names must be lowercase, and it broke every `package:guardmonitor/`
  import); reverted to `guardmonitor`.
- `flutter analyze` clean · **195 tests pass**.

**Test matrix:** happy to have you configure dev rows **2, 3, 4, 5, 6** — the ones exercising
photo-off, wakefulness-off, both-off, manual-push-with-empty-schedule, and the tight-gap (min=5)
case so we can confirm the offline lag behaviour end-to-end on a device.
