# Backend reply — Wakefulness offline flush, pending poll, code padding

**From:** backend/dashboard (Jerry)
**To:** Flutter app (Guard Monitor)
**Date:** 2026-07-21
**Re:** your `BACKEND_ASKS_2026-07-21.md`

---

## TL;DR

All four items are done on the backend. §1 endpoints are built **and deployed to the
branded host** (`dashboard.ironlock.co.uk`). §1b envelope is pinned below. §2 needs
**no backend change** — the code has always been sent zero-padded; the lost zero was an
app-side int-parse, which your defensive fix already covers. §3 is live. You're clear to
run the on-device pass.

---

## 1. ✅ Both endpoints are live on the branded host

Deployed and serving on `https://dashboard.ironlock.co.uk/api/mobile/v1`. Both sit behind
`guard.auth` (same bearer token as every other mobile call).

### 1a. `POST /shifts/{shiftId}/wakefulness/offline` — confirmed behaviour

- ✅ **Idempotent per `(shift, window_reference)`.** A re-flush returns **HTTP 200** with
  `{ "result": <first outcome>, "reason": "ALREADY_RESOLVED" }` — no duplicate row, no
  double event. Duplicates are **always 200**, never 4xx, so your "treat any 200 as done,
  dequeue" loop is safe. Retry only on 5xx / network.
- ✅ **Wrong code → 200** `{ "result": "FAILED", "reason": "OFFLINE_CODE_MISMATCH" }`.
  Recorded for audit, **no** retroactive CRITICAL. Not a 4xx.
- ✅ **Error codes as documented and terminal (don't retry):**
  `404 SHIFT_NOT_FOUND`, `409 SEED_UNAVAILABLE`, `422 VALIDATION_ERROR`.
- Note: the success body is wrapped in the standard envelope —
  `{ "success": true, "data": { "result": "...", "reason": ... }, "meta": {...} }`.
  `result` is `PASSED` | `FAILED`; `reason` is `null` on a pass, `ALREADY_RESOLVED` on a
  replay, `OFFLINE_CODE_MISMATCH` on a wrong code.

### 1b. `GET /shifts/{shiftId}/wakefulness/pending` — exact envelope (pin your parser to this)

Challenges come back as an **array under `data.challenges`**. When there's nothing
pending it's an **empty array** — there is no `pending:false` field, so treat
`data.challenges: []` as "nothing to do".

```json
{
  "success": true,
  "data": {
    "challenges": [
      {
        "check_id": "9f1c…",
        "shift_id": "3ab7…",
        "code": "0472",
        "request_type": "scheduled",
        "scheduled_at": "2026-07-21T10:00:00.000000Z",
        "issued_at":    "2026-07-21T10:00:00.000000Z",
        "response_seconds": 60,
        "expires_at":   "2026-07-21T10:01:00.000000Z"
      }
    ]
  },
  "meta": { "timestamp": "2026-07-21T10:00:05.000000Z" }
}
```

All five fields you asked for are present (`check_id`, `code`, `issued_at`,
`response_seconds`, `expires_at`), plus `shift_id`, `request_type` (`manual` | `scheduled`)
and `scheduled_at`. Only **online** checks ever appear here (offline TOTP challenges never
exist as server rows). On discovery: `POST /wakefulness/{check_id}/received` then answer via
`/respond` — unchanged.

---

## 2. Code padding — no backend change needed (it's already padded)

The backend has sent the wakefulness code **zero-padded to 4 digits since the very first
build**. The push `data.code` and the notification body are built from the **same** padded
string, the DB column is `char(4)`, and `GET /wakefulness/pending` echoes that same padded
value. FCM data is stringified with a plain cast, so `"0472"` goes on the wire as `"0472"`.

So `data.code` and the body are **byte-identical and both padded**. A guard seeing `472`
in `data.code` but `0472` in the tray body is the classic `int.parse("0472") → 472`
dropping the leading zero on the **receiving** side — which is exactly what your
zero-pad-on-receipt fix addresses. Keep that fix (it's the right defensive move); there's
nothing to change on the backend. If you ever see a genuinely 3-digit code arrive on the
wire (raw JSON string, not a parsed int), send me the payload and I'll dig in — but the
current code path can't produce one.

---

## 3. ✅ Dashboard shows offline wakefulness results

A flushed offline answer is a first-class `WakefulnessCheck` (mode `OFFLINE`). It back-fills
the timeline (`WAKEFULNESS_CHALLENGE` → `CONFIRMED`/`FAILED`, dated to when it fired
on-device), renders in the **shift timeline** Wakefulness table with an **"Offline"** badge
and the failure reason, and appears in the **Shift Welfare Report** (web + PDF). Failures are
recorded but **not** escalated to CRITICAL. Go ahead and verify: offline shift → answer a
challenge → reconnect → it lands on the timeline / Welfare Report tagged "Offline".

---

## Your checklist — answered

- [x] `POST /shifts/{id}/wakefulness/offline` live on the branded host; duplicates → 200
      `ALREADY_RESOLVED`; wrong code → 200 `FAILED`.
- [x] `GET /shifts/{id}/wakefulness/pending` live; envelope pinned above
      (`data.challenges[]`, empty array = none).
- [x] `WAKEFULNESS_CHALLENGE` push `data.code` (and pending `code`) zero-padded to 4 —
      already the case; the zero-loss was an app-side parse, no backend change.
- [x] Offline wakefulness result shows on the timeline / Welfare Report tagged "Offline".

Anything off when you test against the branded host, send me the raw request/response and
I'll turn it around fast.
