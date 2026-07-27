# Code audit — Guard Monitor app (manual review)

**Date:** 2026-07-24
**Reviewer:** Mobile (manual, file-by-file — not test-driven)
**Scope:** Offline-sync (Phase 7) surface + overlay/screen lifecycle, providers, and
services. `flutter analyze` clean; 168 tests pass. These are issues found by
**reading the code**, not by tests — a passing suite does not cover them.

Severity key: 🔴 data-loss / correctness · 🟠 medium (perf / edge correctness) ·
🟡 low / hygiene · ✅ reviewed and cleared (documented so we don't re-chase it).

> **Status 2026-07-24:** all findings below are **FIXED** in code except **L1/L2**
> (the temp diagnostic + the unverified defunct-element crash — held together
> pending one on-device confirmation). `flutter analyze` clean; **176 tests pass**
> (+8 new: H1 GPS/photo drain-after-shift-end, M2 exactly-4 code, M5 UTC parsing).
> Backend-contract follow-ups for M2/M4/M5 are in `docs/BACKEND_ASKS_2026-07-24.md`.

---

## 🔴 H1 — Offline GPS + photos are stranded when a shift ends with a backlog

**Files:** `lib/services/sync_flush_service.dart`, `lib/providers/shift_provider.dart`,
`lib/data/offline_queue_db.dart`

**Status:** open · **Impact:** silent, permanent loss of a shift's final GPS trail and
any offline proof photo · **Contradicts documented intent.**

The queue is meant to outlive the shift:
> `offline_queue_db.dart:43` — *"the flush must survive the shift ending mid-backlog."*

But the flush engine gates GPS/photo draining on the **currently active** shift:
- `sync_flush_service.dart:333-335` — `_currentShiftId = () => shift.active ? shift.id : null`
- `sync_flush_service.dart:138-141` — `_flushPhotos`/`_flushGps` run only `if (shiftId != null)`
- `offline_queue_db.dart:139` / `:196` — `dueGps`/`duePhotos` filter rows **by that shiftId**

And both end paths clear `active`/`id` **without flushing first**:
- `shift_provider.dart:300` — `end()` → `state = const ShiftState()`
- `shift_provider.dart:319` — `reconcileServerClosed()` → `state = const ShiftState()`

**Clearest failure (auto-close):** guard goes offline near `scheduled_end`, accumulates a
GPS trail + an offline photo. Server auto-closes at `scheduled_end + grace`. Guard
reconnects → `currentShiftProvider.fetch()` returns `completed/auto` →
`reconcileServerClosed()` sets `active = false` → `_currentShiftId()` returns null → those
rows are never flushed and sit in the encrypted queue until sign-out wipes them.

**Also a race on manual end:** the offline→online edge fires `flush()` (async) at the same
moment the guard taps END; if END wins and sets `active = false` before that cycle reaches
GPS/photos, the remaining rows strand.

**Why wakefulness is safe but GPS/photos aren't** — the tell: `_flushWakefulness`
(`sync_flush_service.dart:220-260`) uses each **row's own** `shiftId` and has **no**
current-shift gate. GPS/photos use the live shift id. That asymmetry is the bug.

**Fix (recommended):** make GPS/photo flush shift-independent, mirroring wakefulness —
drain all due rows grouped by each row's own `shiftId`, not the live shift. Requires:
- `dueGps` / `duePhotos` variants (or a `distinctShiftIds()` query) that don't filter on
  the current shift, and
- `_runCycle` to flush GPS/photos even when `_currentShiftId()` is null.
Add a regression test: enqueue GPS+photo, set shift inactive, flush → both drain.

**Alternative (smaller, weaker):** flush synchronously inside `end()` /
`reconcileServerClosed()` before clearing state. Doesn't cover app-killed-then-relaunched
-inactive, so the shift-independent flush is preferred.

---

## 🟠 M1 — `totalPending()` full-table-scans every poll to produce a count

**File:** `lib/data/offline_queue_db.dart:254-259`

`totalPending()` does `select(gpsQueue).get()` + wakefulness + photos and returns the sum of
`.length`. It **materialises every row** just to count them. It runs on the 20 s home poll
and on pull-to-refresh, and GPS accumulates ~1 row / 15 s offline — a multi-hour offline
stretch is thousands of rows loaded into memory every 20 s to render one chip.

**Fix:** use COUNT() aggregates, e.g.
`select(gpsQueue).addColumns([countAll()])` (three cheap scalar queries), or a single
`customSelect` with `SELECT (SELECT COUNT(*)…) + …`.

---

## 🟠 M2 — Wakefulness code must be **exactly 4 digits** (online + offline); >4 is currently unpassable

**File:** `lib/providers/wakefulness_provider.dart:189-192` (`_normalizeCode`) + `:196`

**Invariant (product rule):** a wakefulness code is **always exactly 4 digits** — never fewer,
never more — identical on the online (server-push) and offline (local TOTP) paths. The pin
entry is fixed at 4 and any incoming code must resolve to exactly 4 before it's shown.

**What's already correct — keep it.** A TOTP with `digits: 4` always yields a value in
`0000–9999`, so a **short** code is a real 4-digit code whose **leading zero was dropped** in
transport (server/notification sends `472` for a true `0472`). `_normalizeCode`'s
`padLeft(4, '0')` **restores** it — the guard enters `0472`, which is exactly what the server
re-derives for that window. This short→4 padding is legitimate and must stay; removing it would
re-break the leading-zero case.

**The gap — a code >4 digits.** `_normalizeCode` strips non-digits then only `padLeft(4)` — it
does **not** handle a value that is already **longer** than 4. A 4-digit TOTP can never exceed
4 digits, so a >4 value is **malformed by definition**. Today it's shown as-is while entry is
hard-capped at 4 (`addDigit`, `:196`), so `state.entry` can never equal `state.code` → the
guard **fails a check they answered correctly** → false supervisor alert (and, offline, a wrong
buffered answer).

**Fix (enforce the exactly-4 invariant):** in `_normalizeCode`, after stripping non-digits,
require the result to be **exactly 4** — pad a short value (leading-zero restore, as now) and
treat **length > 4 (or 0) as invalid**. An invalid code must **not raise a passable
challenge**: skip it and stay idle so the server raises its own missed-check, rather than
showing a code the guard can never match. Apply on **both** ingest paths (`trigger` /
`triggerLocal`) so online and offline enforce identically. Add tests: `472 → 0472` (shown &
passable), `12345 → invalid` (no challenge raised).

---

## 🟠 M3 — Nonce + durable files leak on a partial offline-photo enqueue

**File:** `lib/services/offline_photo_service.dart:42-78`

`enqueueCapture` (a) draws a single-use pool nonce (`:42`, marks it `drawn` in the DB), then
(b) copies files to durable storage (`_persistFiles`, `:51`), then (c) computes signatures by
reading each file (`:54-55`), then (d) inserts the queue row (`:67`).

If (c) or (d) throws (file read error, DB write failure), the function propagates without
rolling back: the **nonce stays consumed** (lost forever — pool depth silently drops) and the
**durable copies leak** (never referenced by any row, so nothing ever deletes them). The
caller treats a throw as "capture lost" and records a miss, but the side effects persist.

Low probability, but each occurrence permanently burns a scarce offline credential.
**Fix:** wrap (b)-(d) so a failure after `draw()` deletes the copied files; consider drawing
the nonce **last** (after files+signatures are ready) so a pre-insert failure costs nothing.

---

## 🟡 L1 — Temp diagnostic still in `main.dart`

**File:** `lib/main.dart:22-26`

`FlutterError.onError = (d) => dumpErrorToConsole(d, forceReport: true)` (debug-only) is
scaffolding left in to catch the L2 crash's stack. Harmless in release, but remove once L2
is confirmed closed.

---

## 🟡 L2 — Defunct-element assertion: unverified, not located

**Files:** `lib/overlays/wakefulness_overlay.dart`, `lib/screens/photo/photo_screen.dart`,
`lib/screens/home/home_screen.dart`

Previously spammed `_lifecycleState != _ElementLifecycle.defunct` ~1/s during a
welfare/photo overlay. **A full read of every dispose/listen/timer path found no code path
that produces it** — the lifecycle handling is correct (notifiers captured in `initState`,
not `ref`, for dispose; `Future.microtask` deferral of `reset()`; `mounted` guards before
every `Navigator`/`setState`; single-flight `_challengeQueue`; `_wakefulnessPresenting` /
`_seenPhotoRequestIds` dedup). Best hypothesis: the earlier `_close()` change already fixed
it. **Cannot be proven dead by reading — needs one on-device run to confirm.** Track as
open-unverified, not a located defect.

---

## 🟡 L3 — `PhotoProvisioning.dueMark` assumes an ascending schedule

**File:** `lib/providers/photo_schedule_provider.dart:68-78`

`dueMark` returns the **first list element** that is due and in-window. If the server ever
returns `schedule[]` unsorted, it fires marks in list order, not chronological order (a
later-listed-but-earlier mark could be skipped until its window lapses). Today the server
sends them sorted, so it's latent. **Fix:** sort `schedule` ascending on parse
(`fromJson`) so ordering is guaranteed regardless of server output.

---

## 🟠 M4 — Top-level shift fields parse non-defensively; a null time drops the whole shift

**File:** `lib/models/current_shift_model.dart:106-136`

`fromJson` guards the **nested** site/geofence (`_parseNested`, with a comment at `:124-127`
explaining that a malformed sub-object must not drop the whole shift). But the **top-level**
required fields are unguarded hard casts:
- `id: json['id'] as String` (`:108`)
- `status: json['status'] as String` (`:110`)
- `scheduledStart: DateTime.parse(json['scheduled_start'] as String)` (`:111`)
- `scheduledEnd: DateTime.parse(json['scheduled_end'] as String)` (`:112`)

If the server sends a null/missing `scheduled_start` or `scheduled_end` — which the backend
**has** done for active shifts (see the known `/shifts/current` null bug) — the cast/parse
throws out of the entire shift parse. On the 20 s poll that silently strands the guard on a
disabled START button with no explanation: exactly the failure the nested-object guard was
added to prevent, but the top-level fields don't have it.

**Fix:** parse the top-level required fields defensively too — either surface a typed
"malformed shift" the UI can explain, or tolerate a null time (fall back / skip the update)
instead of dropping the shift. At minimum, don't let one null field blank the whole screen.

---

## 🟠 M5 — Datetime parse assumes the server always includes a zone designator

**File:** `lib/models/current_shift_model.dart:111-118`

`DateTime.parse(...).toLocal()` is correct **only if** the server string carries a `Z` or a
numeric offset. Dart's `DateTime.parse` treats a **zone-less** string (`2026-07-24T13:00:00`)
as **device-local**, so `.toLocal()` is then a no-op and the wall-clock is silently wrong by
the device's UTC offset — which would mis-time the START window, the countdowns, and the
"you can begin at HH:MM" hint. This directly concerns the UTC-localise rule
(`backend-times-are-utc-localize-on-device`).

**Fix:** verify the backend always emits UTC with `Z` (or an offset). Defensively, if a parsed
value `isUtc == false` and the contract says UTC, treat it as UTC
(`DateTime.parse(s).isUtc ? … : DateTime.parse('${s}Z')`-style normalisation) before
`.toLocal()`. Add a parse test with a zone-less input.

---

## 🟡 L4 — Notification id collisions via `hashCode & 0xFFF`

**File:** `lib/services/notification_service.dart:281` (`showPhotoRequest`), `:330`
(`showPhotoReview`)

Ids are derived as `base + (requestId.hashCode & 0xFFF)` — only **4096 slots**. Two distinct
request (or review) ids can collide in the low 12 bits, so one notification silently
**replaces** another (e.g. a second pending photo request wipes the first's prompt). Also
`String.hashCode` is not guaranteed identical across the **FCM background isolate** (where
`showPhotoRequest` fires at arrival) and the **foreground** (where the poll fires it), so the
"replace, don't stack" intent can break the other way and **duplicate** a prompt.

Low impact (a missed/duplicated prompt, not a crash), but real. **Fix:** derive the id from a
stable numeric (e.g. a monotonic per-request counter, or a wider hash), or accept the tradeoff
explicitly. At minimum note it so it isn't mistaken for a backend issue.

---

## 🟡 L5 — iOS 64 pending-notification cap isn't budgeted across types

**File:** `lib/services/notification_service.dart:36` (`_maxScheduledChecks = 64`)

Each reminder type is capped at 64 **independently** (`_scheduleChecks` breaks at
`_maxScheduledChecks`). iOS hard-limits **total** pending local notifications to 64 across the
app, so wakefulness (≤64) + photo (≤64) + shift-end (1) can exceed it on a long shift, and iOS
**silently drops** the overflow — and offline local reminders are the *only* prompt an offline
guard gets. Normal shifts schedule ~20-25 total, so it's a ceiling, not a live break (already
noted in the code comment). **Fix:** budget a single shared cap across both types (e.g. cap
wakefulness+photo+1 ≤ 64), oldest/soonest first.

---

## 🟡 L6 — Shift-end reminder uses an inexact alarm

**File:** `lib/services/notification_service.dart:130`

`scheduleShiftEnd` uses `AndroidScheduleMode.inexactAllowWhileIdle`, while the welfare/photo
check reminders use `exactAllowWhileIdle`. Under Doze the shift-end reminder can batch and fire
late. It's a *reminder* (the backend auto-close is the real safety net), so this is minor — but
inconsistent with the "exact alarms are core" rationale in `requestPermission`. **Fix (optional):**
use exact-with-inexact-fallback here too, for consistency.

---

## 🟡 L7 — Location-services gate blocks the entire app, including pre-shift + Sign Out

**File:** `lib/screens/home/home_screen.dart:778`

`if (!locationOn) Positioned.fill(LocationRequiredOverlay())` is **not** gated on
`shift.active`, so when device location services are off the full-screen, non-dismissable gate
covers the app even when **no shift is running** — blocking the pre-shift screen **and the Sign
Out button**. GPS only matters during a shift, so blocking the idle/logged-in-wrong-account
case can trap a guard who just wants to sign out. Likely a deliberate "location is required for
the job" call, but worth confirming. **Fix (if unintended):** gate the overlay on
`shift.active` (or at least keep Sign Out reachable underneath it).

---

## 🟡 L8 — Seen-review set grows unbounded

**File:** `lib/providers/photo_review_provider.dart:18, 29, 59`

`_seen` and its persisted backing list (`addSeenReviewId`) have **no cap**, unlike the
wakefulness `_handled` (capped 200) and `_seenPhotoRequestIds` (capped 50). Over a long-lived
install the set and the stored list grow without bound. Impact is tiny (memory + a slowly
growing secure-storage entry), but it's an inconsistency. **Fix:** bound it (ring-buffer /
cap) like the other dedup sets.

