# Backend Spec — Shift End: early-end reasons + guaranteed auto-close

**Audience:** backend developer (Laravel API).
**Status:** the mobile app already sends the new fields below; the backend needs to accept/store them and add the auto-close job.
**Why this exists:** a shift only ends today when the app POSTs `/end`. If a guard forgets, or their phone is dead/offline/backgrounded, the shift stays `active` forever — no `actual_end`, no duration, an open record. The app now (a) lets a guard end *early* with a recorded reason and (b) reminds them when the duration is up — but the **guarantee** that every shift closes can only live on the server.

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

## 4. Related open backend items (context, not part of this spec)
- **`GET /shifts/current` returns `null` for an `active` shift** once `scheduled_start` passes — contract violation; should return the active shift so the app can resume after a relaunch.
- Deploy the human-readable **`reference`** field on shifts.
- Move the API to **HTTPS** (then the app's Android cleartext exception can be removed).

---

## 5. What the app already does (so you can test end-to-end)
- Sends the section-1 body on `POST /end`.
- Before `scheduled_end`: END requires a reason + note (this is what populates `reason`/`note`).
- Schedules a **local** "shift ended" reminder at `scheduled_end` (device-side; no backend involvement).
- Shows an on-screen "shift overdue, tap END" banner past `scheduled_end`.
- The app does **not** and **cannot** guarantee closure — section 2 is the only reliable mechanism.
