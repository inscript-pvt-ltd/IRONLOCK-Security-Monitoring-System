# Phase 7 — Offline Sync: Flutter Implementation Plan

**Status:** PLAN — awaiting build. Approved decisions: storage = **Drift + SQLCipher**;
scope = **all three capabilities (GPS, wakefulness, photos) in one pass**.
**Date:** 2026-06-30.
**Contracts:** `PHASE_7_FLUTTER_OFFLINE_SYNC.md` (what to build),
`PHASE_7_SYNC_INTEGRITY.md` (server guarantees), `FLUTTER_API_GUIDE-2.md` (payloads).
**Rule above all rules:** never send the device wall-clock as proof of time
(TOTP `window` + NTP anchor are the only trusted time sources).

---

## 0. Guiding principles (do not violate)

1. **Server is done & idempotent.** We only queue, flush, retry. No new endpoint,
   no "sync complete" call, no client-side de-dup.
2. **Both platforms, every change** (Android + iOS) — house rule.
3. **The online happy-path must not regress.** Offline is an *added fallback*: when
   online, behaviour is byte-for-byte what it is today. The queue is a catch, not a
   reroute.
4. **Time integrity:** GPS `recorded_at` is diagnostic; wakefulness validity = TOTP
   `window_reference`; photo capture time = `ntp_reference + elapsed_seconds`. Persist
   these *at capture*, send verbatim on flush. Never recompute from wall-clock at flush.
5. **Encrypted at rest.** The queue holds GPS, wakefulness answers, and photo
   nonce/signature/NTP anchors — all via SQLCipher. Photo *bytes* stay on the filesystem
   (app sandbox); the DB stores the file path + crypto material.
6. **Quality gate:** `flutter analyze` clean + `flutter test` green before "done."

---

## 1. What already exists (reuse, don't rebuild)

| Piece | Where | Reuse as |
|---|---|---|
| GPS already POSTs batch `{pings:[…]}`; catch comments "offline queue phase will handle" | `gps_service.dart:153,162` | Redirect the catch into the queue |
| Offline wakefulness payload (`is_offline`, `window_reference`) already wired | `wakefulness_service.dart:26-29` | Flush calls this unchanged |
| TOTP `window()` + `code()` | `totp_service.dart` | Compute offline answer + `window_reference` |
| Connectivity stream `networkStatusProvider` / `isOnlineProvider` | `connectivity_service.dart` | Reconnect trigger for the flusher |
| `WakefulnessState.windowReference / isOffline` | `wakefulness_provider.dart:34-49` | Already models an offline challenge |
| Multi-photo signing (`buildSignature`, `photos[]`/`signatures[]`) | `photo_service.dart` | Offline photo reuses the exact signer |
| Secure storage (Keychain/Keystore) | `secure_storage_service.dart` | Store the SQLCipher DB passphrase here |

**Implication:** wakefulness offline is ~80% done already; the heavy new work is the
**queue itself**, the **flush orchestrator**, and the **offline-photo nonce pool + NTP anchor**.

---

## 2. New dependencies

```yaml
dependencies:
  drift: ^2.x                 # typed SQL, transactions, testable
  sqlcipher_flutter_libs: ^0.6.x   # bundles the SQLCipher .so/.dylib
  # connection: open Drift over SQLCipher via a NativeDatabase with PRAGMA key
  ntp: ^2.x                   # SNTP anchor for photo capture-time proof
dev_dependencies:
  drift_dev: ^2.x
  build_runner: ^2.x
```

Notes:
- **iOS:** `sqlcipher_flutter_libs` requires the bundled SQLCipher to take precedence
  over the system SQLite — add the standard `$(inherited)` + the pod's preprocessor
  define per its README, and verify `PRAGMA cipher_version` returns non-empty on device.
- **Passphrase:** 32-byte random key generated once, stored in `flutter_secure_storage`
  (`db_cipher_key`). Wiped in `clearSession()` → a fresh login = fresh DB (no cross-guard
  bleed). If the key is missing on open, recreate the DB empty (the queue is best-effort,
  not the source of truth).
