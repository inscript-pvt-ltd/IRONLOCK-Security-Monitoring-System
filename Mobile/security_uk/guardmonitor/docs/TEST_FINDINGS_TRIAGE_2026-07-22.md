# Offline-sync test findings — triage & ownership (2026-07-22)

**Author:** backend/dashboard (Jerry)
**Context:** live device test of the Phase 7 offline path (wakefulness + photo, online + offline,
manual + scheduled) against a real shift. This is the internal master record — every finding, who
owns it, the root cause, the code it lives in, and the planned fix. The Flutter-facing subset is in
`FLUTTER_APP_TASKS_2026-07-22.md`.

**Tested shift (reference):** started 22 Jul 6:16 PM; two offline windows (~6:21–6:51, ~6:52–7:26);
offline wakefulness confirmed 6:52; reconnect 7:26; scheduled online wake+photo both at 7:30:02;
manual wake+photo burst 7:32–7:43; offline photo captured ~8:24, flushed 8:25:15.

---

## Verdict at a glance

| # | Symptom | Owner | Severity | Status |
|---|---------|-------|----------|--------|
| 1  | Offline wakefulness notify + flush + Offline tag | ✅ works | — | No action |
| 2a | Online photo push silent (window opened via poll only) | Flutter | Medium | Handed off |
| 2b | Offline photo capture "connect internet and retry" | Flutter | High | Now working in test — watch |
| 3  | Reconnect fires online wake **and** photo clustered at the same second, right after the offline ones | **Backend** | High | To implement |
| 4  | Dark scrim locks the screen when tapping an old notification | Flutter | High | Handed off |
| 5  | Dual manual wake+photo — one modal kills the other | Flutter | Low/intermittent | Handed off |
| 6  | `DELAYED_UPLOAD` flag on a legitimate offline flush | **Backend** | Medium | To implement |
| 7  | Offline photo not tagged "Offline" on the shift page | **Backend** | Low | To implement |
| 8  | Scheduled wakefulness failed+alerted 16 min after the challenge | **Ops + Backend** | High | Verify cron; then implement |

**Backend queue (mine):** #3, #6, #7, #8b/8a. (Plus #2b poll-expiry filter already applied to
`PhotoController::pending`.)
**Flutter dev:** #2a, #4, #5; keep watching #2b.

---

## 1. ✅ Offline wakefulness — works end to end

Offline TOTP challenge fired on-device, guard answered offline, and the answer flushed on reconnect
and materialised correctly. Dashboard row:

> `CONFIRMED  Scheduled  Offline Offline  22 Jul 6:52 PM  22 Jul 6:52 PM  9.20s`

