# Flutter Handoff — Offline Wakefulness Flush + Verification UX

**Audience:** the Flutter app developer.
**From:** backend/dashboard (Jerry).
**Date:** 2026-07-06.
**Companion docs:** `MOBILE_API_INTEGRATION.md` (§6.2 wakefulness — the payload
source of truth), `PHASE_7_FLUTTER_OFFLINE_SYNC.md` (the on-device queue you
already own), `PHASE_5_WAKEFULNESS_PLAN.md` (the code-challenge protocol).

---

## TL;DR

Two things surfaced in testing:

1. **An offline wakefulness challenge fired on the device but nothing was
   recorded on the server.** Root cause was a backend gap — there was no endpoint
   to receive an offline wakefulness result, so `respond()` 404'd and the answer
   was dropped. **That's now fixed on the backend** (new endpoint below). Your app
   must call it on reconnect. Until it does, offline wakefulness checks never
   reach the timeline or the reports.

2. **Online, screen-off works via the notification, but there's an in-app UX
   gap:** with the screen already on and the app open, the wakefulness (and photo)
   prompt does not surface unless you tap the notification. That's app-side work
   (§3).

Nothing you already built breaks. The offline flush is **additive** and reuses the
same TOTP you already compute.

> **Read §0 first.** Before the flush and the UX work, be clear on where an
> *offline* notification even comes from: the backend cannot reach an offline
> device, so the offline prompt is a **local notification your app schedules** —
> not a push. §0 makes that responsibility explicit.

---

## 0. Where offline notifications come from (app-scheduled, NOT pushed)

This is the foundation for everything below, so it's stated first and plainly.

**A push notification requires connectivity. An offline device has none — so the
backend physically cannot deliver a wakefulness or photo prompt while the guard is
offline.** There is no server-sent notification in the offline case, at all. Any
prompt the guard sees offline is a **local notification the app fires itself**.

So the offline notification is **100% app-side**. The backend's only offline role
is what it already does: it **provisions everything up front** in the shift-start
payload, and it **receives the result on reconnect** (§1). Between those two
points — while the guard is offline — the backend is blind and silent by design.

What the backend hands you at shift start to make this possible (see
`MOBILE_API_INTEGRATION.md` §5.6 / the start-shift response):

- **Wakefulness:** the TOTP **seed** + the wakefulness **schedule** (the marks at
  which a check is due). You derive the code locally with the seed and the
  `window_reference` for that mark (RFC-6238, 30 s period) — no server round-trip.
- **Photos:** the **`photo_schedule`** (the marks at which a capture is due). At
  each mark you raise the capture UI and store the evidence against an
  offline-pool nonce, to be materialised on upload.

**What your app must do offline (this is the notification part):**

1. On shift start, read the wakefulness schedule + `photo_schedule` and **register
   local notifications** for each upcoming mark (e.g. `flutter_local_notifications`),
   so they fire even with no connectivity and the app backgrounded.
2. At each mark, present the same code-entry sheet / camera flow the online path
   uses — for wakefulness, compute the offline TOTP from the seed + that mark's
   `window_reference`.
3. **Capture the result into the offline queue** (§1 lists the exact fields), then
   flush on reconnect via the endpoints in §1.

You already have (1)–(2) working — you observed the offline wakefulness prompt fire
in testing. The gap that this handoff fixes is (3): the **result** had nowhere to
go on the backend. That's §1.

> This is the offline analogue of §3's foreground rule. Online-foregrounded and
> offline both bypass server push and must raise the prompt from app-side logic —
> the difference is only *what triggers it* (an FCM foreground message / pending
> poll vs. a locally-scheduled timer).

---

## 1. NEW — flush an offline wakefulness result (you must call this)

### Why it's a new endpoint (not `respond()`)

`POST /wakefulness/{checkId}/respond` needs a **server-side check row** to answer.
While the guard is offline the server never creates one (the dispatcher can't push
to an unreachable device), and the shift-start payload gives you the seed +
schedule but **no check IDs**. So there is no `checkId` to respond to. The new
endpoint hands the raw offline result to the server, which **materialises** the
check from it — the exact analogue of how an offline photo upload creates its own
request on arrival.

