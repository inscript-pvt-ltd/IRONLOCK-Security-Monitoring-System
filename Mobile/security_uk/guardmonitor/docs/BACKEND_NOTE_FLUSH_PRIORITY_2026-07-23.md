# Backend note — offline flush is now priority-ordered (FYI, no action needed)

**Date:** 2026-07-23
**From:** Mobile (Guard Monitor app)
**To:** Jerry (backend)
**Status:** Informational. **No backend change is required.** This is a heads-up
about a mobile-side behaviour change so the dashboard side isn't surprised by the
new arrival pattern on reconnect.

---

## TL;DR

When a guard's phone comes back online after being offline, the app used to drain
its buffered captures in the order **wakefulness → GPS → photos**. GPS is
high-volume (one ping every ~15s), so the **proof photos and welfare answers were
arriving *behind* a long GPS backlog** — sometimes minutes late on the dashboard.

We've reordered the flush to send the **compliance-critical events first**:

> **New order: wakefulness answers → proof photos → GPS trail** (oldest-first
> within each category).

We also added two extra triggers so buffered items drain sooner (details below).

**Nothing about the request payloads, endpoints, idempotency, or auth changes.**
The exact same POSTs hit the exact same endpoints — only the *order* and *timing*
of when they're sent changes.

---

## What changed on the app side

1. **Reordered flush** — wakefulness → photos → GPS (was wakefulness → GPS →
   photos). Welfare answers and proof photos now reach you first on reconnect;
   the GPS breadcrumb trail catches up after.

2. **Enqueue-kick** — the moment a welfare answer or offline photo is buffered,
   the app attempts an immediate drain instead of waiting for the next
   connectivity event. (GPS is excluded — it's bulk and latency-tolerant.)

3. **60-second heartbeat** — while signed in, the app retries the queue at least
   once a minute, as a backstop for the case where the OS connectivity flag never
   flips but individual requests are still failing.

The flush remains **single-flight** (overlapping triggers coalesce onto one run)
and **best-effort** (an empty queue is a cheap no-op).

---

## What this means for the backend / dashboard

- **Endpoints unchanged.** Same as today:
  - Wakefulness offline answers → `POST /shifts/{id}/wakefulness/offline`
  - Photos → `POST /shifts/{id}/photos`
  - GPS → `POST /shifts/{id}/locations` (batched `pings[]`, ≤200/req)
- **Payloads unchanged.** Same fields, same signatures, same time proofs
  (`window_reference` / `responded_at` / `scheduled_at` for wakefulness;
  `captured_at` / `ntp_reference` / `elapsed_seconds` for photos).
- **Idempotency still relied upon.** We re-send freely and expect the existing
  `ALREADY_RESOLVED` / `NONCE_ALREADY_USED` responses on a duplicate. The new
  triggers (enqueue-kick + heartbeat) mean **you may see slightly more duplicate
  re-sends** for an item that's mid-retry — this is by design and already handled
  by your idempotent responses. No change needed, just flagging it.
- **Arrival order on the dashboard changes.** For a shift that was offline for a
  while, expect **photos and welfare answers to land before the bulk of that
  window's GPS pings**, rather than after. Timestamps are unaffected — every item
  still carries its original captured/answered time, so the dashboard timeline
  back-dates correctly regardless of arrival order.

---

## The one thing worth confirming on your side

We rely on your endpoints tolerating **any order** of arrival across the three
categories (a photo can arrive before the GPS ping that shares its minute, etc.).
This was already stated in `PHASE_7_SYNC_INTEGRITY.md §3`, and it was already true
before this change — we're just leaning on it more now.

**Please confirm there's no hidden ordering assumption** (e.g. a photo/welfare
event being rejected or mis-bucketed if its surrounding GPS pings haven't arrived
yet). If everything is genuinely order-independent — which we believe it is — then
there is nothing for you to do.

---

## Question back to you (optional, not blocking)

Is there any server-side benefit to us **de-prioritising or throttling GPS**
further during a large flush (e.g. sending photos/welfare, pausing, then trickling
GPS) — or does your ingest handle a 200-ping batch immediately after a photo
without contention? We're happy with current behaviour; only asking in case your
geofence/UPSERT path prefers GPS spaced out.
