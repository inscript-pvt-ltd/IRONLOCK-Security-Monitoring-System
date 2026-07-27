# Backend asks — Wakefulness offline flush, pending poll, code padding

**From:** Flutter app (Guard Monitor)
**To:** backend/dashboard (Jerry)
**Date:** 2026-07-21
**Re:** `FLUTTER_HANDOFF_WAKEFULNESS_OFFLINE_AND_UX.md` (2026-07-06) + two device bugs

> ✅ **RESOLVED — see `BACKEND_REPLY_2026-07-21.md`.** All four items confirmed live on the
> branded host. `/wakefulness/pending` envelope pinned to **`data.challenges[]`** (empty array =
> none) — app parser + tests aligned to it. Code padding: no backend change (the zero-loss was an
> app-side parse; our zero-pad-on-receipt fix covers it). App side is contract-complete; only the
> **on-device verification pass** remains.

---

## TL;DR

The app side of your 2026-07-06 handoff is **implemented** (offline wakefulness flush +
`wakefulness/pending` poll + foreground in-app prompt). Before it works end-to-end against the
live host I need a few things **confirmed or changed on the backend**. There's also **one small
push-payload fix** that caused a real field bug.

Nothing here blocks the app from compiling/running — where the backend isn't ready the app
degrades safely (a 404 is swallowed, an offline answer just stays queued). But the feature is only
truly done once the items below are true.

---

## 1. 🔴 MUST — deploy the two new endpoints on the live host

The app now calls these on `https://dashboard.ironlock.co.uk/api/mobile/v1`. Please confirm both
are **deployed and serving on production** (your handoff says they're live — I just need it
confirmed on the branded host, since device sign-in is the real test).

### 1a. `POST /shifts/{shiftId}/wakefulness/offline`

Flushes a wakefulness answer that fired on-device **while offline** (no server `check_id`).

**Request body the app sends:**

```json
{
  "window_reference": 1782342,
  "code": "4821",
  "scheduled_at": "2026-07-06T10:00:00.000Z",
  "responded_at": "2026-07-06T10:00:12.000Z"
}
```

- `window_reference` (int, required) — TOTP step = `floor(unixSeconds / 30)`.
- `code` (string, required) — the digits the guard typed.
- `scheduled_at` / `responded_at` (ISO-8601 UTC, optional) — the app **always** sends
  `scheduled_at` (the schedule mark) when it has it, so please use it for an exact timeline.

**Confirm:**
- [ ] **Idempotent per `(shift, window_reference)`** — a re-flush returns **200** with
  `reason: "ALREADY_RESOLVED"` (no duplicate row, no double event). The app treats *any* 200 as
  "done, dequeue"; it retries **only** on 5xx / network. If a duplicate ever comes back as a 4xx,
  the app will **drop** the answer — so duplicates must be 200.
- [ ] A wrong code is a normal **200** `{ "result": "FAILED" }` (recorded, no retroactive
  CRITICAL) — **not** a 4xx.
- [ ] Error codes are as documented: `404 SHIFT_NOT_FOUND`, `409 SEED_UNAVAILABLE`,
  `422 VALIDATION_ERROR` (these are terminal → the app drops, doesn't retry).

### 1b. `GET /shifts/{shiftId}/wakefulness/pending`

Push-miss fallback — the app polls this (on foreground + every ~20s while active) so it can raise
the code-entry sheet **in-app** when the FCM push was missed (notably **iOS with no APNs**).

**Confirm the response carries:** `check_id`, `code`, `issued_at`, `response_seconds`, `expires_at`.

The app's parser is envelope-tolerant (it accepts `data.challenge`, a `data.challenges[]` array, a
bare object, `pending:false`, etc.), but please tell me the **actual shape** you return so I can
pin it. On finding a challenge the app calls `POST /wakefulness/{check_id}/received` then answers
via `/respond` — unchanged.

---

## 2. 🟠 SHOULD — pad the wakefulness push `code` to 4 digits (real field bug)

A guard saw a **4-digit** code in the notification but the app would only let them enter 3, and OK
never enabled.

**Cause:** the push `data.code` appears to be sent **without a leading zero** (e.g. `472`) while
the tray notification body shows the padded `0472`. Wakefulness codes are **always 4 digits**.

**App-side:** already fixed defensively — the app now zero-pads any incoming code to 4
(`472` → `0472`), online and offline. So this is no longer breaking.

**Backend ask (please still do it):**
- [ ] Send `data.code` in the `WAKEFULNESS_CHALLENGE` push **already zero-padded to 4 digits**, so
  it matches the notification body byte-for-byte. Same for the `code` in
  `GET /wakefulness/pending`. Belt-and-braces, but it keeps the wire correct.

---

## 3. 🟡 CONFIRM — dashboard shows offline wakefulness results

Per your handoff, a flushed offline answer becomes a first-class `WakefulnessCheck` (mode
`OFFLINE`), back-fills the timeline (`WAKEFULNESS_CHALLENGE` → `CONFIRMED`/`FAILED`), and shows in
the Welfare Report tagged **"Offline"**, with failures recorded but **not** escalated to CRITICAL.

- [ ] Confirm this is live so I can verify it on-device (offline shift → answer a challenge →
  reconnect → it appears on the shift timeline / Welfare Report tagged "Offline").

---

## 4. What's changing on the app side (FYI — no action needed)

- Offline wakefulness answers now flush to `POST /shifts/{id}/wakefulness/offline` instead of the
  old (wrong) `/wakefulness/{synthetic-id}/respond`, which was 404ing → answers were **silently
  dropped**. This is the core fix.
- `/respond` is now **online-only** (real `check_id` from a push/pending-poll). It no longer
  carries `is_offline` / `window_reference`.
- New `GET /wakefulness/pending` poll wired into the home screen.
- Incoming codes normalised to 4 digits.
- (Unrelated) app now blocks the UI if the device's **location services** are switched off
  mid-shift, and adds pull-to-refresh — no backend impact.

---

## 5. Our own follow-ups (not Jerry)

- **Mock backend** (`mock-backend/server.js`) doesn't serve `/wakefulness/offline` or
  `/wakefulness/pending` yet — the app swallows the 404 locally, but we should add them to
  exercise the flow without the real host.
- On-device verification pass once §1–§3 are confirmed.

---

## Quick checklist for Jerry

- [ ] `POST /shifts/{id}/wakefulness/offline` live on the branded host; **duplicates → 200
      `ALREADY_RESOLVED`**; wrong code → 200 `FAILED`.
- [ ] `GET /shifts/{id}/wakefulness/pending` live; tell me the exact response envelope.
- [ ] `WAKEFULNESS_CHALLENGE` push `data.code` (and pending `code`) **zero-padded to 4**.
- [ ] Offline wakefulness result shows on the timeline / Welfare Report tagged "Offline".
