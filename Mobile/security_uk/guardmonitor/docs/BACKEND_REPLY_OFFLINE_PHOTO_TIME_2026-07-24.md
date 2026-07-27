# Flutter Reply — Offline Photo "Scheduled" vs "Capture" Time

> **✅ RESOLVED 2026-07-24 — Jerry replied; NO app changes needed.** Both open
> confirmations came back settled from the backend code:
> - **Q1 (pre-projected `elapsed_seconds = 0`):** **ACCEPTED — keep as-is.** The backend
>   computes `ntp_reference + elapsed_seconds` and trusts the result; `0` is not treated as
>   "no proof" (that's why SH-1049 verified). Do **not** switch to the raw form.
> - **Q2 (NTP fields on the ONLINE path):** **DON'T — Jerry retracted that ask.** Online is
>   server-anchored (`server_received_at`); sending phone-NTP there would risk a false
>   `TIMELINE_ANOMALY` hard-reject. Online path stays exactly as-is.
> - Only optional, non-blocking follow-up: a separate `ntp_last_sync_at` **diagnostic** field
>   so the backend could judge anchor age — Jerry won't consume it until agreed, so **not
>   implemented**. Everything else agreed. See the reply thread for the code citations.

**Audience:** backend/dashboard (Jerry).
**From:** the Flutter app developer.
**Date:** 2026-07-24.
**Re:** your `FLUTTER_HANDOFF — Offline Photo "Scheduled" vs "Capture" Time` (2026-07-24).
**Companion (app side):** `lib/services/offline_photo_service.dart`,
`lib/services/photo_service.dart`, `lib/services/time_anchor_service.dart`,
`lib/services/sync_flush_service.dart`, `lib/providers/photo_schedule_provider.dart`.

---

## TL;DR

- **Your two-times reading is correct** and nothing on our side is broken. `requested_at`
  = the scheduled slot, `submitted_at` = the true shutter time. Agreed.
- **Item B — we already send `ntp_reference` + `elapsed_seconds` on offline photos**, with
  exactly those field names. The two samples arrived "empty" for two different reasons, one
  of which is *correct behaviour* (SH-1050 genuinely had no NTP anchor) and one of which is a
  **contract nuance to align** (we pre-project, so `elapsed_seconds` is always `0`).
- **Item A — the 7–10 min gap is guard response latency to the offline notification, not a
  fixed dispatch interval.** The capture time IS the shutter time; offline the shutter fires
  when the guard reacts to the local notification. Not a bug; expected.
- **One real gap on our side:** the **online** photo path sends **no** NTP fields at all.
  If you want NTP anchoring on every photo (your item B), that's an additive change we'll make.

---

## Item A — offline capture dispatch mechanism + drift

**How the app decides when to fire an offline scheduled capture:**

1. At shift start the `photos` schedule (`photo_schedule` marks) is baked into the device
   (`PhotoScheduleNotifier`), and we register **one local OS notification per mark**
   (`NotificationService.schedulePhotoChecks`).
2. A capture is actually opened by `checkSchedule(offline: true)`, which runs on the
   **20-second foreground poll**. A mark is fired when the **NTP-anchored** `trustedNow()` is
   `≥ mark` **and** `≤ mark + 15 min` (`PhotoProvisioning.dueMark`, `fireWindowMinutes = 15`).
3. The camera **cannot** fire in the background (it needs the app foregrounded). So offline,
   the real trigger is: **the local notification fires at the mark → the guard taps it → the
   app opens → the next poll opens the camera → the guard shoots.**

**So the drift you measured is guard response latency, not a poll interval:**

- If the app is **foregrounded at the mark**, the capture opens within ~1 poll cycle (≤20 s).
  Low drift.
- If the app is **backgrounded/locked at the mark** (the normal offline case), nothing fires
  until the guard reacts to the notification. The 7–10 min in your samples is the time between
  the scheduled mark and the guard actually opening the app and pressing the shutter.
- `submitted_at` is therefore the **honest shutter time**. `requested_at` (the slot) minus
  `submitted_at` = "how long after the planned slot the guard responded to the offline prompt."

**Can it be tightened?** Only marginally, and not below human reaction time:

- We could shrink `fireWindowMinutes` (15 → e.g. 5), but that would make a **late** responder
  **miss** the check entirely rather than capture late — probably worse for compliance.
- We just added a **90-second expiry** to the offline capture screen itself (it previously had
  no timer and could sit open forever). That bounds *how long the open camera lingers*, but it
  doesn't change *when* the guard opens it.
- The genuine lever is notification insistence (full-screen / high-priority), not the app's
  timing logic.

**Correction to one line in your doc:** you wrote "there is no 'guard was slow' gap." Precisely,
the gap **is** the guard's response latency to the offline notification — i.e. the capture
legitimately happened ~10 min after the planned slot because that's when the guard reacted.
It's not "slow" in a bad sense, but it is real human latency, not zero.

---

## Item B — trusted NTP fields on every offline photo

### What we already send (offline path)

On the reconnect flush, `PhotoService.submitOfflinePhotos` puts these in the `POST /photos`
form body (`photo_service.dart`):

| Field | Sent offline? | Notes |
|---|---|---|
| `captured_at` | **always** | device wall-clock (diagnostic / fallback) |
| `elapsed_seconds` | **always** | see the "pre-projection" note below — currently always `0` |
| `ntp_reference` | **only when we have an NTP anchor** | omitted (not sent empty) when no anchor exists |
| `ntp_timestamp` | **never** | we don't emit a field by this literal name — see below |

The values originate at capture time in `TimeAnchorService.capture()` and are stored on the
offline queue row, then re-sent verbatim by `SyncFlushService._flushPhotos` (so the HMAC
signature still matches).

### Why the two samples looked "empty" — two different causes

1. **SH-1050 (no NTP at all): correct behaviour, not a bug.** That phone **never obtained an
   NTP anchor** (offline since launch, or SNTP/UDP blocked on that network). When there's no
   anchor, `capture()` falls back to the device wall clock and returns `ntp_reference = null`.
   We then **deliberately omit** `ntp_reference` rather than send an untrusted wall-clock value
   dressed up as trusted — which is exactly what lets your `reconstructCaptureTime()` fall to
   `captured_at` and flag it. **There is no payload fix for this** — without at least one
   online NTP sync we have nothing trustworthy to send. We already prime the anchor at shift
   **start / resume / cold-start restore** and refresh it on every online poll, so this only
   bites a guard who is offline from the very start of the shift.

2. **The `elapsed_seconds = 0` "pre-projection" — the thing to align.** By design we **project
   the anchor forward to the shutter instant** and send `ntp_reference = <projected shutter
   time>` with `elapsed_seconds = 0` (documented in `time_anchor_service.dart`). So
   `ntp_reference + elapsed_seconds` still equals the true capture instant — but our
   `ntp_reference` is **not** "the last-sync time" your doc describes; it already *is* the
   shutter time, and the gap lives inside it, not in `elapsed_seconds`.
   - This is why **SH-1049 verified** — your priority-2 path (`ntp_reference + elapsed_seconds`)
     accepted our value even with `elapsed_seconds = 0`.
   - **We need one confirmation from you:** does `reconstructCaptureTime()` treat
     `elapsed_seconds == 0` as "no monotonic proof" (and therefore distrust it), or does it
     simply compute `ntp_reference + elapsed_seconds` and trust the result? If the former, we
     will switch to the **raw** form you describe (`ntp_reference` = last successful NTP sync
     time, `elapsed_seconds` = real monotonic seconds to the shutter). We did **not** change
     this pre-emptively because SH-1049 currently verifies and we don't want to break it.

### `ntp_timestamp`

We don't send a field literally named `ntp_timestamp`. Our fresh-NTP-projected shutter time is
carried in **`ntp_reference`** (with `elapsed_seconds = 0`). If your priority-1 wants a distinct
`ntp_timestamp` field, tell us and we'll populate it from the same projected value when the
anchor is fresh — but functionally `ntp_reference` already delivers a trusted capture time.

### The one real gap: the ONLINE path sends no NTP fields

`PhotoService.uploadPhotos` (the online/live path) currently sends only `nonce_value`,
`captured_at`, `latitude`, `longitude`, `request_id`, `signature`. **No `ntp_reference` /
`elapsed_seconds`.** Online we assumed `server_received_at ≈ capture` made it unnecessary. Your
item B says "on **every** photo," so **we'll add the NTP fields to the online path too** (they're
unsigned metadata — they don't affect the existing HMAC signature). Confirm you want this and
it's a small change.

---

## Item C — our read on acceptable drift

- A 7–10 min slot→capture gap for **offline** checks is, in our view, **acceptable and honest**,
  because it reflects real guard response latency to an offline prompt with no push. Forcing it
  smaller would mean either (a) shrinking the fire window and converting late captures into
  **missed** checks, or (b) demanding the guard react instantly to a local notification — neither
  improves safety.
- So **your dashboard treatment is the right call**: headline the **capture** (`submitted_at`)
  time and show the scheduled slot (`requested_at`) as a small "scheduled 5:38 PM" note. That
  reads truthfully.
- If you want a **hard cap** on how late an offline capture may still count (e.g. "> 15 min late
  = missed"), we already enforce that client-side via `fireWindowMinutes` (a mark older than 15
  min never opens a capture). Tell us the number you want and we'll align it.

---

## §6 — direct answers to "what we need back"

1. **Dispatch mechanism + measured drift (item A):** local notification per schedule mark +
   a 20 s foreground poll that opens the camera within a 15-min window of the mark; the camera
   can't fire backgrounded, so the ~7–10 min is the guard reacting to the notification. Expected,
   not a fixed interval. Capture time = true shutter time.
2. **Will we add `ntp_reference` + `elapsed_seconds`?** They're **already sent on the offline
   path.** We'll additionally add them to the **online** path (your "every photo" ask). Timing:
   a small change we can ship in the next pass once you confirm you want it online.
3. **Acceptable drift (item C):** yes, 7–10 min is acceptable for offline; your capture-headline
   + scheduled-note display is correct. We'll match any hard "too-late = missed" cap you specify.

---

## What we need back from you (2 confirmations)

1. **Does `reconstructCaptureTime()` accept our pre-projected form** (`ntp_reference` = shutter
   instant, `elapsed_seconds = 0`), or do you want the **raw** form (`ntp_reference` = last-sync,
   `elapsed_seconds` = gap)? SH-1049 verifying suggests you accept ours — please confirm so we
   can either leave it or switch.
2. **Do you want NTP fields on the ONLINE photo path too?** If yes, we add `ntp_reference` +
   `elapsed_seconds` there (unsigned; no signature impact).

Everything else in your doc we agree with. No blocking issues on either side.
