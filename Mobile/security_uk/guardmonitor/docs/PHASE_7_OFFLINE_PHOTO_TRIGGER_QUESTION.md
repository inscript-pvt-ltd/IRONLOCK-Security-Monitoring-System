# Phase 7 — Offline-photo capture trigger: open question for backend

**For:** Jerry (backend/dashboard).
**From:** Flutter app.
**Date:** 2026-06-30.
**Status:** ✅ **RESOLVED 2026-06-30 — Option A (offline photo SCHEDULE).** Backend reply below.
**Context docs:** `PHASE_7_FLUTTER_OFFLINE_SYNC.md` (§2c), `PHASE_7_IMPLEMENTATION_PLAN.md` (§8),
updated `FLUTTER_API_GUIDE` (Photo Verification → Provisioning; 2026-06-30 changelog).

---

## ✅ Answer (2026-06-30): Option A — a provisioned photo schedule

The backend chose **Option A**: offline photos are triggered by a **photo schedule**, exactly
analogous to the wakefulness TOTP schedule. Concrete contract:

- **`POST /shifts/{id}/start` now returns a `photos` block** next to `wakefulness`:
  ```jsonc
  "photos": {
    "schedule": [ "2026-06-30T12:28:00Z", "2026-06-30T13:18:00Z" ], // ISO-8601 UTC due-times
    "response_seconds": 90,            // ONLINE window only (a pushed request); NOT used offline
    "offline_nonce_ttl_minutes": 15,   // offline: drawn pool nonce validity; capture must fall inside
    "max_photos_per_capture": 5        // up to 5 images per mark
  }
  ```
- **One schedule drives both paths.** While **online**, a mark arrives as a pushed
  `PHOTO_REQUEST` (existing flow). While **offline**, the app self-fires the camera at the mark,
  draws a **pool nonce**, captures, queues, flushes on reconnect — **omit `request_id`**. So the
  app must **not** self-fire when online (the server push owns it) — same gating as wakefulness
  (`!online || !PushMessaging.isDelivering`).
- **No per-mark id, no offline countdown.** Offline validity is purely the 15-min pool-nonce
  window. The server matches a fired check to a mark by timestamp (±60 s), so we don't echo an id.
- **No new upload contract.** Upload endpoint, signing, nonce pool, 15-min window are unchanged
  from Phase 4 — only the *trigger* is now defined. A scheduled offline photo lands as its own
  evidence record (`request_type = SCHEDULED`, no `request_id`).

→ This maps onto the machinery already built (NoncePoolService, TimeAnchorService,
OfflinePhotoService.enqueueCapture, the photo flush). Remaining app work = parse the `photos`
block + a `PhotoScheduleNotifier` (mirror `WakefulnessScheduleNotifier`) + an offline capture UI
entry. Tracked in `PHASE_7_IMPLEMENTATION_PLAN.md`.

The original question + option analysis is kept below for the record.

---

## (Original question — for the record)

**Status when written:** ⛔ blocking the offline-photo capture path. Everything else in Phase 7
(GPS + wakefulness offline queue/flush, the photo *flush* + signing machinery) is built and tested.

---

## TL;DR

The app can now **flush** offline photos (sign with a pool nonce, queue, re-send on reconnect).
What's undefined is **when an offline photo gets taken in the first place** — i.e. what *triggers*
the camera while the guard is offline. All photos today are **server-initiated and online-only**.
We need the backend's intended model before wiring the UI, so we don't build the wrong behaviour.

---

## Why there's a gap

Today's photo flow is **100% server-initiated and online**:

1. Server raises a `PHOTO_REQUEST` (FCM push and/or `/photos/pending` poll), carrying a
   `request_id` + an **online** nonce (`TYPE_ONLINE`, 90s, judged at receipt).
2. The guard opens the camera and uploads within the 90s window.

