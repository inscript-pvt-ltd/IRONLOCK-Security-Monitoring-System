# Manual test — GPS `backfill` flag (multi-chunk reconnect routing)

**Date:** 2026-07-23
**Owner:** Jerry (backend / dashboard)
**Scope:** Validate `POST /shifts/{id}/locations` `backfill:true` routing before the
app dev flips `sendGpsBackfillFlag = true`.
**Code under test:** `app/Http/Controllers/Mobile/GPSController.php` (lines 71–133).

> ⚠️ **Run this in DEV/staging against MariaDB — NOT live production.** The behaviour
> being tested is zone-exit paging. A mistake on a real shift can dispatch a real
> `CheckZoneExitJob` and page a real supervisor about a real guard. Use a throwaway
> test guard + test shift.

---

## 1. What bug we're proving is closed

The server decides per HTTP request whether a `/locations` POST is a **live 15s tick**
or a **buffered reconnect drain**, keyed on the guard's *pre-batch* last-seen:

```php
$isLiveTick = !$isBackfill
    && ($pre['shift_id'] === $shift->id)
    && $lastSeen instanceof \Carbon\Carbon
    && $lastSeen->diffInSeconds(now()) <= $threshold;   // threshold = 60s
```

A reconnect backlog bigger than one 200-ping batch splits across ≥2 requests.
**Chunk #1** processes as a reconnect (last-seen stale) — but writing it refreshes the
live row, so **chunk #2** sees a *fresh* last-seen and, without the flag, is misread as
a **live tick** → it debounces + inline-dispatches a zone-exit on **historical** pings.
That violates "no retroactive paging."

`backfill:true` forces `isLiveTick = false` regardless of last-seen, so **every chunk**
of a backlog stays on the non-paging replay path.

**We are proving two things:**
1. With `backfill:true`, chunk #2 routes as reconnect (`isLiveTick=false`) — the fix.
2. Without the flag, chunk #2 *does* get misrouted as live (`isLiveTick=true`) — proving
   the flag is load-bearing, not a no-op.

---

## 2. Prerequisites

- Dev/staging environment pointed at the dev **MariaDB** (not SQLite).
- A **test guard** with a valid bearer token (the same auth the app uses for
  `guard.auth`).
- One **active shift** owned by that guard (`status = active`). A `geofence_id` is only
  needed for the optional end-to-end Test 2; Test 1 works with any coordinates.
- **`QUEUE_CONNECTION=database`** in `.env`, and **the queue worker STOPPED.** This lets
  us inspect dispatched jobs in the `jobs` table without any job ever executing or
  paging anyone.
- Set these once for the shell examples below:

```bash
BASE_URL="http://localhost:8000"     # your dev host
TOKEN="<test-guard-bearer-token>"
SHIFT_ID="<active-test-shift-id>"
```

---

## 3. Test 1 — routing decision (definitive, fast)

This tests the exact line that changed. Coordinates are irrelevant here.

### 3.1 Add one temporary log line

In `GPSController::ping`, immediately **after** line 77 (the `$isLiveTick = ...` block):

```php
\Log::info('BACKFILL_TEST', [
    'backfill_flag' => $isBackfill,
    'is_live_tick'  => $isLiveTick,
    'pre_shift_id'  => $pre['shift_id'],
    'shift_id'      => $shift->id,
    'last_seen_at'  => optional($lastSeen)->toISOString(),
    'ping_count'    => is_array($pings) ? count($pings) : 0,
]);
```

Then `php artisan optimize:clear` (or just restart the dev server) and
`tail -f storage/logs/laravel.log` in a second terminal.

### 3.2 Fire two chunks back-to-back, both flagged

Send them within a few seconds of each other so chunk #1 leaves last-seen *fresh* for
chunk #2 (this is what reproduces the edge).

```bash
# Chunk 1 — oldest pings, backfill:true
curl -s -X POST "$BASE_URL/api/shifts/$SHIFT_ID/locations" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"backfill": true, "pings": [
        {"latitude": 51.5007, "longitude": -0.1246, "recorded_at": "2026-07-23T13:00:00Z"},
        {"latitude": 51.5008, "longitude": -0.1247, "recorded_at": "2026-07-23T13:00:15Z"}
      ]}'

# Chunk 2 — next pings, backfill:true (sent immediately after)
curl -s -X POST "$BASE_URL/api/shifts/$SHIFT_ID/locations" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"backfill": true, "pings": [
        {"latitude": 51.5009, "longitude": -0.1248, "recorded_at": "2026-07-23T13:00:30Z"},
        {"latitude": 51.5010, "longitude": -0.1249, "recorded_at": "2026-07-23T13:00:45Z"}
      ]}'
```

**PASS:** both log lines show `"is_live_tick": false`. Chunk #2 shows a *fresh*
`last_seen_at` (just written by chunk #1) yet still routes as reconnect — that is the
flag doing its job.

### 3.3 Contrast — prove the flag is load-bearing

Repeat chunk #2 but **omit** `backfill` (send another chunk 1 first so last-seen is
fresh again):

