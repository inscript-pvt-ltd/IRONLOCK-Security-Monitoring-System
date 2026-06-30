# Phase 7 — Offline Sync: Flutter App Responsibilities

**Audience:** the Flutter app developer.
**From:** backend/dashboard (Jerry).
**Date:** 2026-06-29.
**Companion docs:** `MOBILE_API_INTEGRATION.md` (the API contract — source of truth for
payloads), `PHASE_7_SYNC_INTEGRITY.md` (what the server guarantees).

---

## TL;DR

The **server side of Phase 7 is done**: the batch/replay endpoints exist, they are
idempotent and replay-safe, and a reconnect is recorded + shown on the dashboard. **No
backend changes are pending on you.**

What's left is **all on the device**: persist captured data while offline, then drain
it to the existing endpoints on reconnect. The server already tolerates duplicates and
any ordering — your job is to **queue, flush, and retry correctly**, and to **never
trust the device wall-clock** as proof of time.

You do **not** need a new "sync" endpoint. There is no server `sync_queue`. You reuse
the endpoints below.

---

## 1. What you must build (the on-device queue)

1. **Encrypted local queue** (roadmap 7.1) — an on-device store (e.g. encrypted
   SQLite / `sqflite_cipher`, or Drift + SQLCipher) holding every item captured while
   offline:
   - GPS pings
   - Wakefulness answers (offline TOTP)
   - Photo captures (+ their pre-fetched nonce, signature, NTP anchor)
2. **Flush on reconnect** (7.2) — when connectivity returns, drain the queue to the
   endpoints in §2.
3. **Retry with backoff** — re-send on transport failure/timeout/5xx; **stop** on a
   terminal 4xx business code (see §4).
4. **Chronological flush order** — drain **wakefulness → GPS → photos**, oldest first.
   The server tolerates any order (§ integrity doc), but ordering keeps your own UI and
   logs coherent.

---

## 2. The endpoints you flush to (already live)

All under the authenticated mobile API (GuardAuth). Paths per `routes/api.php`.

### a) GPS backlog — `POST /shifts/{id}/locations`
Send a **batch** in one call. Steady state is one ping / 15s; offline you accumulate
and flush the backlog together.
```jsonc
{
  "pings": [
    {
      "latitude": 51.5012,
      "longitude": -0.0901,
      "accuracy": 4.5,          // metres, optional
      "battery": 0.83,          // 0.0–1.0 FRACTION (server stores as integer %)
      "recorded_at": "2026-06-29T14:05:11Z"  // device capture time, ISO-8601 UTC
    }
    // … more buffered pings, in chronological order
  ]
}
```
- `recorded_at` is **diagnostic only** — the server stamps its own "last seen" on
  arrival. But still send it: the server uses the gap between the previous receipt and
  now to detect the comms gap and write the offline band on the timeline.
- A flush that arrives after a gap > 60s is auto-recorded as
  `COMMS_GAP_START/END` + `SYNC_FLUSH`. You don't send those — the server derives them.

### b) Wakefulness offline answer — `POST /wakefulness/{checkId}/respond`
For a challenge the guard answered **offline** (you computed `TOTP(seed, window)`
locally and they transcribed it):
```jsonc
{
  "code": "4821",
  "is_offline": true,
  "window_reference": 1782342,      // the TOTP time-step (integer) you used
  "responded_at": "2026-06-29T14:06:02Z"  // optional, audit only
}
```
- The server re-derives `TOTP(seed, window_reference)` and confirms/fails it. Validity
  is proven by the **window**, not by when it arrives — a late flush still lands on the
  right window.
- **A failed offline answer does NOT page the supervisor retroactively** (the guard is
  back online by the time you flush). It's recorded for audit. So don't suppress
  sending a failed offline answer — send it; the server handles it correctly.