When the guard is **offline**, that request **cannot arrive** (no push, no poll). So there is no
event that tells the app "take a verification photo now" while disconnected. Meanwhile the contract
(`PHASE_7_FLUTTER_OFFLINE_SYNC.md` §2c) clearly expects offline photos to exist — captured against a
**pre-fetched `TYPE_OFFLINE_POOL` nonce**, `request_id` omitted, judged by reconstructed capture
time (15-min window). Those two facts only reconcile if there's an **offline trigger**, which the
app doesn't have yet.

> Confirmed already (2026-06-30): an online request nonce on a delayed upload → `NONCE_EXPIRED`;
> offline captures must draw a pool nonce and omit `request_id`. That part is settled. The open
> question is purely **what initiates an offline capture**.

---

## The decision we need

Which of these is the intended offline-photo model? (Pick one, or describe the real one.)

### Option A — Offline schedule (like wakefulness TOTP)
The backend issues a **photo schedule** at shift start (a list of due-times), analogous to the
wakefulness TOTP schedule. While offline, the app fires the camera when a scheduled time comes due,
draws a pool nonce, and queues the result.
- **Backend work:** add a photo schedule to the `POST /shifts/{id}/start` payload (times only; the
  app already prefetches the nonce pool).
- **App work:** a scheduler mirroring `WakefulnessScheduleNotifier` (we have the pattern).
- **Pro:** verification keeps happening during long offline stretches. **Con:** new backend contract.

### Option B — Bridge a missed online request
If a `PHOTO_REQUEST` arrived **just before** the link dropped (or drops mid-window), the app lets the
guard still answer it **offline** by re-signing with a **pool nonce** (no `request_id`) and queueing.
The original request is recorded as missed; the pool-nonce photo lands separately on reconnect.
- **Backend work:** none (pool-nonce path already exists). **Just confirm** a standalone pool-nonce
  photo with no `request_id` is the correct/acceptable record for a request that expired offline.
- **App work:** on an offline upload failure in the existing photo screen, fall back to
  `enqueueCapture` when a pool nonce is available.
- **Pro:** smallest change, reuses the live screen. **Con:** only covers requests that overlap the
  disconnect; doesn't cover a guard who's been offline a while.

### Option C — Guard-initiated offline capture
A manual "take verification photo" affordance the guard can use while offline (or always), pool-nonce
backed.
- **Backend work:** confirm self-initiated pool-nonce photos are accepted + how they're surfaced on
  the dashboard. **App work:** a new capture entry point.
- **Pro:** simple, always available. **Con:** relies on the guard remembering; weakest assurance.

### Option D — Offline photos aren't a real scenario yet
If, in practice, photo verification is only ever expected while online, then there's **nothing to
trigger** and we leave the capture path unwired (the flush machinery stays dormant, ready). Confirm
this and we close the item.

---

## Specific confirmations needed (whichever option)

1. **Which option above** matches the intended product behaviour?
2. **Dashboard side:** how should a standalone **pool-nonce photo with no `request_id`** be
   attributed/displayed? Against the nearest shift timeline? As its own evidence item?
3. **If Option A:** what's the schedule shape in the start payload (field name, times, any per-mark
   id)? Same `response_seconds` semantics as wakefulness?
4. **If Option B:** is a missed online `PHOTO_REQUEST` + a separate pool-nonce photo the right
   recorded outcome, or do you need the pool photo tied back to the original `request_id` somehow?

---

## What's already built and waiting (no backend dependency)

- `NoncePoolService` — prefetch/refill/draw `OFFLINE_POOL` nonces (`/shifts/{id}/nonces/prefetch`),
  topped up while online during a shift.
- `TimeAnchorService` — NTP anchor projected to the shutter via a monotonic clock (tamper-proof,
  EXIF-aligned).
- `OfflinePhotoService.enqueueCapture` — draws a nonce, signs each image, persists files, queues.
- `PhotoService.submitOfflinePhotos` + `SyncFlushService._flushPhotos` — re-send the stored
  signature **verbatim**, idempotent retry table, `NONCE_ALREADY_USED` = success.

→ Once you pick a model, wiring the trigger is small (Option B is ~an afternoon; Option A reuses the
wakefulness scheduler). **The only thing we won't do is guess the product rule.**
