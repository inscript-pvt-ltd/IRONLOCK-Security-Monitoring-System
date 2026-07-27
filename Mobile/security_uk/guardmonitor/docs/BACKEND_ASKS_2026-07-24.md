# Backend asks — contract guarantees found during the 2026-07-24 code audit

**Date:** 2026-07-24
**From:** Mobile (Guard Monitor app)
**To:** Jerry (backend / dashboard)
**Status:** The app-side fixes for these are **already shipped** (defensive on the
device). These asks make the guarantees hold at the source so the device never has
to compensate. None is blocking; all are "please confirm / tighten the contract."

Context: a manual file-by-file audit (`docs/CODE_AUDIT_2026-07-24.md`) turned up three
places where the app was trusting a server-shape assumption. We hardened the app for
each; below is what we'd like the backend to guarantee so the assumption is true
end-to-end.

---

## 1. Datetimes: always ISO-8601 **UTC with a zone designator** (audit M5)

**Ask:** every datetime field in every mobile response is ISO-8601 with a trailing
`Z` (or an explicit numeric offset) — **never a zone-less string** like
`2026-07-24T13:00:00`.

**Why:** a zone-less timestamp is ambiguous. The device's date parser treats a
string with no zone marker as **device-local**, so a zone-less value would be
silently misread by the guard's UTC offset — shifting the START window, the
"you can begin at HH:MM" hint, and the login-window copy by hours for a guard
outside the server's timezone.

**Fields this touches (at least):** `scheduled_start`, `scheduled_end`,
`actual_start`, `actual_end` on `GET /shifts/current` and the start/end responses;
`window_opens_at`, `next_shift_start` in the `LOGIN_WINDOW_CLOSED` details;
`issued_at` / `expires_at` on wakefulness + photo requests.

**App side (done):** all server timestamps now go through a `parseServerUtc` helper
that interprets a zone-less value as UTC before localising. So we're safe either way
— but please still emit `Z` so other consumers (dashboard, exports) aren't exposed.

**Please confirm:** is `Z` already guaranteed on all of the above today? If any
endpoint emits a naive/zone-less time, that's the one to fix.

---

## 2. `GET /shifts/current`: never a partial shift with null `scheduled_*` (audit M4)

**Ask:** when the endpoint returns a shift object, `id`, `status`, `scheduled_start`,
and `scheduled_end` are **always present and non-null**. "No shift" should be an
absent/null `shift`, not a shift object with null required fields.

**Why:** the app builds the whole shift card + START/END timing from these. A shift
object missing `scheduled_start`/`scheduled_end` can't be constructed, so that poll
is dropped. (Cross-reference: we've separately seen `GET /shifts/current` return a
**null shift for an active shift** after `scheduled_start` — same family of issue.
If that's still open, this is a good moment to close it too.)

**App side (done):** the parser now (a) reads a missing required time as a clear,
logged `FormatException` instead of an obscure cast error, and (b) `fetch()` keeps
the **last good** shift on a malformed poll rather than blanking an in-progress
shift. But the device can't invent a start time — a persistently partial payload
still means the shift can't appear on a cold start.

**Please confirm:** are `id` / `status` / `scheduled_start` / `scheduled_end`
guaranteed non-null whenever a shift is returned?

---

## 3. Wakefulness code: always exactly **4 digits, zero-padded** (audit M2)

**Ask:** the `code` on a `WAKEFULNESS_CHALLENGE` push and on
`GET /shifts/{id}/wakefulness/pending` is always a **4-character, zero-padded**
string — e.g. `0472`, never `472`, and never longer than 4.

**Why:** the guard's entry pad is fixed at exactly 4. We already restore a **dropped
leading zero** (`472` → `0472`) because a `digits:4` TOTP is always `0000–9999`. But a
value **longer than 4** is malformed by definition and could never be matched on the
pad → the guard would fail a check they answered correctly (a false supervisor
alert), and offline it would buffer a wrong answer.

**App side (done):** codes are normalised to exactly 4 — a short value is
zero-padded; a value that strips to **>4 digits (or empty) raises no challenge**
(we stay idle and let your missed-check handling fire) rather than showing an
unpassable code. Applied to both the online push/poll path and the offline TOTP path.

**Please confirm:** is the pushed/pending `code` always emitted as a 4-char
zero-padded string? If the TOTP is ever formatted without left-padding (so `0472`
goes out as `472`), padding it server-side removes the last place this can bite.

---

## Net

- All three are **shipped defensively on the device** — nothing here blocks a release.
- Each ask just moves the guarantee to the source so the contract is honest for every
  consumer. **Confirmations welcome; only #1 (any zone-less field) and #2 (any
  null-required-field shift) would be actual bugs to fix if present.**
