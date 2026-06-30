# Backend Spec — Shift End: early-end approval + guaranteed auto-close

> **See also:** [`BACKEND_REQUIREMENTS.md`](BACKEND_REQUIREMENTS.md) — the consolidated, priority-ordered list of **everything** the backend must do across the whole audit (HTTPS, welfare scoring, timestamps, etc.). This file is the detailed shift-end contract it references.

**Audience:** backend developer (Laravel API).
**Status:** the mobile app already implements the client side of everything below — it submits the early-end **request**, polls `GET /shifts/current` for the supervisor's decision, and only ends once approved. The backend needs to add the request/approval endpoints (section 0), expose the decision on `GET /shifts/current`, accept the end body (section 1), add the auto-close job (section 2), and — critically — **enforce the security-meaningful decisions server-side (section 4: H1 clock, H2 welfare scoring, M1 echo)**. The app reports honestly but cannot be trusted; section 4 is what makes the controls real.
**Why this exists:** a shift only ends today when the app POSTs `/end`. If a guard forgets, or their phone is dead/offline/backgrounded, the shift stays `active` forever — no `actual_end`, no duration, an open record. The app now (a) makes a guard **request supervisor approval** before ending early, with a recorded reason, and (b) reminds them when the duration is up — but the **guarantee** that every shift closes can only live on the server.

> **What changed (2026-06-22):** early ends are no longer immediate. A guard who wants to leave before `scheduled_end` now submits a **request**; a supervisor/admin **approves or rejects** it; only after approval can the guard tap END. This adds section 0 below and a new `early_end_request` object on the shift. Sections 1–3 are unchanged in intent — `POST /end` for an early shift is now only reachable **after** an approval.

---

## 0. Early-end approval flow — **new**

### 0.1 `POST /shifts/{id}/early-end-request` — guard asks to leave early

The guard taps END before `scheduled_end`, picks a reason + writes a note, and the app POSTs this. **It does not end the shift** — the shift stays `active`. It creates (or replaces) a pending early-end request for a supervisor to decide on.

#### Request body
```json
{
  "reason": "Illness",
  "note": "Felt unwell, need to leave at 14:30."
}
```

| Field | Type | Notes |
|---|---|---|
| `reason` | string | One of the fixed enum (see §1). Required. |
| `note` | string \| absent | Free text, ≥10 chars (app-enforced). Sent whenever present. |

#### Backend behaviour
- Create a `early_end_request` for the shift with `status: 'pending'`, storing `reason`, `note`, `requested_at`, and the requesting guard.
- One open request per shift: if a `pending` (or `rejected`) request already exists, replace/overwrite it (a re-request after rejection is expected and normal).
- Only valid while the shift is `active` and `now < scheduled_end`. Otherwise return `409` (e.g. `EARLY_END_NOT_APPLICABLE`) — the app falls back to a normal end.
- Notify the supervisor/admin however the dashboard does it (push, queue, email — out of scope here).
- Response: return the shift (same shape as `GET /shifts/current`) with the `early_end_request` object populated, or a simple `{ "early_end_request": { "status": "pending", ... } }`. The app optimistically marks itself pending and reconciles on the next poll regardless, so an empty 2xx is acceptable.

### 0.2 Supervisor decision — admin/dashboard side

The supervisor approves or rejects from the admin UI (endpoint shape is your call — e.g. `POST /admin/shifts/{id}/early-end/approve` / `/reject`). The decision sets `early_end_request.status` to `approved` or `rejected` and records `decided_at` + `decided_by`. **This is how the guard's phone learns the outcome** — see 0.3.

### 0.3 `GET /shifts/current` — expose the decision (the app polls this every 20s)

Add an optional `early_end_request` object to the shift payload. The app reads `early_end_request.status` on each poll and drives the UI from it:

```json
{
  "id": "…",
  "status": "active",
  "scheduled_start": "…",
  "scheduled_end": "…",
  "early_end_request": {
    "status": "pending",            // pending | approved | rejected
    "reason": "Illness",
    "note": "Felt unwell…",
    "requested_at": "2026-06-22T14:05:00Z",
    "decided_at": null,             // set when approved/rejected
    "decided_by": null
  }
}
```

