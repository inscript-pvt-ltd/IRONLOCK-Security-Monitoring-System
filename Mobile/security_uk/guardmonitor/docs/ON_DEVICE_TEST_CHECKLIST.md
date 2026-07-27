# On-device test checklist — IronLock Guard Monitor

**Date:** 2026-07-23
**Purpose:** turn "the code should work" into "we watched it work." This is the sequence to run on a
**real phone** before trusting the current build in the field. It targets the whole flow *and* every
recent change (the 5 bug fixes + the challenge queue + the photo notification).

---

## Preconditions (read first — most "it didn't fire" reports are one of these)

- [ ] **Use a ~2-hour test shift.** Per Jerry, a 30-min shift has an **empty** schedule by design
      (first welfare mark = start + 30–45 min, first photo = start + 50–70 min). Short shift → nothing
      fires. Window **> 70 min** guarantees at least one of each.
- [ ] **Build in RELEASE, not debug.** Offline sync **cannot** be tested over USB-debug — killing
      Wi-Fi drops the VM Service and the debug app dies. Use `flutter run --release -d <id>` or a
      TestFlight build.
- [ ] `flutter config --enable-native-assets` was run once (SQLCipher offline queue).
- [ ] Grant **camera + location (Always)** and **notifications** when prompted.
- [ ] Have the **dashboard** open to trigger manual checks and read results (Online/Offline tags).
- [ ] Note the device time is correct (the app uses NTP anchoring, but a wildly-wrong clock is worth
      ruling out).
- [ ] **iOS push:** if the APNs `.p8` isn't uploaded to Firebase yet, iOS has **no push** — the
      wakefulness/photo checks come via the foreground poll only. That's expected; test push
      separately once APNs is live (last section).

---

## 1. Login + shift start