Mode is correctly tagged **Offline**, backfilled, response time preserved. This is the reference the
photo path should match (see #7). No action.

---

## 2a. 🟡 Online photo push is silent — FLUTTER

**Symptom:** the online photo window appeared, but with **no push notification**, whereas the
wakefulness challenge did raise a heads-up notification.

**Backend is symmetric — verified.** `PhotoPushNotifier::notify()`
(`app/Domains/Notifications/Services/PhotoPushNotifier.php:23`) fires the **same**
`FcmService::sendToDevice(token, title, body, data)` call the wakefulness challenge uses
(`WakefulnessService::pushChallenge` → `fcm->sendToDevice`, service line ~419). The photo data payload
carries `type: 'PHOTO_REQUEST'`, `request_id`, `shift_id`, `nonce_value`, `issued_at`,
`response_seconds`. Both are data-style pushes down one path.

**Conclusion:** the server sent both identically. If the wakefulness heads-up showed and the photo one
did not, the **app** isn't turning a `PHOTO_REQUEST` data message into a visible local notification
the way it does for `WAKEFULNESS_CHALLENGE`. The window still opened because the `photos/pending` poll
caught it — that's the fallback working, not the push. → Flutter.

---

## 2b. 🟡→✅ Offline photo capture "connect internet and retry" — FLUTTER (now working)

**Earlier symptom:** offline photo capture failed with "failed, connect the internet and retry."
**Latest test:** an offline photo was captured while disconnected and **flushed successfully** on
reconnect (stored as the 7th attempt). So the app's offline-pool capture + on-device queue is now
functioning.

**Design reminder (unchanged):** offline photos mirror offline wakefulness — capture against a
pre-fetched `OFFLINE_POOL` nonce, queue on-device, upload to the normal `POST /shifts/{id}/photos` on
reconnect (`PhotoVerificationService::submitPhotos` creates the request at receipt for a pool nonce;
the `nonce_value` is the anchor). No separate offline-photo endpoint. Backend was always ready.

**Status:** app-side, appears resolved in this test — keep watching on flaky signal. Backend already
tightened the sibling gap: `PhotoController::pending` now filters on the live deadline so an expired
request is never re-served (helps #4).

---

## 3. 🔴 Reconnect fires online wake + photo clustered — BACKEND (mine)

**Symptom:** after reconnect, both an online wakefulness challenge and an online photo request appeared
within minutes — at the **same second** (7:30:02), with no gap — even though the guard had just
answered the offline windows minutes earlier. Two bugs in one:

1. **Retroactive dispatch.** Both dispatchers skip an offline guard, then on reconnect fire *"the
   latest due-but-unfired mark"*:
   - `DispatchScheduledWakefulness::handle` — `app/Console/Commands/DispatchScheduledWakefulness.php:82`
   - `DispatchScheduledPhotos::handle` — `app/Console/Commands/DispatchScheduledPhotos.php:88`

   The problem: that mark came due **while the guard was offline**, and the app already answered it
   offline. Before the offline flush lands, the dispatcher sees no matching check → fires a fresh
   online challenge for a mark that's already been handled. Result: a surprise online window seconds
   after reconnect, and potentially a duplicate (one OFFLINE from flush + one ONLINE from dispatch)
   for the same mark.

2. **Same-second collision.** Both crons run every minute. On the first tick after reconnect, each
   independently fires its own latest-overdue mark → wake and photo land in the **same second**
   regardless of how far apart the original schedule placed them. The schedules are independent random
   draws (they don't normally collide); the collision is a *symptom of retroactive firing*, not the
   schedule.

**Fix (planned): a staleness guard on retroactive dispatch.**
Do **not** retroactively fire a mark that fell due during the guard's outage — the offline flush covers
it. Only fire a mark that is *genuinely current* (due within a small recent window). Concretely:

- When selecting the due-but-unfired mark, ignore any mark older than a freshness threshold
  (proposal: `max(MATCH_TOLERANCE_SECONDS, one cron interval)` past-due, i.e. a mark must be due within
  roughly the last ~60–90s to be dispatchable). Older marks are treated as "the app owned this offline"
  and skipped.
- Net behaviour: offline → skipped (already true); reconnect → fire only if a mark is due *now*, not a
  stale one from the outage. A future mark still fires normally at its time.

This single change removes both the retroactive online window **and** the same-second collision (there
is no longer a pile of overdue marks to fire at once on reconnect).

**Edge to preserve:** a purely-online guard whom the *server* missed (cron gap) should still get the
latest mark. The freshness threshold must be generous enough to cover a normal cron hiccup but tight
enough to exclude a multi-minute outage. I'll show the exact number before committing.

---

## 4. 🔴 Dark scrim locks the screen on an old notification — FLUTTER

**Symptom:** tapping an **old** wakefulness/photo notification opened the app into a challenge screen,
then a black faded layer covered it — buttons and refresh dead, screen stuck.

**Diagnosis:** a modal barrier/scrim is shown for a challenge that has no live request behind it (the
request already expired/was answered). The barrier never dismisses. Pure app UI.

**Backend already helps:** after the #2b fix, `photos/pending` no longer returns an expired request, so
the app can treat "not in pending / expired" as "close the screen." The app must validate the
request/challenge is still live on open and, if not, dismiss the barrier and show "expired." → Flutter.

---

## 5. 🟡 Dual manual wake+photo — one modal kills the other — FLUTTER

**Symptom:** when a supervisor fires manual wakefulness and photo at the same time, the app opens one,
and the other flashes and disappears after a second. Intermittent.

**Backend is correct:** both are recorded as independent first-class rows (the tested timeline shows
both the manual wake challenge and the manual photo request at 7:41–7:43). The server does not conflate
them.

**Diagnosis:** app-side modal concurrency — two challenge modals race and one dismisses the other. The
app needs to **queue** simultaneous challenges and present them one after another. → Flutter.

---

## 6. 🟠 `DELAYED_UPLOAD` on an offline flush is a false positive — BACKEND (mine)

**Symptom:** the offline photo (captured ~8:24, flushed 8:25:15) was flagged `DELAYED_UPLOAD`,
`delay_seconds: 39.97`, despite being sent seconds after the window on-device.

**Root cause:** `PhotoVerificationService::submitPhotos` — `app/.../PhotoVerificationService.php:216`:

```php
if ($captureTime->diffInSeconds($serverReceivedAt) > self::DELAYED_UPLOAD_SECONDS) { // 10s
    $flags[] = FLAG_DELAYED_UPLOAD;
}
```

This measures **capture-time → server-receipt**. For an **online** photo that's meaningful (a guard
stalling before uploading a live shot). For an **offline-pool** photo the gap *is the offline hold* —
you capture while disconnected and it can only reach the server on reconnect. So every offline flush
longer than 10s trips it. The ~40s here is exactly the hold+flush latency, not a stall.

The code already knows it's offline: `$capturedOffline = $nonce->type !== Nonce::TYPE_ONLINE;` is
computed 19 lines **below** the check, at line 235.

**Fix (planned):** hoist `$capturedOffline` above the flag block and **skip `DELAYED_UPLOAD` for
offline captures** (the offline hold is expected and legitimate). Online photos keep the flag
unchanged. Small and self-contained — no schema change.

---

## 7. 🟢 Offline photo not tagged "Offline" on the shift page — BACKEND (mine)

**Symptom:** the offline photo attempt shows only `Scheduled` + status, with no Online/Offline badge —
unlike the wakefulness table, which shows a Mode of "Offline."

**Root cause:** the data exists but the view doesn't render it.
- Stored: `captured_offline` on the evidence metadata —
  `PhotoVerificationService::store()`, `app/.../PhotoVerificationService.php:464`.
- Consumed by the **welfare report** (web + PDF) already.
- **Not rendered** on the shift page's photo attempt row —
  `resources/views/admin/shifts/timeline.blade.php:782` renders `request_type` + status only, no mode
  badge. Wakefulness has a Mode column; photos don't.

**Fix (planned):** add an Online/Offline badge to the attempt row (and the collection modal), reading
`captured_offline` off the attempt's evidence (surface it into `$photoRequests` where the row is
composed). Dashboard-only display change; no schema, no API change.

---

## Implementation plan (backend)

Three separate, reviewable edits:

1. **#6 delayed-upload false flag** — hoist `$capturedOffline`, guard the `DELAYED_UPLOAD` block.
   (Smallest; ship first.)
2. **#7 offline photo badge** — surface `captured_offline` into the attempt view-model + render the
   badge in `timeline.blade.php`.
3. **#3 staleness guard** — freshness threshold in both dispatch commands. **Behaviour change** — final
   threshold value reviewed with Jerry before commit.

Then fold the Flutter subset into `FLUTTER_APP_TASKS_2026-07-22.md` and hand off.