| `status` | What the app does |
|---|---|
| `pending` | END button **locked** — guard sees "waiting for supervisor approval", keeps working (GPS/welfare continue). |
| `approved` | END button **unlocks** — guard taps END → `POST /end` with `ended_early:true` + the stored reason/note (§1). |
| `rejected` | Guard told the request was declined; may submit a fresh request (back to `pending`) or keep working to `scheduled_end`. |
| `early_end_request` absent / `null` | No outstanding request — normal behaviour. |

- Omit the object (or send `null`) when there's no outstanding request. The app treats absent as "no request".
- Once the shift is ended/completed, the object can stop being returned.
- **Server is the authority.** The app only *requests* and *reflects* status — it never self-approves. The backend must reject `POST /end` with `ended_early:true` if there is no `approved` request (recommend `409 EARLY_END_NOT_APPROVED`) so a tampered client can't skip approval.

---

## 1. `POST /shifts/{id}/end` — accept an optional reason

The app now sends a JSON body on every end. Backwards compatible: all fields optional, a normal on-time end sends only `ended_early: false`.

### Request body

```json
{
  "ended_early": true,
  "reason": "Illness",
  "note": "Felt unwell, supervisor authorised leaving at 14:30."
}
```

| Field | Type | Notes |
|---|---|---|
| `ended_early` | bool | `true` when ended before `scheduled_end`. Always present. |
| `reason` | string \| absent | One of a fixed set (see below). Present only when `ended_early`. |
| `note` | string \| absent | Free text, ≥10 chars (app-enforced). Present only when `ended_early`. |

**`reason` enum** (app-side list — keep in sync): `Incident / Emergency`, `Illness`, `Relieved early`, `Site closed`, `Other`.

