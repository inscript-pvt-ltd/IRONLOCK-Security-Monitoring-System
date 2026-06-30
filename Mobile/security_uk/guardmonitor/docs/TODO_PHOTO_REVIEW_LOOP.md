# TODO — Photo review feedback loop (`PHOTO_REVIEWED` + `/photos/reviews`)

**Status:** 🟡 NOT IMPLEMENTED — deferred, pick up later
**Date raised:** 2026-06-25
**Source:** FLUTTER_API_GUIDE.md update (the "three push types" / photo-review version)

## What this is

The *after-upload* feedback loop for photo verification: once a guard uploads a
photo, a supervisor later **approves or rejects** it from the dashboard. This
feature surfaces that verdict back to the guard. It is **photo verification
only** — nothing to do with the wakefulness/wellness check.

## Why it's not done yet

The app currently handles photo verification only up to the upload result
(`VALIDATED`/`FLAGGED` shown at capture time, then the screen pops). It has **no
concept of the supervisor's later review decision**:

- Push router knows only `wakefulness, photo, unknown`
  ([`lib/services/push_router.dart`](../lib/services/push_router.dart)) — a
  `PHOTO_REVIEWED` push falls through to `unknown` and is **dropped**.
- No `/photos/reviews` endpoint, model, service, or poll exists.

> ⚠️ Important nuance already observed on-device: the guard **does** see a tray
> banner ("Photo approved/rejected") today. That's the **OS drawing the push's
> `notification` block automatically** — it is NOT the app handling it. The
> `data` payload (`type=PHOTO_REVIEWED`, `decision`, `note`) is still ignored, so
> the rejection **note/reason is never shown in-app** and nothing updates.

## Backend contract (from the guide)

### Push — `PHOTO_REVIEWED` (FCM, data values are strings)

```jsonc
{
  "type":       "PHOTO_REVIEWED",
  "request_id": "<uuid>",          // correlate with the uploaded photo
  "shift_id":   "<uuid>",
  "decision":   "APPROVED" | "REJECTED",
  "note":       "Clear, on-site."  // optional supervisor note ("" if none)
}
```
Notification block (OS-drawn): title `"Photo approved"`/`"Photo rejected"`,
body explains + "Tap for details" on reject.

### Poll — `GET /shifts/{id}/photos/reviews` (source of truth)

Push is best-effort; this endpoint is authoritative and also catches reviews
that landed while the app was closed / the push was lost. Works **after the
shift ends** too. `404 SHIFT_NOT_FOUND` if it isn't this guard's shift.

```jsonc
{ "data": { "reviews": [
  { "request_id": "<uuid>",
    "decision": "APPROVED" | "REJECTED",
    "note": "…or null",
    "reviewed_at": "2026-06-25T14:31:02.000000Z" }   // newest first
] } }
```

## Implementation plan (when picked up)

1. **`lib/services/push_router.dart`**
   - Add `PushKind.photoReviewed`.
   - Parse `request_id`, `shift_id`, `decision`, `note` onto `PushMessage`.
   - `isActionable` for it = `request_id` + `decision` present.
   - Add an `onPhotoReviewed(requestId, decision, note)` callback to `routePush`
     and dispatch it in the `switch`.

2. **`lib/services/push_messaging_service.dart`**
   - Wire `onPhotoReviewed` in `_dispatch` (foreground) and handle it in the
     `_backgroundHandler` switch (alongside `PHOTO_REQUEST`/`WAKEFULNESS`).
   - Action: push an entry into the existing `alertsProvider`:
     - **REJECTED → urgent/warning `AppAlert`** with the supervisor's `note`
       (so the guard sees *why*).
     - **APPROVED → quiet/info `AppAlert`** (or no alert — decide on UX).

3. **`lib/config/api_config.dart` + a service**
   - `static String shiftPhotosReviews(String id) => '/shifts/$id/photos/reviews';`
   - Small `PhotoReview` model (`requestId`, `decision`, `note`, `reviewedAt`)
     with `fromJson`, tolerant of the envelope (mirror `extractPendingPhoto`'s
     defensive parsing).
   - Service method to GET + parse the `reviews` list.

4. **Poll / refresh** (in `home_screen.dart` `_pollBackend`, or on app-foreground)
   - Fetch `/photos/reviews`, dedupe by `request_id` (+ `reviewed_at`) so the
     same review isn't surfaced twice, and feed **new** decisions into
     `alertsProvider`. Also refresh on a `PHOTO_REVIEWED` push.
   - Persist seen `request_id`s (or the latest `reviewed_at`) so reviews aren't
     re-alerted across app restarts — small `SecureStorageService` slot, like
     the photo-receipt one.

5. **Docs** — update the local `documents/FLUTTER_API_GUIDE.md` to the 3-push
   version (currently it still describes two push types) so it's the source of
   truth.

6. **Tests + gates**
   - `push_router_test.dart`: parse + dispatch a `PHOTO_REVIEWED` push
     (APPROVED + REJECTED-with-note); assert non-actionable without `decision`.
   - Reviews fetch/parse test (envelope tolerance, newest-first, dedupe).
   - `flutter analyze` (zero issues) + `flutter test` green.

## Open decision

**Where do review results surface?** Recommended: the **existing alerts list**
(REJECTED = urgent + note, APPROVED = quiet/info) — no new screen. A dedicated
"photo history" view is more work and should be scoped separately if wanted.

## Files to touch (summary)

- `lib/services/push_router.dart` — new kind + parse + callback
- `lib/services/push_messaging_service.dart` — dispatch (fg + bg) → alerts
- `lib/config/api_config.dart` — `/photos/reviews` path
- `lib/services/photo_service.dart` (or a new review service) — fetch reviews
- new `lib/models/photo_review.dart` — `PhotoReview`
- `lib/services/secure_storage_service.dart` — seen-reviews marker (dedupe)
- `lib/screens/home/home_screen.dart` — poll + surface
- `test/services/push_router_test.dart` (+ a reviews test)
- `documents/FLUTTER_API_GUIDE.md` — sync to 3-push version

## Not in scope here

- Wakefulness/wellness check — **no change** from this guide update (the manual
  supervisor-trigger note is wire-identical; the app already handles
  `WAKEFULNESS_CHALLENGE`).
- `PHOTO_REQUEST` `issued_at`/`response_seconds` + 90s window — **already done**.
