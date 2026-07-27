# Backend asks — empty check schedules block on-device testing

**From:** Flutter app (Guard Monitor)
**To:** backend/dashboard (Jerry)
**Date:** 2026-07-22
**Re:** on-device testing of welfare + photo checks (online and offline)

> ✅ **RESOLVED 2026-07-22** — Jerry replied (see `BACKEND_REPLY_2026-07-22`). Net: **no app
> changes needed.** §1 `schedule:[]` was expected for a 30-min shift (use a ~2 h test shift);
> §2 tagging is endpoint-based (our `/respond` body is already clean); §2b expired photo-pending
> is a real gap with a **fix queued** server-side (our defensive handling stays); §3 manual trigger
> confirmed on `/wakefulness/pending`. Checklist below ticked. Kept for the record.

---

## TL;DR

I wired up the app side of the welfare/photo check flow (online push + `/wakefulness/pending`
poll + offline schedule + reminders + flush). But when I start a real shift on-device, **the
`POST /shifts/{id}/start` response returns empty schedules** — so **no scheduled check ever fires**,
online or offline. That's the one blocker. A couple of confirmations below too.

---

## 1. 🔴 BLOCKER — `wakefulness.schedule` and `photos.schedule` come back empty

Starting shift `SH-1031` on the live host, the start response was (trimmed):

```jsonc
"wakefulness": {
  "totp_seed": "S7PSQCC2AOEPTRTFISWHIQJWZKRPRYRI",
  "totp_period_seconds": 30,
  "totp_digits": 4,
  "response_seconds": 60,
  "schedule": []            // ← empty
},
"photos": {
  "schedule": [],           // ← empty
  "response_seconds": 90,
  "offline_nonce_ttl_minutes": 15,
  "max_photos_per_capture": 5
}
```

Shift window was `05:15–05:45Z` (30 min), `status: active`.

**Why this blocks everything:** the app drives all scheduled checks off these arrays — online (you
push at each mark) and offline (the app fires locally at each mark from the seed/pool). With
`schedule: []` there are **no marks**, so:

- no welfare check and no photo check fire — **online or offline**,
- the seed is useless (a key with no times to unlock),
- the app can't schedule its offline reminders either.

**Ask:**
- [ ] Have `POST /shifts/{id}/start` return **non-empty** `wakefulness.schedule` + `photos.schedule`
      — a list of ISO-8601 UTC due-times **inside the shift window**.
- [ ] For test shifts, please put a couple of marks **within the next few minutes** so we can
      actually observe a check fire during a session, e.g.:

```jsonc
"wakefulness": { "…": "…", "schedule": ["2026-07-22T05:20:00Z", "2026-07-22T05:35:00Z"] }
"photos":      { "…": "…", "schedule": ["2026-07-22T05:25:00Z"] }
```

**Questions on the schedule (so the app matches your intent):**
- [ ] Roughly **how many** welfare/photo checks per shift, and any **minimum spacing** between marks?
- [ ] Is the schedule **fixed at start**, or can the server add/adjust marks mid-shift (i.e. should
      the app re-read it, or trust the start payload for the whole shift)?
- [ ] Confirm the **same schedule** drives both the online dispatch (your push) and the offline
      path — i.e. a mark the app fires offline is the *same* check you'd have pushed online (so
      there's exactly one check per mark, never two).

---

## 2. 🟡 CONFIRM — online vs "Offline" tagging on the dashboard

During testing, a welfare check I answered **while online** showed as **"Offline"** on the
dashboard. Root cause was **app-side** (on iOS without APNs the app was answering scheduled checks
via `/wakefulness/offline`); **I've fixed that** — online checks now answer via
`/wakefulness/{id}/respond` and only genuinely-offline ones use `/wakefulness/offline`.

- [ ] Please confirm the dashboard tags a check **Offline purely by endpoint** — i.e. a check
      answered via `/respond` shows **Online**, and only `/wakefulness/offline` shows **Offline**.
      (If it's derived some other way — comms-gap window, timing — tell me, because that changes how
      we label.)

**How I'll verify once §1 is unblocked:** a **manual** supervisor-triggered welfare check while the
device is online → expect it to arrive (push or `/wakefulness/pending`) and land **Online**.

---

## 2b. 🟡 `GET /shifts/{id}/photos/pending` keeps returning an EXPIRED request