### Backend behaviour
- Persist `ended_early`, `reason`, `note` on the shift record (or a related `shift_end_events` row).
- Set an **`end_type`** on the shift: `guard` (normal on-time), `early` (this request with `ended_early:true`), or `auto` (section 2).
- Validation: if `ended_early` is true, `reason` should be required server-side too (don't trust only the client). If `reason`/`note` missing on an early end, still close the shift but flag it for review rather than rejecting — never leave a shift stuck open over a validation error.
- Keep returning the existing end payload (`actual_start`, `actual_end`, `duration_hours`, `status: completed`). The app merges it.

---

## 2. Auto-close overdue shifts (the guarantee) — **the important part**

A scheduled job (cron / queue worker) that closes shifts the guard never ended.

### Rule
For any shift where `status === 'active'` **and** `now > scheduled_end + GRACE`:
- Set `status = 'completed'`, `end_type = 'auto'`.
- Set `actual_end` to a defensible value — recommend **`scheduled_end`** (the contracted end), or the **last GPS ping / last activity timestamp** if you want "last known on-site". Pick one and be consistent; document which.
- Compute `duration_hours` from `actual_start → actual_end`.
- Flag it (`auto_closed: true`) so it surfaces in supervisor reporting.

### Grace window
- Suggest **30–60 min** after `scheduled_end`. Long enough to let a slightly-late manual end (or the app's reminder) happen first; short enough that records don't linger.
- Run the job frequently (e.g. every 5–15 min) so closures are timely.

### Why `actual_end = scheduled_end` (recommended default)
An auto-closed shift means we have **no trustworthy signal** the guard stayed past `scheduled_end`. Crediting time up to "now" (when the job happens to run) would over-pay and misrepresent coverage. `scheduled_end` is the honest, contracted boundary. If GPS shows activity beyond it, last-ping is the more accurate choice — your call, just record which rule you used.

---

## 3. `end_type` — three accountable outcomes

Every completed shift should carry one of:

| `end_type` | Meaning | Trustworthy duration? |
|---|---|---|
| `guard` | Ended on/after `scheduled_end` via the app | Yes |
| `early` | Ended before `scheduled_end`, **with reason + note** | Yes (+ reason on file) |
| `auto` | Guard never ended it; **server closed it** at the grace deadline | No — flag for supervisor |

This lets a supervisor see "guard X auto-closed 3 shifts this week" (forgetting to end) vs legitimate early ends — which matters for payroll accuracy and accountability. Silent auto-close without this flag loses that signal.

---

## 4. Server-authoritative enforcement — **audit H1, H2, M1**

These are the controls the product's trustworthiness rests on. The app reports honestly, but **every decision below must be made and enforced on the server** — the device runs on the guard's own phone, where the clock can be changed, the binary inspected, and any client-side check bypassed. Treat all client-sent values here as *claims*, not facts.

### 4.1 H1 — the server decides early-vs-normal (never the device clock)

The app sends `ended_early` on `POST /end`, but it computes that flag from the **device clock**, which the guard controls (Settings → Date & Time). A guard can set the clock past `scheduled_end` to make `ended_early:false` and skip the whole approval flow.

**Required server behaviour on `POST /shifts/{id}/end`:**

- Compute `is_early = server_now < scheduled_end` from the **server's own clock**. Ignore the client's `ended_early` for the decision (keep it only as a logged claim for anomaly detection).
- If `is_early` is true, the end is only allowed when an **`approved`** `early_end_request` exists for the shift. Otherwise reject with `409 EARLY_END_NOT_APPROVED` (do **not** close the shift).
- If the client claimed `ended_early:false` but the server clock says it's early (likely clock tampering), treat it as **early** → require approval, reject if none. Persist the server's determination as the authoritative `end_type` and flag the mismatch.
- Record both values where they differ (`client_claimed_early` vs server `is_early`) so a supervisor can see attempted bypasses.

> This makes the early-end approval flow (§0) actually binding. Without it, §0 is a UI convenience a tampered client walks straight past.

### 4.2 H2 — welfare checks are scored and tallied server-side

The welfare code is server-issued, but today the **app** compares the entry locally and keeps its own passed/total counters, then merely *posts* the result. A tampered client can report a perfect attentiveness record the guard never earned. The app's local compare is fine for instant UX feedback — but it must not be what feeds reporting/payroll.

**Required server behaviour:**

- `POST /wakefulness/{checkId}/respond` — the server compares the submitted entry against the code **it issued**, and records the authoritative pass/fail + `responded_at`. The client's self-assessed result is advisory only.
- Score a **timeout as a fail**: if no valid response arrives within the check window, the server marks that check failed — don't rely on the app to report its own miss.
- Maintain the per-shift welfare summary (`welfare_passed` / `welfare_total`) from the server's own records and expose it (e.g. on `GET /shifts/current` and/or the shift-end payload) so the app can display the **server's** tally, not its local counters, anywhere that feeds compliance or pay.

### 4.3 M1 (backend half) — echo `early_end_request` on **every** poll while outstanding

Specced in §0.3; restated here as a hard requirement because the app depends on it. While a request is `pending`, or `approved`/`rejected` but the shift hasn't ended yet, **every** `GET /shifts/current` response must include the `early_end_request` object — not just the first one after the request is created.

- The app now holds the `pending` lock locally as a safety net (audit M1 app-half) so a single poll that drops the field won't unlock the END button. But that's a fallback, not a substitute: if the backend stops echoing the object, the guard's UI runs on stale local state instead of the real server decision, and an **`approved`** that's only sent once can be missed.
- Echo the object consistently across the whole `pending → approved/rejected → ended` lifecycle. Stop returning it only once the shift is `completed`/`cancelled`.

---

## 5. Related open backend items (context, not part of this spec)
- **`GET /shifts/current` returns `null` for an `active` shift** once `scheduled_start` passes — contract violation; should return the active shift so the app can resume after a relaunch.
- Deploy the human-readable **`reference`** field on shifts.
- Move the API to **HTTPS** (then the app's Android cleartext exception can be removed).

---

## 6. What the app already does (so you can test end-to-end)
- **Early end before `scheduled_end`:** the END button opens a sheet that requires a reason + note, then POSTs `POST /shifts/{id}/early-end-request` (§0.1). The shift is **not** ended — the app shows "waiting for supervisor approval" and **locks** the END button.
- **Polls `GET /shifts/current` every 20s** and reads `early_end_request.status` (§0.3): `pending` keeps END locked; `approved` unlocks END; `rejected` lets the guard re-request.
- **On approval**, tapping END sends the section-1 body on `POST /end` with `ended_early:true` and the approved `reason`/`note`.
- **Normal end after `scheduled_end`:** no request/approval needed — END sends `ended_early:false` directly.
- Schedules a **local** "shift ended" reminder at `scheduled_end` (device-side; no backend involvement).
- Shows an on-screen "shift overdue, tap END" banner past `scheduled_end`.
- The app does **not** and **cannot** guarantee closure — section 2 is the only reliable mechanism. It also never self-approves an early end: enforce approval server-side (§0.3) so a tampered client can't bypass it.
