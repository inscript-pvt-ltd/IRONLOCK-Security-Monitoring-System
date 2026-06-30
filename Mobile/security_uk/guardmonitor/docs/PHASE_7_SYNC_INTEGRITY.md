# Phase 7 — Server-Side Sync Integrity (Component C)

**Status:** ✅ Verified 2026-06-29 (10/10 live rolled-back checks).
**Scope:** the *server's* guarantees when a reconnecting app flushes a buffered
backlog. The on-device queue, flush ordering and retry/backoff are the **Flutter
dev's** responsibility — see `PHASE_7_FLUTTER_OFFLINE_SYNC.md`.

This is the "Queue integrity validation / Chronological sync order / Failed sync
retry" line of `DEVELOPMENT_ROADMAP.md` Phase 7. On the server these are **already
satisfied** because each capability replays through its own idempotent, replay-safe
endpoint. **There is no server-side `sync_queue` table** — the queue lives on the
device. This document states the guarantees and points at the code + tests that prove
them, rather than adding new machinery.

---

## 1. No single sync transaction — three independent, idempotent endpoints

A reconnect flush is **not** one atomic server call. The app drains its on-device
queue into the *existing* per-capability endpoints:

| Capability | Endpoint | Contract |
|---|---|---|
| GPS backlog | `POST /shifts/{id}/locations` (`GPSController@ping`, batch `pings[]`) | §6.1 |
| Wakefulness replay | `POST .../wakefulness/{check}/respond` (`WakefulnessService::respond`) | §6.2 |
| Offline photos | `POST .../photos` (`PhotoVerificationService::submitPhoto(s)`) | §6.3/6.4 |

Each is self-contained. The server **does not require a particular arrival order**
between the three (see §3), so a partial flush (e.g. GPS succeeds, photos retried
later) is always in a consistent state.

---

## 2. Replay / duplicate safety (the core guarantee)

Every path is safe to receive **the same item more than once** — the inevitable
consequence of client-side retry over a flaky link.

### GPS — idempotent UPSERT
`GPSTrackingService::recordLocation()` does `GuardLocation::updateOrCreate(['guard_id'
=> …], …)`. One mutable row per guard; a re-sent identical ping just rewrites the same
row. *Verified:* three identical pings → exactly one `guard_locations` row.

### Wakefulness — atomic PENDING→result, then echo
`WakefulnessService::respond()` resolves a check exactly once. A replay of an
already-resolved check short-circuits at the `!$check->isPending()` guard and **echoes
the recorded outcome** with `reason = ALREADY_RESOLVED` — it does **not** re-write the
row and does **not** raise another alert. *Verified:* a replayed correct answer returns
`ALREADY_RESOLVED`, `responded_at` is unchanged, no extra alert; and a *wrong*-code
replay cannot flip an already-PASSED check.

### Photos — single-use nonce, enforced at the DB
`NonceService::markUsed()` is a conditional UPDATE (`whereNull('used_at')`), so two
uploads racing the same nonce cannot both win. `PhotoVerificationService::submitPhotos()`
rejects a spent nonce with `NONCE_ALREADY_USED` (both at pre-validate and at the
post-race guard), so a replayed photo never creates a duplicate `photo_evidence` row.
*Verified:* first `markUsed()` → true, second → false; `validate()` on a used nonce →
`NONCE_ALREADY_USED`.

---

## 3. Order-insensitivity (why "chronological sync order" is a client concern)

The roadmap's "chronological sync order" is about the **device** draining its queue
in a sensible order (wakefulness → GPS → photos) so its own UI is coherent. The
**server** does not depend on it:

- **GPS** UPSERT is last-write-wins on a single row; `finalizeFlush()` judges the
  *net* zone transition against the pre-flush snapshot, and the delayed
  `CheckZoneExitJob` re-confirms present position before alerting (Component A). Order
  within the batch does not change the outcome.
- **Wakefulness** windows are **absolute** — a check is validated against
  `TOTP(seed, window_reference)`, not against wall-clock arrival order. A late answer
  lands on the correct window regardless of when it arrives.
- **Photos** carry their **own** validity window (nonce expiry / NTP-anchor
  reconstruction). Acceptance depends on the reconstructed capture time vs that
  window, never on arrival order.

→ **The Flutter dev should still flush in chronological order for app-side coherence,
but the server tolerates any order.** Flag this as an expectation, not a server
requirement.

---

## 4. Failed-sync retry — stable, idempotent error envelopes

Retry/backoff is **client-side**. The server's job is to return clear, stable error
codes so a retry is unambiguous and a retried success never double-writes. The codes
the app must branch on:

| Code | Endpoint | Retryable? | Meaning |
|---|---|---|---|
| `VALIDATION_ERROR` (422) | GPS, others | No (fix payload) | Malformed body (e.g. empty `pings[]`, bad coords) |
| `SHIFT_NOT_ACTIVE` (409) | GPS, wakefulness, photos | No | Shift not active / not owned by this guard — drop the item |
| `CHECK_NOT_FOUND` | wakefulness | No | Unknown check id — drop |
| `ALREADY_RESOLVED` | wakefulness | No (treat as success) | Check already resolved — stop retrying, it's done |
| `NONCE_NOT_FOUND` | photos | No | Unknown nonce — drop |
| `NONCE_ALREADY_USED` | photos | No (treat as success) | This capture already landed — stop retrying |

Rule of thumb for the app: **transport/5xx/timeout → retry with backoff; a 4xx
business code → do not retry (it is terminal), surface or drop per the table.** A
success that is actually a duplicate returns the same terminal "already done" signal,
so the queue can safely mark the item complete.

---

## 5. The flush audit trail (Component A output, consumed by B)

A reconnect that crosses `gps_backfill_threshold_seconds` (60s) writes, on the
immutable `shift_events` log:

- `COMMS_GAP_START` — dated to the last server-receipt before the gap.
- `COMMS_GAP_END` — dated to reconnect.
- `SYNC_FLUSH` — a summary (`gap_seconds`, `gps_pings_synced`).

Boundaries are **server-determined** (the client clock is never trusted to decide the
window). `SYNC_FLUSH` counts only GPS pings in that batch; wakefulness/photo replays
stay individually audited (`WAKEFULNESS_*` / `PHOTO_*`). The admin dashboard renders
these as an "offline / backfilled" band (Component B).

---

## 6. What is explicitly NOT built server-side (and why that's correct)

- **No `sync_queue` table / no cross-capability "sync complete" receipt.** The queue
  is on the device; a single receipt would need a dedicated app-called endpoint — a
  future Flutter-contract item, not needed for correctness today.
- **No breadcrumb / location history.** `guard_locations` stays single-row (Phase 6
  decision 0.1). Offline windows derive from the immutable `shift_events`, not a new
  history store.
- **No retroactive alerts.** Component A: a backfilled backlog records history but only
  ever alarms on a condition still true *now*.

---

## 7. Verification

`verify:phase7` (Component A, 14/14) and `verify:phase7c` (this doc, 10/10) were
throwaway artisan commands run as live rolled-back transactions against the dev DB,
then deleted. To re-prove, re-create them from this spec + the daily update, or fold
the assertions into a feature-test suite if/when one is stood up. All production code
lints clean (`php -l`); both dashboard blades compile (`view:cache`).
