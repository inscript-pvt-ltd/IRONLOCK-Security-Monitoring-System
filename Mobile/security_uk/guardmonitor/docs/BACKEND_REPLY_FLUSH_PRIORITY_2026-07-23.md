# Mobile reply — flush priority-ordering (shipped) + yes to the `backfill` flag

**Date:** 2026-07-23
**From:** Mobile (Guard Monitor app)
**To:** Jerry (backend / dashboard)
**Re:** your reply to `BACKEND_NOTE_FLUSH_PRIORITY_2026-07-23.md`
**Status:** Reorder shipped. **Yes — please wire up `backfill`.** App side is
already implemented and held behind an off-by-default flag, ready to flip on your
go-live confirmation. Details + two small contract questions below.

---

## TL;DR

| Item | Us |
|---|---|
| Reorder (wakefulness → photos → GPS, oldest-first) | **Shipped.** analyze clean, 168 tests pass. |
| Order-independence / idempotency | Understood — we lean on it. Nothing to change. |
| Keep GPS as one big-batch-last drain, don't split into small requests | **Confirmed** — that's exactly what we do (≤200/req, contiguous oldest→newest). |
| Never send a "current position" ping ahead of the backlog | **Confirmed** — we have no position-first-on-reconnect logic. |
| `backfill:true` flag | **Yes, please implement it.** Ours is coded + gated OFF, awaiting your go-live. |

---

## 1. Reorder — shipped

Live now: `wakefulness → photos → GPS`, oldest-first, single-flight. Plus two extra
drain triggers (an enqueue-kick on any welfare/photo buffer, and a 60s heartbeat
backstop). `flutter analyze` clean, full suite green (168, +2 new: photos-before-GPS
ordering, enqueue-signal fires).

## 2. Order-independence + duplicates — understood

Thanks for tracing all three ingest paths. We rely on exactly what you confirmed:
disjoint tables, append-only `shift_events`, per-item time proofs, and idempotent
nonce/window handling (`NONCE_ALREADY_USED` / `ALREADY_RESOLVED`). The enqueue-kick
+ heartbeat will produce a few more mid-retry re-sends — good to have that blessed.

## 3. GPS shape — confirmed we already match your guidance

- **One contiguous drain, big-batch-last.** Every buffered ping flushes after the
  photo/welfare drain, oldest→newest, chunked at **≤200/request**. We do **not**
  split GPS into many small requests.
- **No position-first ping.** We never send a lone current-position ping ahead of
  the historical backlog to speed the live map — the flush only replays the queue.
  (The only live GPS traffic is the normal ~15s tick posted directly by
  `GpsService`; it never goes through the reconnect drain.)

## 4. `backfill:true` — yes, please wire it up

Your §5 edge (chunk #1 refreshes last-seen → chunk #2 of a >200-ping backlog is
misread as a live tick) is exactly the kind of latent thing we'd rather close now
than debug during a real incident. We've **already implemented the producer side**
so there's nothing left for us but a one-line flip once your side is live:

- Every reconnect-drain `/shifts/{id}/locations` POST will carry `"backfill": true`
  at the request-body top level, alongside `pings` — on **every chunk** of a
  multi-request backlog, not just the first.
- It's honest by construction: our flush queue only ever holds pings that failed
  live delivery, so **100% of `_flushGps` requests are backfills**. Live 15s ticks
  post on a separate path and will **never** carry the flag.
- It's currently gated behind `SyncFlushService.sendGpsBackfillFlag = false`, so we
  send nothing today (per your "don't send until confirmed"). On your word we set
  it `true` and redeploy — no logic change.

### Two small contract questions before you ship

1. **Placement/spelling** — is `{"pings":[...], "backfill": true}` (boolean, request
   body, top level) the shape you want? Or would you prefer it on each ping, or as a
   header/query param? We'll match whatever you document.
2. **Semantics when present** — confirm that `backfill:true` **forces** the
   reconnect/replay path (suppress per-ping paging, one net-state decision in
   `finalizeFlush`) **regardless** of the pre-batch last-seen — so all chunks of one
   backlog stay coherent even though earlier chunks refreshed the live row.

Once you confirm + document it in `MOBILE_API_INTEGRATION.md §6.1`, we flip the flag.

## 5. Live-map-first — not needed now

We're happy with current live-map recovery latency; no need for a position-first
story today. If it ever matters, we agree the `backfill` flag is the clean lever and
we'll revisit then — not a position-first ping.

---

## Net

- Reorder is live.
- Nothing to change for order/idempotency/GPS-batching — we already match your model.
- Producer side of `backfill` is coded and safely dark. **Please wire the server
  side + answer the two contract questions above, and we'll go live with one flip.**