---

## Note on M4's blast radius (confirmed reachable)

`ShiftService.fetchCurrent` (`shift_service.dart:21`) feeds the raw shift map straight into
`CurrentShiftModel.fromJson`, so a null/missing `scheduled_start` or `scheduled_end` throws out
of `fetchCurrent` itself — confirming M4 is on the live 20 s-poll path, not a theoretical edge.
`startShift`/`endShift` are safe (they use the defensive `_parseTime`); only the
`fromJson` constructor is exposed.

---

## ✅ Reviewed and cleared (so we don't re-chase these)

- **SQLCipher key interpolation** — `offline_queue_db.dart:290,302` interpolate the cipher
  key into `PRAGMA key = '$key'`. The key is **base64** (`secure_storage_service.dart:149`),
  whose alphabet (`A-Za-z0-9+/=`) contains no single-quote, so it can't break the statement
  or inject. Safe. (A raw hex-key form would be more conventional but isn't needed.)
- **`backoffDelay` shift** — `sync_retry.dart:106-108` computes `base << attempts`. `attempts`
  is clamped to 30 and, in practice, capped at `kMaxFlushAttempts=12`; result is `min`-capped
  at 5 min and runs on 64-bit mobile ints. No overflow. (Would matter only on JS/web.)
- **Riverpod dispose safety** — overlay + photo screen capture their notifier in `initState`
  and reset via `Future.microtask` in `dispose`; correct per the earlier crash fixes.
- **Online vs offline welfare labelling** — the local TOTP scheduler is correctly gated on
  `!online` (`home_screen.dart:425-428`), so an online check is answered via `/respond`
  (recorded Online), not `/wakefulness/offline`. The earlier "mislabelled Offline" bug stays
  fixed.
- **Flush single-flight + retry classification** — `flush()` coalescing and `classifyFlush`
  (success/retry/drop, idempotent codes → success) are sound.

---

## Suggested order of work

1. **H1** — real data loss; fix + regression test. (Biggest win.)
2. **M4 / M5** — shift-parse robustness + timezone: both can silently strand or mis-time the
   guard, and both intersect known backend quirks. Cheap, high-value.
3. **M1** — cheap COUNT() swap; removes a per-poll full scan.
4. **M2 / M3** — small defensive fixes, batchable.
5. **L4 / L5 / L6** — notification hygiene (id collisions, iOS cap budget, exact alarm).
6. **L1** — remove once **L2** is confirmed on device.

---

## Coverage map (what this audit read)

| Area | Files | Verdict |
|---|---|---|
| Offline flush engine | `sync_flush_service`, `sync_retry` | H1, cleared retry logic |
| Offline queue DB | `offline_queue_db` | M1, cleared cipher/migration |
| Offline photo + nonce | `offline_photo_service`, `nonce_pool_service`, `time_anchor_service` | M3, rest sound |
| Wakefulness | `wakefulness_provider`, `wakefulness_service` | M2, rest sound |
| Photo capture | `photo_provider`, `photo_schedule_provider`, `photo_screen` | M3/L3, lifecycle sound |
| Overlay lifecycle | `wakefulness_overlay`, `home_screen` | L2 (unverified), sound |
| Networking / auth | `api_client`, `auth_provider`, `auth_service` | all cleared |
| Push | `push_router`, `push_messaging_service` | all cleared |
| Notifications | `notification_service` | L4/L5/L6 |
| HMAC signing | `photo_service` | cleared |
| Connectivity | `connectivity_service` | cleared |
| Models | `current_shift_model` | M4/M5 |

**Not yet deep-read** (lower risk, next pass if wanted): `gps_service` internals,
`shift_service`, `login_screen`, `end_shift_sheet`, `shift_access_link`, the remaining
overlays (`permission_gate`, `location_required`, `privacy_notice`), `device_info_service`,
`secure_storage_service` (key handling spot-checked, not fully read).