```bash
# Refresh last-seen with a flagged chunk 1
curl -s -X POST "$BASE_URL/api/shifts/$SHIFT_ID/locations" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"backfill": true, "pings": [
        {"latitude": 51.5007, "longitude": -0.1246, "recorded_at": "2026-07-23T13:01:00Z"}
      ]}'

# Chunk 2 WITHOUT the flag — expected to be misrouted as live
curl -s -X POST "$BASE_URL/api/shifts/$SHIFT_ID/locations" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"pings": [
        {"latitude": 51.5009, "longitude": -0.1248, "recorded_at": "2026-07-23T13:01:15Z"}
      ]}'
```

**PASS:** the unflagged chunk logs `"is_live_tick": true`. This confirms the pre-batch
last-seen really was fresh and that only the flag prevents the misroute (i.e. the fix is
not a coincidence of some other guard).

---

## 4. Test 2 — end-to-end "no retroactive page" (optional, deeper)

Use a shift whose `geofence_id` is set, and pick coordinates relative to that polygon:
- `IN_LAT/IN_LNG` = a point **inside** the geofence.
- `OUT_LAT/OUT_LNG` = a point **outside** the geofence.

Simulate a backlog where the guard was inside, then stepped outside — split across two
flagged chunks:

```bash
# Chunk 1 — guard still INSIDE (backfill)
curl -s -X POST "$BASE_URL/api/shifts/$SHIFT_ID/locations" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"backfill": true, "pings": [
        {"latitude": IN_LAT, "longitude": IN_LNG, "recorded_at": "2026-07-23T13:00:00Z"},
        {"latitude": IN_LAT, "longitude": IN_LNG, "recorded_at": "2026-07-23T13:00:15Z"}
      ]}'

# Chunk 2 — guard now OUTSIDE (backfill), last-seen already fresh from chunk 1
curl -s -X POST "$BASE_URL/api/shifts/$SHIFT_ID/locations" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"backfill": true, "pings": [
        {"latitude": OUT_LAT, "longitude": OUT_LNG, "recorded_at": "2026-07-23T13:00:30Z"},
        {"latitude": OUT_LAT, "longitude": OUT_LNG, "recorded_at": "2026-07-23T13:00:45Z"}
      ]}'
```

**Expected:** no **per-ping / inline** zone-exit job from chunk #2's historical pings.
A single **present-state** decision is made once in `finalizeFlush` (the guard genuinely
ends outside, so a delayed re-confirming check is legitimate) — and its `CheckZoneExitJob`
re-checks the *live* row before it can raise, so it won't page on stale data.

Now run the **buggy** contrast (chunk 2 without the flag): you should see the misrouted
live path produce the inline zone-exit dispatch on historical pings — the exact
retroactive page the flag suppresses.

---

## 5. How to observe (SQL)

With the worker stopped, dispatched jobs sit in the `jobs` table:

```sql
-- Any zone-exit jobs queued? (CheckZoneExitJob appears in the payload)
SELECT id, queue, available_at FROM jobs ORDER BY id DESC LIMIT 20;

-- Audit trail for the shift — expect exactly ONE COMMS_GAP/SYNC_FLUSH set per backlog,
-- and (Test 2, flagged) no retroactive ZONE_EXIT from historical pings.
SELECT event_type, recorded_at, metadata
FROM shift_events
WHERE shift_id = '<SHIFT_ID>'
ORDER BY recorded_at DESC
LIMIT 30;

-- The live row that chunk #1 refreshes (drives the last-seen heuristic)
SELECT guard_id, shift_id, zone_status, updated_at
FROM guard_locations WHERE guard_id = '<GUARD_ID>';
```

Inspect a queued job's class without running it:

```sql
SELECT payload FROM jobs ORDER BY id DESC LIMIT 1\G   -- look for CheckZoneExitJob
```

---

## 6. Pass / fail summary

| # | Scenario | Expected | Pass? |
|---|----------|----------|-------|
| 1a | Chunk 1, `backfill:true` | log `is_live_tick=false` | ☐ |
| 1b | Chunk 2, `backfill:true`, fresh last-seen | log `is_live_tick=false` | ☐ |
| 1c | Chunk 2, **no flag**, fresh last-seen | log `is_live_tick=true` (misroute reproduced) | ☐ |
| 2a | Flagged inside→outside backlog | no inline per-ping zone-exit; ≤1 present-state job; one COMMS_GAP set | ☐ |
| 2b | Unflagged chunk 2 (contrast) | inline zone-exit dispatched on historical pings | ☐ |

If 1a/1b/1c all pass, the routing fix is proven. 2a/2b add end-to-end confidence.

---

## 7. Cleanup

- **Remove the temporary `\Log::info('BACKFILL_TEST', …)` line** and
  `php artisan optimize:clear`.
- Clear the test rows if desired: truncate the test `jobs`, delete the test shift's
  `shift_events` / `guard_locations` rows, or just reset the test guard's shift.
- Restore `QUEUE_CONNECTION` and restart the worker.
- Green-light the app dev to set `sendGpsBackfillFlag = true` once 1a–1c pass.
