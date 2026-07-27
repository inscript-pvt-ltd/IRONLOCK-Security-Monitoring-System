# Backend ask — accept LATE offline submissions for an older window

**Date:** 2026-07-24
**From:** Mobile (Guard Monitor app)
**To:** Jerry (backend / dashboard)
**Status:** Needs a backend **confirmation** (possibly a small tweak). Not blocking a
build, but it's the one contract point the app can't guarantee on its own.
**Related:** `docs/BACKEND_ASKS_2026-07-24.md` (the 3 shape/format asks). This is a
follow-up from the reachability work on the same date — see the "Why now" section.

---

## The ask, in one line

When an **offline** welfare answer or photo is flushed to the server **minutes or
hours after** its scheduled window, the server should still **accept and record it
against that original window** (pass/fail as the code dictates) — not reject it as
"too late / unknown window / expired."

Concretely:

1. **`POST /shifts/{id}/wakefulness/offline`** — a body whose `window_reference`
   (and optional `scheduled_at`) points at a window well in the past must still
   materialise that check and record the outcome. A duplicate for the same
   `(shift, window_reference)` returns `200 ALREADY_RESOLVED` (already the contract).
   Please confirm there is **no server-side max-age** that would drop a genuinely
   late replay.

2. **Offline photos** (`POST /shifts/{id}/photos` with a pool nonce, no `request_id`)
   — a capture whose `captured_at` is well before the flush time must still be
   accepted, validated against that timestamp, and stored. The nonce's own
   `expires_at` already spans the shift (confirmed 2026-07-23), so this is really
   "please don't reject on `captured_at` age alone."

---

## Why now (what changed on the device)

Until today the app decided "am I offline?" purely from the OS network interface
(`connectivity_plus`). That misses the **dead-Wi-Fi / captive-portal** case: the
phone associates to a Wi-Fi that never lets our API calls through, so the interface
says *online* while nothing actually reaches the server.

We fixed that: the app now tracks **server reachability** (did our requests actually
get an HTTP response?) and, when the interface is up but the backend is unreachable,
it falls back to the **offline** welfare/photo path — so the guard keeps getting
prompted instead of silently missing checks. Those answers are recorded via the
**offline endpoints** the moment reachability returns.

Side effect: a guard can now be "effectively offline" for **longer** stretches than
before (a whole shift on a bad venue network), so an offline answer may be flushed
**significantly after** its window. That makes the "does the server accept a late
window replay?" question load-bearing where before it was mostly theoretical.

This is also correct for the dashboard's **Online/Offline tagging**: because the
server was genuinely unreachable at answer time, recording via the offline endpoint
(→ tagged *Offline*) is accurate, not a mislabel.

---

## What we need back from you

- [ ] **Confirm** `POST /shifts/{id}/wakefulness/offline` has **no max-age cutoff**
      that would reject a window flushed hours late (or tell us the cutoff so we can
      surface it honestly).
- [ ] **Confirm** offline photos are accepted on `captured_at` regardless of how much
      later they're flushed (within the shift), keyed on the pool nonce.
- [ ] If there **is** a cutoff we should respect, tell us the value and the error
      code, and we'll stop replaying past it (and record a local "expired offline"
      miss instead of a doomed POST).

## What the app already guarantees (so you're not chasing our side)

- Every offline welfare answer carries its absolute `window_reference` (+ `scheduled_at`
  when known) and its true `responded_at`, so the server has everything it needs to
  place it on the right step.
- Replays are **idempotent** — we re-send freely and treat `ALREADY_RESOLVED` /
  `NONCE_ALREADY_USED` as success.
- Offline captures wait for a **real** connection now: a "no server response" failure
  no longer burns the retry budget, so a long offline stretch can't silently drop the
  oldest queued answers before they're sent (fixed 2026-07-24).

---

## Net

One confirmation (no max-age on late offline window replays) and one on late-flushed
photos. If both are already true, there's nothing to build — we just want it written
down so the "connected-but-unreachable" guard's checks are guaranteed to land.