### c) Offline photos — `POST /shifts/{id}/photos`
For photos captured offline using a **pre-fetched nonce** from the pool:
```jsonc
// multipart/form-data
{
  "nonce_value": "<one nonce from the prefetched pool>",
  "photos[]":     [<file>, ...],     // 1–5 images
  "signatures[]": ["<hmac>", ...],   // one per image, positionally matched
  "ntp_reference":  "<NTP anchor captured at/near capture time>",
  "elapsed_seconds": 37.2,           // monotonic elapsed since the NTP anchor
  "latitude": 51.5012, "longitude": -0.0901,
  "captured_at": "2026-06-29T14:04:55Z"
}
```
- Pre-fetch the pool while online: `POST /shifts/{id}/nonces/prefetch` (20-nonce pool,
  15-min single-use each). Draw one nonce per capture; never reuse one.
- Capture time is reconstructed from `ntp_reference + elapsed_seconds`, **not** the
  wall clock — this is what defeats clock manipulation. Capture the NTP anchor + a
  monotonic clock at photo time and store both in the queue.

---

## 3. Time integrity — the one rule that matters most

**Never trust `DateTime.now()` (the device wall clock) as proof of when something
happened.** The server treats the device clock as untrusted by design:

- **GPS**: server uses its own receipt time for "last seen" and gap detection.
- **Wakefulness**: validity comes from the absolute TOTP `window`, which you must record
  at capture time and send back verbatim.
- **Photos**: capture time is rebuilt from the **NTP anchor + monotonic elapsed**, so
  capture the NTP reference (and a monotonic timer) at the moment of capture and persist
  them with the queued item. Do not recompute from wall-clock on flush.

If the NTP anchor is unavailable at capture, still queue the photo — the server flags
`NTP_UNAVAILABLE` / `DELAYED_UPLOAD` rather than silently trusting you.

---

## 4. Retry rules — when to re-send vs give up

| Server response | Action |
|---|---|
| Transport error / timeout / `5xx` | **Retry** with exponential backoff |
| `VALIDATION_ERROR` (422) | **Do not retry** — payload is malformed; log/drop |
| `SHIFT_NOT_ACTIVE` (409) | **Do not retry** — shift ended/handed over; drop the item |
| `CHECK_NOT_FOUND` (wakefulness) | **Do not retry** — drop |
| `ALREADY_RESOLVED` (wakefulness) | **Treat as success** — already done; dequeue |
| `NONCE_NOT_FOUND` (photo) | **Do not retry** — drop |
| `NONCE_ALREADY_USED` (photo) | **Treat as success** — this capture already landed; dequeue |

Key point: **the endpoints are idempotent.** If you're unsure whether a flush
succeeded (e.g. you got a timeout but it actually committed), just re-send — a
duplicate GPS ping rewrites the same row, a duplicate wakefulness answer returns
`ALREADY_RESOLVED`, a duplicate photo returns `NONCE_ALREADY_USED`. You will never
double-count. So **prefer re-sending over guessing.**

---

## 5. Definition of done (app side)

- [ ] Captures persist to an **encrypted** local queue while offline (GPS, wakefulness,
      photos + their nonce/signature/NTP anchor).
- [ ] On reconnect, the queue drains **wakefulness → GPS → photos**, oldest first.
- [ ] GPS flushes as a **batch** (`pings[]`), not one request per ping.
- [ ] Each offline photo uses a **distinct** pre-fetched nonce and carries its
      signature + NTP anchor + elapsed.
- [ ] Retry/backoff follows the §4 table; terminal 4xx codes dequeue, they don't loop.
- [ ] No wall-clock is sent as authoritative time; the TOTP window and NTP anchor are
      preserved verbatim from capture.
- [ ] A flush after a > 60s gap produces the offline band on the admin timeline (you can
      confirm with Jerry on the dashboard) — nothing extra to send for this.

---

## 6. What you do NOT need to do

- ❌ Build any new "sync" endpoint or send a "sync complete" message — not needed.
- ❌ De-duplicate before sending — the server is idempotent; re-send freely.
- ❌ Send `COMMS_GAP_*` / `SYNC_FLUSH` events — the server derives those.
- ❌ Worry about retroactive alerts — the server already suppresses them for backfilled
      data; just flush honestly.

Questions on any payload field → `MOBILE_API_INTEGRATION.md` is the contract; ping
Jerry for anything ambiguous.