### The endpoint

```
POST  mobile/v1/shifts/{shiftId}/wakefulness/offline
Auth: guard.auth (same bearer token as every other mobile call)
Content-Type: application/json
```

**Body:**

| field             | type            | required | notes                                                            |
|-------------------|-----------------|----------|------------------------------------------------------------------|
| `window_reference`| integer         | ✅       | The TOTP time-step the code belongs to = `floor(unixSeconds / 30)`. Same value you already use to *generate* the offline code. |
| `code`            | string (≤ 8)    | ✅       | The digits the guard actually typed.                             |
| `scheduled_at`    | ISO-8601 string | optional | When the challenge fired on-device (the schedule mark). If omitted the server derives it from `window_reference × 30`. Send it if you have it — it makes the timeline exact. |
| `responded_at`    | ISO-8601 string | optional | When the guard answered on-device (audit only).                  |

**Success (200):**

```json
{ "data": { "result": "PASSED", "reason": null } }
```

`result` is `PASSED` | `FAILED`. A `FAILED` is a normal 200 (wrong code) — **not**
an error, and it does **not** page a supervisor (the window is long closed by the
time it flushes; "no retroactive alerts"). It IS still recorded for audit.

**Errors:**

- `404 SHIFT_NOT_FOUND` — the shift id isn't this guard's. Don't retry.
- `409 SEED_UNAVAILABLE` — the shift was never provisioned with a wakefulness seed
  (shouldn't happen for a normally-started shift). Don't retry.
- `422 VALIDATION_ERROR` — missing/!int `window_reference` or missing `code`.

### Idempotency (important for your retry loop)

The server keys the offline check on **(shift, window_reference)**. Flushing the
same window twice returns the first recorded outcome with `reason: "ALREADY_RESOLVED"`
— no duplicate row, no double event. So your queue can retry this call freely and
flush out-of-order; treat any 200 (including `ALREADY_RESOLVED`) as "done, dequeue
it." Only 5xx / network failure should be retried.

### Where this sits in your flush order

Your existing flush order (`PHASE_7_FLUTTER_OFFLINE_SYNC.md`) is
**wakefulness → GPS → photos**. This endpoint is the wakefulness step for
challenges that fired **while offline**. For a challenge that was **online** (you
got the FCM push and a real `check_id`) keep using `respond()` as before. Rule of
thumb:

- Had a `check_id` from a push/pending-poll → `POST /wakefulness/{checkId}/respond`.
- Fired from the on-device schedule while offline (no `check_id`) → `POST
  /shifts/{id}/wakefulness/offline`.

### What each offline queue item needs to store

At the moment the guard answers an offline challenge, persist:
`shift_id`, `window_reference` (the one you used to derive the shown code),
`code` (what they typed), `scheduled_at` (the mark), `responded_at` (now). That's
everything the flush needs. **Never** re-derive `window_reference` at flush time
from the wall clock — capture it when the challenge fires and store it verbatim.

---

## 2. What the backend now does with it (so you can trust the flush)

Once you flush, an offline wakefulness result becomes fully first-class:

- A `WakefulnessCheck` row (mode `OFFLINE`) with the real pass/fail — judged by the
  server re-deriving the TOTP for your `window_reference`, so a tampered code
  fails server-side even though you computed it on-device.
- Timeline audit events: a back-filled `WAKEFULNESS_CHALLENGE` at the mark, then
  `WAKEFULNESS_CONFIRMED` or `WAKEFULNESS_FAILED`.
- It shows on the **shift timeline page** (Wakefulness table, tagged "Offline",
  with the failure reason) and in the **Shift Welfare Report** (§5) and **Shift
  Audit Trail**.

A **failed** offline check is recorded but **not** escalated to a CRITICAL welfare
alert (it's a closed, backfilled window). A genuinely *live* miss is still handled
the old way (online timeout sweep). So: flush everything, pass and fail alike — the
failures are exactly what the supervisor needs to see in the reports.

---

## 3. App-side UX gaps (your work — no backend change)

These are the "screen on vs off" behaviours from testing. All app-side; the
backend already delivers everything needed.

### 3a. Wakefulness — in-app prompt when already foregrounded

- **Screen off / app backgrounded:** ✅ works today — the FCM challenge
  notification lands; tapping it deep-links into the code-entry screen.
- **Screen on, app already open:** ❌ the code-entry prompt does not appear on its
  own; you can only reach it by tapping the notification.

**What to build:** when a `WAKEFULNESS_CHALLENGE` data message arrives (or you
discover a pending challenge — see below) **while the app is in the foreground**,
present the code-entry sheet directly (an in-app modal/route push), don't rely on
the user tapping a tray notification. Handle both the FCM foreground-message
callback and the notification-tap deep link, routing both to the same sheet.

- The challenge `check_id`, `code` (online), `shift_id` and `response_seconds` all
  arrive in the FCM data payload (see `WakefulnessService::pushChallenge`).
- **Belt-and-braces fallback (now live):** `GET /shifts/{id}/wakefulness/pending`
  returns any outstanding online challenge (`check_id`, `code`, `issued_at`,
  `response_seconds`, `expires_at`) — the twin of `GET /shifts/{id}/photos/pending`.
  Poll it on foreground and every ~15–20 s while active; on finding a challenge,
  call `POST /wakefulness/{check_id}/received` then raise the same sheet and answer
  via `respond`. This makes the in-app prompt reliable instead of push-dependent.
  Contract: `MOBILE_API_INTEGRATION.md` §6.2.3.

### 3b. Photo verification — same foreground rule

Same expectation: with the screen on and the app open, a photo request should
raise the capture UI in-app, not only via the notification. The pending-poll
(`GET /shifts/{id}/photos/pending`) already exists as the fallback discovery path;
wire the foreground FCM `PHOTO_REQUEST` message to open the camera flow directly.

### 3c. Deep-link contract (both)

Make sure the notification-tap deep link and the foreground in-app handler
converge on **one** presentation path per check type, so a guard who taps the
notification and a guard who's already in-app get the identical sheet and the same
countdown. Avoid double-presenting if both fire (dedupe on `check_id` /
`request_id`).

---

## 4. What already works (don't re-do)

- Offline **photo** capture + flush — the offline-pool nonce path materialises the
  request on upload (unchanged). Offline photos are now correctly labelled
  "Offline" on the dashboard/report (backend fix shipped alongside this).
- Offline **TOTP generation** — your local RFC-6238 computation is unchanged; the
  server validates against the same seed/window.
- GPS batch/backfill, comms-gap events, sync-flush summary — all server-side, done.

---

## 5. Checklist for you

- [ ] **Offline prompt is app-scheduled (§0):** on shift start, register local
      notifications from the wakefulness schedule + `photo_schedule`; there is no
      server push while offline. (You already do this — listed so it's contractual.)
- [ ] On reconnect, flush each queued offline wakefulness answer to
      `POST /shifts/{id}/wakefulness/offline` with the stored
      `window_reference` + `code` (+ `scheduled_at`/`responded_at`).
- [ ] Treat any 200 (incl. `ALREADY_RESOLVED`) as done; retry only on 5xx/network.
- [ ] Foreground FCM `WAKEFULNESS_CHALLENGE` → present code-entry sheet in-app.
- [ ] Foreground FCM `PHOTO_REQUEST` → open camera flow in-app.
- [ ] Poll `GET /shifts/{id}/wakefulness/pending` (foreground + ~15–20 s while
      active) as the push-miss fallback; call `/received` on discovery.
- [ ] Notification-tap and foreground handler converge on one sheet; dedupe by id.

Questions — ping me. (The `wakefulness/pending` poll you might've wanted is now
live — §6.2.3.)