Found while testing: a photo request whose response window has **already passed** is still returned
by `GET /shifts/{id}/photos/pending`. On the app that caused a loop — the poll (and pull-to-refresh)
kept re-opening the dead request, and any capture then failed `NONCE_EXPIRED`.

**App-side:** fixed — the app now shows a request **once** (completed or missed) and won't re-open it,
and it closes an expired capture screen instead of retrying. So this is no longer breaking us.

**Backend ask (cleaner contract):**
- [ ] Once a photo request's window has expired (past `issued_at + response_seconds` / the nonce TTL),
      **stop returning it** on `GET /shifts/{id}/photos/pending` — the poll should only surface
      requests that are still **answerable**. (Same spirit as consuming a check on resolution.)
- [ ] Confirm whether an expired photo request raises its **CRITICAL "missed"** alert server-side on
      its own (we assume yes — the app does nothing on expiry now beyond closing the screen).

> If you already prune expired requests and this was a one-off (e.g. a stuck/rescheduled request),
> just say so — the app is defensive either way; this is about keeping the poll's contract clean.

---

## 3. 🟢 CONFIRM — manual trigger + the pending poll (our push-miss path)

Until APNs is live on our side, iOS has no push, so the app relies on
`GET /shifts/{id}/wakefulness/pending` to discover online challenges.

- [ ] Confirm a **manual supervisor-triggered** wakefulness check (dashboard button) creates a
      server check row that appears on `GET /shifts/{id}/wakefulness/pending` (with `check_id`,
      `code`, `issued_at`, `response_seconds`, `expires_at`) — so we can surface it in-app on iOS
      without push.

---

## Context you may want

- **APNs/FCM push:** we expect to have push keys configured in a few days. Until then, iOS uses the
  `/wakefulness/pending` + `/photos/pending` polls as the online path. The app is built to use push
  the moment it's available (no further backend change needed for that).
- Everything from `BACKEND_ASKS_2026-07-21.md` (offline flush endpoint, pending envelope, code
  padding) is confirmed and working — this doc is only the schedule + tagging follow-ups.

---

## Checklist for Jerry — ✅ all answered (2026-07-22)

- [x] ~~`POST /shifts/{id}/start` returns **non-empty** schedules~~ — **not a bug.** A 30-min shift
      is mathematically always empty (first welfare mark = start + 30–45 min, first photo = start +
      50–70 min). Generator stays as-is; **use a ~2 h test shift** to observe marks.
- [x] Counts/spacing: welfare **30–45 min**, photos **50–70 min**, ≤64 marks; **fixed at start**
      (cache on device — no re-fetch endpoint yet; one schedule drives online + offline, one check
      per mark, bar a rare reconnect race).
- [x] Offline tag = **endpoint-based** — *provided* online `/respond` omits `window_reference` /
      `is_offline`. Our `respond()` body is already clean. ✅
- [x] **Manual** welfare trigger confirmed on `GET /shifts/{id}/wakefulness/pending` (all 5 fields).
- [x] Expired photo requests — **fix queued** (poll filters on the live deadline); expiry raises the
      CRITICAL "missed" alert server-side on its own.

---

## 4. 🟡 NEW follow-up (from the offline-sync device test) — add `issued_at` to the wakefulness push

One small addition surfaced while fixing an app-side bug (tapping an **old** wakefulness notification
opened a dead challenge that then stranded the screen).

**Why:** the `WAKEFULNESS_CHALLENGE` FCM payload today is `{ type, check_id, shift_id, code,
response_seconds }` — **no `issued_at`**. Without it, when the guard taps an old notification the app
can't tell how stale the challenge is, so it can't safely anchor/expire the countdown. (The
`PHOTO_REQUEST` push already carries `issued_at` — we just need parity.)

**App-side interim:** we now **drop** a *tapped* wakefulness challenge that has no `issued_at` and let
the `/wakefulness/pending` poll re-surface a genuinely-live one with proper timing. That's safe but
means a legit fresh tap waits for the next poll instead of opening instantly.

**Ask:**

- [ ] Include `issued_at` (ISO-8601 UTC) in the `WAKEFULNESS_CHALLENGE` push data, same as
      `PHOTO_REQUEST`. Then a tapped challenge anchors to the server clock and opens instantly when
      live / is dropped cleanly when stale — no more waiting on the poll.

> Not a blocker — the app is correct either way. This just restores instant-open for a tapped live
> wakefulness challenge.
