# What we need from Jerry (backend) — current open list

**From:** Flutter app (Guard Monitor)
**To:** backend/dashboard (Jerry)
**Date:** 2026-07-23
**Status:** the single, current list of what's still open. Everything from
`BACKEND_ASKS_2026-07-21.md` and `BACKEND_ASKS_2026-07-22.md` is **answered** — this doc
carries forward only the items that still need a change or a confirmation on your side.

> ✅ **RESOLVED 2026-07-23** — Jerry replied; all 3 handled. **#1** pool nonces now valid the
> whole shift (server keys off each nonce's own `expires_at`, which the app already stored/enforced
> — so the blocker is fixed with no structural app change); the aggregate `offline_nonce_ttl_minutes`
> now reports shift-length, so we **decoupled** the offline-photo *fire window* from it (fixed
> 15-min `fireWindowMinutes` constant) to avoid firing hours-late captures. **#2** `issued_at` added
> to `WAKEFULNESS_CHALLENGE` push — app already parsed/used it; stale-tap guard kept as rollout
> defence. **#3** pruning + CRITICAL confirmed in code (re-test after their next deploy +
> `config:cache`). App: analyze clean, 163 tests green. Kept below for the record.

---

## TL;DR — 3 things

| # | Item | Priority | What we need |
|---|------|----------|--------------|
| 1 | **Offline-photo nonce TTL** | 🔴 **Blocker** | Prefetched OFFLINE_POOL nonces expire in **15 min**, but the first photo mark is **50–70 min** into the shift — so an offline guard's nonces are **always dead** before a photo is ever due. Need a longer TTL (or a different offline-photo scheme). |
| 2 | **`issued_at` in the wakefulness push** | 🟡 Small | Add `issued_at` to the `WAKEFULNESS_CHALLENGE` FCM payload (parity with `PHOTO_REQUEST`) so a tapped challenge anchors instantly instead of waiting for the next poll. |
| 3 | **Expired photo-pending pruning** | 🟡 Confirm | You said a fix was queued so `GET /shifts/{id}/photos/pending` stops returning already-expired requests. Just confirm it's **deployed**. |

---

## 1. 🔴 BLOCKER — offline-photo nonces die before any photo is due

This is the one that actually breaks a real offline shift, and it's **not fixable app-side.**

**The numbers don't line up:**

- On `POST /shifts/{id}/start`, `photos.offline_nonce_ttl_minutes` = **15**.
- The app prefetches a pool of OFFLINE_POOL nonces at shift start so it can sign a photo while
  offline (no server round-trip to get a per-request nonce).
- But the **first photo mark is 50–70 minutes** into the shift (your confirmed spacing).
- So by the time the first offline photo is due, **every prefetched nonce has already expired**
  (issued at 0 min, dead at 15 min, first needed at 50+ min).

**What the guard sees:** they take the photo, and the app can't save it — the nonce it draws is
already past TTL, so the capture is rejected before it can even be queued. Exactly the
*"it says it can't save the photos"* symptom from the device test.

**Why the app can't fix it:** offline means no server to issue a fresh nonce. The whole point of
the pool is to pre-issue signing material — but pre-issued material that expires in 15 min can
never cover a mark that's 50+ min out. Refilling doesn't help either: while offline there's no
connection to refill from.

**Options (your call — any one unblocks us):**

- [ ] **Simplest:** raise `offline_nonce_ttl_minutes` so a pool nonce stays valid across the
      whole shift window (e.g. shift-length + a margin — or just make OFFLINE_POOL nonces valid
      until end-of-shift). The server still enforces single-use, so a longer TTL doesn't weaken
      replay protection — the nonce is still burned on first use.
- [ ] **Alternative:** decouple offline-photo integrity from a short-lived nonce entirely — e.g.
      accept the HMAC signature + `ntp_reference` (tamper-proof capture time we already send) as
      the offline proof, and let the nonce be validated leniently on flush.
- [ ] Tell us the intended offline-photo design if it's neither of the above — we may have the
      wrong mental model of how OFFLINE_POOL is meant to span a shift.

**Question:**
- [ ] Is `offline_nonce_ttl_minutes: 15` deliberate, or a copy of the *online* per-request TTL?
      Online it's fine (request → capture is seconds); offline it can't work as-is.

---

## 2. 🟡 Add `issued_at` to the `WAKEFULNESS_CHALLENGE` push

Carried over from `BACKEND_ASKS_2026-07-22.md §4` — still open.

**Today:** the `WAKEFULNESS_CHALLENGE` FCM payload is
`{ type, check_id, shift_id, code, response_seconds }` — **no `issued_at`.** The `PHOTO_REQUEST`
push already carries `issued_at`; we just want parity.

**Why:** without `issued_at`, when a guard taps an **old** wakefulness notification the app can't
tell how stale the challenge is, so it can't safely anchor the countdown. Our interim fix **drops**
a tapped wakefulness challenge that has no `issued_at` and waits for the `/wakefulness/pending`
poll to re-surface a genuinely-live one — safe, but a legit fresh tap opens on the next poll
instead of instantly.

**Ask:**
- [ ] Include `issued_at` (ISO-8601 UTC) in the `WAKEFULNESS_CHALLENGE` push data. Then a tapped
      challenge opens instantly when live / is dropped cleanly when stale — no poll wait.

> Not a blocker — the app is correct either way. This just restores instant-open on a tapped
> live challenge.

---

## 3. 🟡 Confirm expired photo-pending pruning shipped

From `BACKEND_ASKS_2026-07-22.md §2b` you said a fix was **queued** so that once a photo request's
window has expired (past `issued_at + response_seconds` / the nonce TTL),
`GET /shifts/{id}/photos/pending` stops returning it — the poll should only surface requests still
**answerable**.

The app is already defensive (it shows a request once, won't re-open a dead one, closes an expired
capture screen), so this isn't breaking us — we just want to confirm the contract is clean now.

**Ask:**
- [ ] Confirm the expired-request pruning is **deployed** on `GET /shifts/{id}/photos/pending`.
- [ ] Confirm an expired photo request raises its **CRITICAL "missed"** alert server-side on its
      own (we assume yes — the app does nothing on expiry beyond closing the screen).

---

## For reference — already answered, no action needed

Kept here so nothing looks dropped:

- ✅ Empty `schedule: []` on a short shift — **expected** (first welfare = start + 30–45 min,
  first photo = start + 50–70 min). Use a ~2 h test shift. *(2026-07-22 §1)*
- ✅ Schedule is **fixed at start**, one schedule drives online + offline, one check per mark.
  *(2026-07-22 §1)*
- ✅ Offline tag = **endpoint-based** (our `/respond` body is already clean). *(2026-07-22 §2)*
- ✅ Manual welfare trigger appears on `GET /shifts/{id}/wakefulness/pending`. *(2026-07-22 §3)*
- ✅ Offline flush endpoint, pending envelope, code padding. *(2026-07-21)*

---

## Context

- **APNs/FCM:** iOS push is not live yet (APNs key pending on our side). Until then iOS uses the
  `/wakefulness/pending` + `/photos/pending` polls as the online path. The app is built to use push
  the moment it's available — no backend change needed for that.
- Item **#1** is the only one that blocks a real offline shift end-to-end. #2 and #3 are polish.