- **`ntp`** uses UDP/123; will silently fail offline — that's fine, we record
  `NTP_UNAVAILABLE` and still queue (server flags `DELAYED_UPLOAD`).

---

## 3. The data model (Drift tables)

One Drift database `OfflineQueueDb`, three tables + a small attempt-metadata pattern
shared via columns (not a separate table — keeps it simple).

```text
GpsQueue
  id            INTEGER PK AUTOINC
  shift_id      TEXT
  latitude      REAL
  longitude     REAL
  accuracy      REAL?            -- nullable
  battery       REAL?            -- 0..1 fraction, nullable
  recorded_at   TEXT             -- ISO-8601 UTC, captured at fix time (diagnostic)
  attempts      INTEGER  DEFAULT 0
  next_attempt  INTEGER  DEFAULT 0   -- epoch ms; backoff gate
  created_at    INTEGER          -- epoch ms, for ordering

WakefulnessQueue
  id               INTEGER PK AUTOINC
  check_id         TEXT
  code             TEXT
  window_reference INTEGER        -- the trusted proof-of-time
  responded_at     TEXT           -- ISO UTC, audit only
  attempts         INTEGER DEFAULT 0
  next_attempt     INTEGER DEFAULT 0
  created_at       INTEGER

PhotoQueue
  id               INTEGER PK AUTOINC
  shift_id         TEXT
  nonce_value      TEXT           -- one drawn from the prefetched pool
  file_paths       TEXT           -- JSON array of 1..5 sandbox paths
  signatures       TEXT           -- JSON array, positionally matched
  ntp_reference    TEXT?          -- ISO UTC anchor at capture (nullable)
  elapsed_seconds  REAL           -- monotonic seconds since the anchor
  latitude         REAL?
  longitude        REAL?
  captured_at      TEXT           -- ISO UTC (diagnostic; server rebuilds from ntp+elapsed)
  attempts         INTEGER DEFAULT 0
  next_attempt     INTEGER DEFAULT 0
  created_at       INTEGER

NoncePool                          -- prefetched offline nonces
  nonce_value   TEXT PK
  shift_id      TEXT
  expires_at    INTEGER            -- epoch ms; 15-min TTL, single-use
  drawn         INTEGER DEFAULT 0  -- 0=available, 1=consumed by a queued capture
```

Why columns instead of a generic `queue` blob: typed retry/backoff per row, trivial
"oldest first" ordering by `created_at`, and each capability's flush maps 1:1 to a table.

---

## 4. New components

### 4.1 `OfflineQueueDb` (Drift) — `lib/data/offline_queue_db.dart`
- Opens SQLCipher with the secure-storage passphrase.
- CRUD: `enqueueGps/Wakefulness/Photo`, `dueItems(now)` per table (where
  `next_attempt <= now` ordered by `created_at`), `markDone(id)` (delete),
  `bumpAttempt(id, nextAttempt)`.
- `clearAll()` called from `clearSession`.

### 4.2 `NoncePoolService` — `lib/services/nonce_pool_service.dart`
- `Future<void> refillIfLow(shiftId)` — when online and pool `available < threshold`
  (e.g. <5), `POST /shifts/{id}/nonces/prefetch`, store the 20 returned nonces with
  their `expires_at`. Call opportunistically: on shift start, and after each online
  photo capture.
- `Future<String?> draw(shiftId)` — atomically pick an unexpired, undrawn nonce, mark
  `drawn=1`, return it (null if the pool is dry → offline capture not possible, surface
  to UI: "reconnect to take a verification photo offline" — rare, pool is 20 deep).
- `purgeExpired()`.

### 4.3 `TimeAnchorService` — `lib/services/time_anchor_service.dart`
- `Future<TimeAnchor?> capture()` → `{ ntpIso, stopwatch }`: one SNTP query (cached for
  the shift, re-synced periodically) + a `Stopwatch` started at sync. At photo time:
  `elapsed = stopwatch.elapsed`. Returns null when NTP is unreachable (offline) — caller
  queues anyway with `ntp_reference=null`.