- [ ] Log in → lands on Home with the correct guard name/site.
- [ ] START enables at the right time (from 15 min before scheduled start).
- [ ] Tap START → shift goes active, elapsed timer runs, END button shows.
- [ ] **Dashboard:** the shift shows `active` with an `actual_start`.
- [ ] *(Verifies Fix #4 caveat)* Within seconds of start — **before** the first 20s poll — the
      offline nonce pool is primed. Hard to see directly; validated indirectly by test **6b** below
      (go offline immediately and still capture a photo).

## 2. GPS tracking (online + offline buffer)

- [ ] Zone card updates within ~15–30s (inside/outside/awaiting).
- [ ] Lock the screen / background the app for ~1 min → dashboard still receives pings (background
      location).
- [ ] **Go offline** (airplane mode) for ~2 min while moving a little → the on-screen **sync chip**
      shows a rising pending count.
- [ ] **Come back online** → chip drains to zero and a "synced" snackbar appears; dashboard shows the
      buffered pings arrived as a batch.

## 3. Wakefulness — ONLINE (foreground poll path)

> With no iOS push yet, this arrives via `/wakefulness/pending` while the app is foreground.

- [ ] Trigger a **manual** welfare check from the dashboard (or wait for a scheduled online mark).
- [ ] Within ~20s the code overlay appears with a **4-digit** code.
- [ ] Enter the code → ✓ confirmed.
- [ ] **Dashboard:** the check is tagged **Online** (NOT Offline), response time recorded.
      *(Verifies the clean `/respond` body — the earlier mislabel fix.)*
- [ ] Enter a **wrong** code → fails cleanly, "supervisor alerted" copy, overlay closes.

## 4. Wakefulness — OFFLINE (TOTP)

- [ ] Go **offline** and wait for a scheduled welfare mark (or keep a ~2h shift running so one lands).
- [ ] The code overlay appears offline (chip in the overlay reads **OFFLINE**).
- [ ] Enter the code → accepted locally.
- [ ] **Come back online** → the answer flushes; **dashboard** shows the check tagged **Offline**,
      backfilled at the right time.
- [ ] Force a couple of offline answers, then reconnect → all flush, none duplicated.

## 5. Photo — ONLINE request (+ the new notification)

- [ ] Trigger a **manual** photo request from the dashboard (app foreground).
- [ ] *(Verifies Fix #1)* A **"Photo required"** notification appears **and** the camera screen opens.
- [ ] Capture 1–5 photos → Use Photo → uploads → VALIDATED/FLAGGED shows.
- [ ] **Dashboard:** the photo attempt is recorded.
- [ ] **Repeat, but let it EXPIRE** (don't capture): the screen shows "window expired — closing…" and
      **auto-closes**; it does **not** re-open on the next poll/pull-to-refresh.
      *(Verifies the expired-loop fix.)*

## 6. Photo — OFFLINE scheduled capture

- [ ] **6a (normal):** go offline, wait for a photo mark → camera opens in scheduled mode → capture →
      "Saved — will upload when you're back online." → reconnect → **dashboard** shows the photo
      stored (Jerry's fix will tag it Offline once deployed).
- [ ] **6b (the Fix #4-caveat test):** START a shift and **immediately go offline** (within the first
      ~15s, before the first poll). Force an offline photo capture. It should still **queue
      successfully** (pool was primed at start) — NOT "Couldn't save the photo offline."
- [ ] **6c (Fix #5):** if you can force a dry pool (offline for a very long time so nonces expire),
      a capture that can't queue shows the "reconnect and try again" message **and** the end-shift
      summary later counts it as a miss.

## 7. Simultaneous wake + photo (the challenge queue — Fix #3)

- [ ] From the dashboard, fire a **manual welfare check AND a manual photo request at the same time**.
- [ ] **Expected:** one presents first (e.g. the code overlay), you complete it, **then** the other
      opens. Neither flashes-and-vanishes; neither leaves a dark scrim.
      *(This is the core race fix — the old behavior was one modal killing the other.)*
- [ ] Try the reverse order / repeat a few times — it should always serialize, never stack.

## 8. Old / stale notification handling (the scrim-lock — Fix #2a)

> Only reproducible once iOS push is live, or on Android with FCM. Skip if no push yet.

- [ ] Let a wakefulness push arrive, **don't answer**, wait until it's expired, then **tap the old
      notification**.
- [ ] **Expected:** it does **not** open a dead challenge under a frozen dark scrim. Either nothing
      opens (stale challenge dropped) or a live one opens normally. The screen is never stuck with
      dead buttons.

## 9. Location turned off mid-shift

- [ ] Mid-shift, turn **off** the OS location master toggle (Control Centre / Settings).
- [ ] **Expected:** a full-screen blocker appears ("location required") with an "Open Settings"
      button; the app is unusable until location is back on.
- [ ] Turn location back on → blocker dismisses, tracking resumes.

## 10. Photo upload failure handling (Fix #3 — no double-count)

- [ ] During an **online** photo upload, drop the network **mid-upload** (airplane mode right after
      tapping Use Photo).
- [ ] **Expected:** "Upload failed — check your connection and tap Try Again." The attempt is **not**
      silently counted as a miss.
- [ ] Restore network → **Try Again** → uploads → success.
- [ ] **End-shift summary** counts this as **one** completed photo, not a miss + a pass.

## 11. Reconnect flush under a flaky link (Fix #4 — early-stop)

- [ ] Build up a backlog (offline for a while: pings + an offline wakefulness answer + an offline
      photo).
- [ ] Reconnect on a **weak/flaky** connection.
- [ ] **Expected:** the flush drains in order (wakefulness → GPS → photos); if the link drops mid-flush
      it stops and resumes on the next reconnect — it should **not** spin hammering every row. Chip
      eventually reaches zero.

## 12. App-kill / relaunch mid-shift

- [ ] Force-quit the app mid-shift, relaunch.
- [ ] **Expected:** it resumes the active shift (END button, elapsed timer), GPS restarts, and the
      wakefulness/photo **schedules re-arm** (a due offline check still fires).

## 13. Shift end + summary

- [ ] End the shift (normal, after scheduled end).
- [ ] The summary counts match what you actually did — welfare checks (pass/miss), photos
      (completed/missed) — with no obvious double-counting.
- [ ] **Dashboard:** the shift closes cleanly.

## 14. Sign-out hygiene

- [ ] Sign out mid-ish session.
- [ ] **Expected:** GPS notification disappears, scheduled reminders cancelled, and signing back in as
      a (different) guard starts clean — no leftover pending items, no old challenges.

---

## Once iOS push (APNs) is live — re-run these

- [ ] Backgrounded/locked iPhone: a manual welfare check + photo request each raise a **heads-up
      notification**; tapping opens the right screen.
- [ ] **Double-notify watch:** a *scheduled* check shouldn't fire both the local reminder AND the
      server push. If it doubles, that's the follow-up noted in `IOS_SIGNING_AND_APNS_SETUP.md`.
- [ ] `PushMessaging.isDelivering == true` (the local scheduler steps aside; online answers land
      **Online**).

---

## If something fails — capture this

For any failed step, grab:

- The **exact step number** + what you saw vs. expected.
- **Screen recording** if it's a UI/timing issue (the challenge-queue and scrim ones especially).
- The **two IDs** (check_id / request_id) from the dashboard row if it's a tagging/duplication issue —
  Jerry asked for these on the rare reconnect-race.
- Whether the device was **online or offline** at that moment, and roughly how long into the shift.
- For a crash: the device log (`flutter logs`, or Console.app / Xcode devices window for iOS).

Send those and I can pinpoint whether it's app-side, backend, or a test-setup issue.
