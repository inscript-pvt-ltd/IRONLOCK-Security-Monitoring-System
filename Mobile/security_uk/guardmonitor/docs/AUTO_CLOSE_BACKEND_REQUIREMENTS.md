# Auto-close reconciliation — what the app needs from the backend

**For:** backend developer · **Date:** 2026-06-26
**Context:** when a guard never taps END, the **server** auto-closes the shift a grace period
after `scheduled_end` (`end_type: "auto"`) — the real safety net, because the app can't be
trusted to close it (backgrounded / killed / offline). That part is yours and already works.

**The gap:** the app never *learns* the shift was closed, so the guard's screen stays on the
active/END view. Today `GET /shifts/current` returns **`null`** for an auto-closed shift, and the
app **intentionally ignores a `null` while it's locally active** (so a transient null can't kill
the END button mid-shift). The app can't tell "transient null" apart from "auto-closed."

We're building the **app-side reconciliation** (detect the close → stop GPS, cancel the reminder,
clear state, drop the guard back to the shift screen with a message). For it to trigger, the
backend just has to **surface the closed shift to the app**. Pick **one** of the options below.

---

## ✅ Option A (preferred) — return the closed shift once on `GET /shifts/current`

After an auto-close (or an admin cancel), keep returning the shift on `/shifts/current` **with a
terminal status**, for a short window, instead of immediately switching to `null`:

```json
{ "success": true, "data": { "shift": {
  "id": "…", "reference": "SH-2847",
  "status": "completed",
  "end_type": "auto",            // ← the key field; "auto" = server force-closed
  "actual_end": "2026-06-26T20:15:00.000000Z",
  "scheduled_start": "…", "scheduled_end": "…",
  "can_start": false, "can_end": false, "can_request_early_end": false,
  "site": { … }, "geofence": { … }
} } }
```

- **For an admin cancel:** same idea — return `"status": "cancelled"` once.
- **How long to keep returning it:** at least until the app has polled once. The app polls
  `/shifts/current` **every 20 s**, so returning the terminal shift for ~**2–3 minutes** (or until
  the next scheduled shift becomes current) is plenty. After that it can go back to `null` / the
  next shift — the app will already have reconciled.
- **Why this is preferred:** the app already has the completed/cancelled handling path; this needs
  no new endpoint or push. The app distinguishes it from a **normal** end purely by
  `end_type` — `"auto"` (or `status: "cancelled"`) triggers reconciliation; `"guard"`/`"early"`
  (a guard-initiated end) does **not**.

> ⚠️ **Must include `end_type`.** Without it the app can't tell an auto-close from a normal end
> and (correctly) won't act, to avoid double-handling a guard's own END.

---

## 🔁 Option B (alternative) — a push

Send a data push the moment the shift is auto-closed / cancelled:

```json
{ "type": "SHIFT_CLOSED", "shift_id": "…", "end_type": "auto", "actual_end": "…Z" }
```

We'd add `SHIFT_CLOSED` to the push router (same pattern as the others). Works while backgrounded
**if** push is delivering (Android now; iOS once APNs is set up). Best paired with Option A as the
reliable fallback, since push is best-effort.

---

## Please also confirm

1. **The auto-close job is actually live** on the cPanel backend (the guide documents it, but if
   it isn't running, a guard who forgets to END leaves a shift open forever — the app can't save
   them).
2. **The grace period** value (minutes after `scheduled_end` before auto-close fires).
3. Which option (A and/or B) you'll provide, so we can match the app side.

---

## What the app will do once you provide this (already being built)

On seeing `status: "completed"` + `end_type: "auto"` (or `status: "cancelled"`) while it still
shows the shift active, the app will: stop GPS capture, cancel the "shift ended" reminder, clear
the in-progress state, drop the guard to the shift screen, and show *"Your shift was automatically
closed at its scheduled end."* No double-close — a guard-initiated END (`end_type: guard`/`early`)
is explicitly excluded.

## TL;DR

| # | Item | Needed? |
|---|------|---|
| A | Return the closed shift once on `/shifts/current` with `status` + **`end_type: "auto"`** (and `"cancelled"` for cancels) | **Preferred — pick A or B** |
| B | `SHIFT_CLOSED` push with `end_type` | Alternative / belt-and-braces |
| — | Confirm the auto-close job is live + the grace-period value | **Yes** |