### 4.4 `SyncFlushService` — `lib/services/sync_flush_service.dart`
The orchestrator. Public API: `Future<void> flush()` and `void start()/stop()`.
- **Trigger:** subscribe to `networkStatusProvider`; on `false→true` transition call
  `flush()`. Also flush opportunistically on app resume and every N minutes while online
  (belt-and-braces; cheap when the queue is empty).
- **Order:** wakefulness → GPS → photos, **oldest first** (`created_at` asc). Server is
  order-insensitive (§3 integrity doc) — this is purely for our own UI/log coherence.
- **GPS batching:** collect *all* due GPS rows for the shift, send as **one**
  `pings[]` batch (not one request per ping — DoD requirement). On success delete all;
  on transport/5xx bump every row's attempt.
- **Per-item retry/backoff** per the §4 table below.
- **Single-flight:** a mutex so connectivity flaps don't launch overlapping flushes.

### 4.5 Retry/backoff table (single source of truth, encode once)

| Outcome | Class | Action |
|---|---|---|
| transport error / timeout / **5xx** | retryable | `bumpAttempt`, backoff `min(2^attempt · base, cap)` (base 2s, cap 5m), jitter |
| `VALIDATION_ERROR` 422 | terminal | delete (drop), log non-prod |
| `SHIFT_NOT_ACTIVE` 409 | terminal | delete (shift ended/handed over) |
| `CHECK_NOT_FOUND` (wakefulness) | terminal | delete |
| `ALREADY_RESOLVED` (wakefulness) | **success** | delete (already done) |
| `NONCE_NOT_FOUND` (photo) | terminal | delete |
| `NONCE_ALREADY_USED` (photo) | **success** | delete (capture already landed) |
| unknown 4xx | terminal | delete + log (fail safe — don't loop forever) |

Encode as a pure function `FlushDecision classify(Object error/result)` → unit-testable
without the network. **Max attempts cap** (e.g. 12) → after that, drop + log, so a
permanently-bad row can't pin the queue forever.

---

## 5. Wiring into existing code (minimal, surgical edits)

1. **`gps_service.dart`** — in `_postPing`'s `catch`, instead of silently dropping,
   `enqueueGps(...)`. *Also* (subtle): when **online but the post throws**, that one ping
   is queued and picked up by the next flush. The online success path is untouched. The
   iOS heartbeat/stream keep producing pings; offline they accumulate in the queue.
2. **Photo capture (`photo_screen.dart` / photo provider)** — branch on connectivity at
   *submit*:
   - **Online:** unchanged — `uploadPhotos(...)` immediately (existing path).
   - **Offline:** `draw()` a nonce, capture the `TimeAnchor`, compute signatures with the
     existing `buildSignature`, persist file paths to the sandbox, `enqueuePhoto(...)`.
     Show "Saved — will upload when back online."
   - ⚠️ The **online photo request has a 90s server deadline**; an *offline* self-initiated
     photo uses a *pool nonce* (15-min TTL), not the request nonce. Keep these two flows
     distinct: a server-initiated `PHOTO_REQUEST` that can't reach the server in 90s is a
     **miss** (don't silently queue it as if answered) — only *self-initiated*/pool
     captures queue. Confirm this intent with backend, but the docs imply offline photos
     are pool-based, not request-based.
3. **Wakefulness offline answer** — when a scheduled TOTP challenge is answered offline,
   `enqueueWakefulness(checkId, code, window_reference, responded_at)`. Flush calls the
   already-existing `WakefulnessService.respond(..., isOffline:true)`.
4. **`secure_storage_service.dart`** — add `db_cipher_key` get/set; wipe in `clearSession`.
5. **`photo_service.dart`** — flush path must treat `NONCE_ALREADY_USED` as **success**,
   *not* a `PhotoRejectedException`. Add a flag/result so the flusher distinguishes
   "rejected" (terminal drop) from "already landed" (success). Online interactive path
   keeps surfacing rejections to the user as today.
6. **`api_config.dart`** — add `noncesPrefetch(id) => '/shifts/$id/nonces/prefetch'`.
7. **App bootstrap** — construct `OfflineQueueDb`, `SyncFlushService.start()` after auth;
   `stop()` + `clearAll()` on sign-out.

---

## 6. Test plan (all offline of the network)

- `classify()` decision table — one case per row of §4.5 (pure, no IO).
- Backoff schedule monotonic + capped + jittered within bounds.
- GPS flush coalesces N queued rows into **one** `pings[]` body (mock Dio, assert single
  POST with N pings); partial-failure bumps all, success deletes all.
- Flush order = wakefulness → GPS → photos, oldest-first (seed mixed `created_at`).
- `NoncePool.draw()` never returns an expired/used nonce; concurrent draws don't collide.
- Photo offline: signatures computed with the same `buildSignature` digest as online
  (reuse the existing signature test vector); `ntp_reference=null` path still enqueues.
- Idempotency: a `NONCE_ALREADY_USED` / `ALREADY_RESOLVED` response dequeues as success.
- Drift DB opens with the cipher key; wrong/missing key → fresh empty DB (no crash).
- Integration-ish: enqueue while "offline", toggle `networkStatusProvider` true →
  flusher drains to a mock server and the queue empties.

Target: keep the suite green (currently 91 tests) and add ~15–20.

---

## 7. Build order (one pass, but staged commits)

1. **Deps + DB skeleton** — add packages, `OfflineQueueDb`, cipher key in secure storage,
   open/clear on device (verify `cipher_version` on both platforms). *Commit.*
2. **`classify()` + `SyncFlushService` shell** — retry table + backoff, single-flight,
   connectivity trigger, empty-queue no-op. Fully unit-tested. *Commit.*
3. **GPS queue** — redirect `_postPing` catch → enqueue; batch flush. Device-verify the
   offline band appears on the dashboard (with Jerry). *Commit.* ← earliest visible win.
4. **Wakefulness queue** — enqueue offline answers; flush via existing `respond`. *Commit.*
5. **Nonce pool + TimeAnchor + offline photos** — prefetch, draw, NTP anchor, queue,
   flush; `NONCE_ALREADY_USED`→success. *Commit.*
6. **Polish** — `clearSession` wipe, app-resume flush, max-attempt drop, logging hygiene
   (no nonce/secret in logs), `flutter analyze` + full test run, HANDOFF.md entry. *Commit.*

---

## 8. Risks / open questions for backend (Jerry)

- **Offline photo = pool nonce, not request nonce?** Confirm a server-initiated
  `PHOTO_REQUEST` that goes unanswered offline is simply a miss, and only *self-initiated*
  offline captures use the prefetched pool. (Plan assumes yes per §2c of the contract.)
- **`/nonces/prefetch` response shape** — confirm it returns `{nonces:[{nonce_value,
  expires_at}], …}` (or similar) so `NoncePoolService` parses correctly.
- **`pings[]` batch upper bound?** A long offline stretch at 15s cadence = lots of pings
  (e.g. 1h ≈ 240). Is there a max batch size, or should we chunk (e.g. 200/req)? Plan
  will **chunk defensively** at ~200.
- **NTP source** — any preferred SNTP host, or is `time.google.com`/pool.ntp.org fine?

---

## 9. Definition of done (from the contract, restated)

- [ ] Captures persist to an **encrypted** queue while offline (GPS, wakefulness, photos +
      nonce/signature/NTP anchor).
- [ ] On reconnect, queue drains **wakefulness → GPS → photos**, oldest first.
- [ ] GPS flushes as a **batch** (`pings[]`), chunked if huge.
- [ ] Each offline photo uses a **distinct** prefetched nonce + its signature + NTP anchor.
- [ ] Retry/backoff follows §4.5; terminal 4xx dequeue, success-codes dequeue, no loops.
- [ ] No wall-clock sent as authoritative time; TOTP window + NTP anchor preserved verbatim.
- [ ] Online happy-path unchanged; both Android & iOS verified.
- [ ] `flutter analyze` clean, `flutter test` green, HANDOFF.md updated.
