# Backend Spec — Photo Request: screen-off delivery + server-anchored timer

**Date:** 2026-06-25
**Audience:** backend developer
**Scope:** the `PHOTO_REQUEST` FCM push and `GET /shifts/{id}/photos/pending`.

> **Update 2026-06-25 — backend shipped items 2 & 3.** Both the push data map and
> `GET /shifts/{id}/photos/pending` now carry `issued_at` + `response_seconds`
> (the poll also has `expires_at`). The backend also **collapsed the nonce TTL
> and the request timeout into one 90 s window** (`nonce TTL = timeout =
> response_seconds`) — removing a hidden dead zone where the nonce died at 60 s
> but the request didn't time out until 90 s (an upload at ~70 s used to hit
> `NONCE_EXPIRED` → CRITICAL despite the timer showing time left). The app
> consumes all of this already; **no app change was needed.** Only **item 4
> (APNs key)** remains, on the ops side. The rest of this doc is kept for the
> record.

The app now anchors the photo response countdown to **when the request was
raised**, not when the guard opens the camera, and surfaces the request when the
screen is off. Most of this works on **Android today with no backend change**.
This doc lists the small, optional additions that make the timer *exact* and
flags the one ops step required for iOS.

---

## TL;DR — what the app needs

| # | Item | Required? | Platform |
|---|------|-----------|----------|
| 1 | Keep sending the `notification` block on `PHOTO_REQUEST` (already done) | ✅ shipped | Android + iOS |
| 2 | Add `issued_at` + `response_seconds` to the push **data** | ✅ shipped | Android + iOS |
| 3 | Add `issued_at`/`expires_at` + `response_seconds` to `GET /photos/pending` | ✅ shipped | Android + iOS |
| 4 | Upload the **APNs auth key** to Firebase | 🔒 required for iOS only — **outstanding** | iOS |

Items 2–3 are **backward-compatible** and now **shipped** on the backend — the
app consumed them without a code change. The only remaining item is **4 (APNs)**.

---

## 1. Notification block (already correct — don't remove it)

The app relies on the OS drawing the lock-screen notification when the app is
backgrounded/locked. That only happens when the push carries a `notification`
block. The current payload already does:

```
notification.title = "Photo required"
notification.body  = "Open the app now to capture a live verification photo."
data = { "type":"PHOTO_REQUEST", "request_id":"<uuid>", "shift_id":"<uuid>",
         "nonce_value":"<hex>" }
```

✅ Keep this exactly as-is. The guard tapping the notification opens the app and
the app routes straight to the camera.

---

## 2 & 3. Add timing fields so the countdown is exact

> ✅ **Shipped 2026-06-25.** The push sends `issued_at` + `response_seconds`; the
> poll sends `issued_at` + `expires_at` + `response_seconds`. The app anchors to
> `issued_at` and computes `deadline = issued_at + response_seconds` — matching
> the server's authoritative window exactly. The text below is the original ask,
> kept for reference.

Today the data payload has **no timestamp**, so the app can only anchor the 90s
window to *when the device received the push*. That's close, but it drifts by
the push-delivery latency and can't survive a force-stopped app. To make it
exact, include **one** of these in **both** the push `data` map **and** the
`GET /shifts/{id}/photos/pending` response body:

**Preferred — `issued_at`** (when the server raised the request):

```jsonc
// push data (all values are strings — FCM data is string→string)
{
  "type": "PHOTO_REQUEST",
  "request_id": "<uuid>",
  "shift_id": "<uuid>",
  "nonce_value": "<hex>",
  "issued_at": "2026-06-25T14:05:00.000000Z",   // ISO-8601 UTC
  "response_seconds": "90"                        // window length; string in push
}
```

**Or — `expires_at`** (when the request times out). The app back-computes the
issue time as `expires_at − response_seconds`:

```jsonc
{ ...,
  "expires_at": "2026-06-25T14:06:30.000000Z",
  "response_seconds": "90"
}
```

For the **poll** body (`GET /shifts/{id}/photos/pending`), the same fields,
JSON-typed normally (number for `response_seconds`):

```jsonc
{
  "success": true,
  "data": {
    "pending": true,
    "request_id": "<uuid>",
    "nonce_value": "<hex>",
    "issued_at": "2026-06-25T14:05:00.000000Z",   // or "expires_at"
    "response_seconds": 90
  }
}
```

Field reference:

| Field | Type | Notes |
|-------|------|-------|
| `issued_at` | ISO-8601 UTC string | When the request was raised. The app's preferred countdown anchor. |
| `expires_at` | ISO-8601 UTC string | Alternative to `issued_at`; the app derives `issued_at = expires_at − response_seconds`. |
| `response_seconds` | int (string in FCM data) | Total window. Defaults to **90** in the app if omitted. |

> **Whatever the server uses as the authoritative timeout, send it here.** The
> app will then show exactly the time the server will honour — so the guard never
> wastes effort on a request the server has already expired (`NONCE_EXPIRED`).

---

## 4. APNs key upload (iOS only — required for screen-off on iOS)

iOS cannot show any notification for a suspended app unless the push is an APNs
alert. Per `APNS_KEY_UPLOAD.md`, until the **APNs auth key (.p8)** is uploaded in
Firebase → Project Settings → Cloud Messaging, **iOS devices receive no
background pushes at all** — so the photo notification never appears on iOS.

This is an **ops step on the Firebase/Apple side**, plus the app-side iOS FCM
config (`GoogleService-Info.plist` + capabilities, see
[`FCM_SETUP.md`](FCM_SETUP.md)). Android is unaffected.

---

## 5. Same ask for the wakefulness push (⭐ optional, not shipped)

The `WAKEFULNESS_CHALLENGE` push has the exact same situation the photo push
just had. Today it sends:

```jsonc
// push data (all values are strings — FCM data is string→string)
{ "type":"WAKEFULNESS_CHALLENGE", "check_id":"<uuid>", "shift_id":"<uuid>",
  "code":"<4-digit>", "response_seconds":"60" }
```

The app now runs a **server-anchored, deadline-based countdown** for wakefulness
too (it parses `issued_at` already — `PushMessage.issuedAt`), but since the push
carries **no `issued_at`** it falls back to anchoring on *device arrival time*.
That drifts by the push-delivery latency. The gap is purely that the window
starts from arrival rather than the true server issue time — the freeze-the-clock
case is already handled app-side (the countdown re-syncs on resume).

**Ask:** add `issued_at` (ISO-8601 UTC) to the `WAKEFULNESS_CHALLENGE` push data:

```jsonc
{
  "type": "WAKEFULNESS_CHALLENGE",
  "check_id": "<uuid>",
  "shift_id": "<uuid>",
  "code": "<4-digit>",
  "issued_at": "2026-06-25T14:05:00.000000Z",   // ← add this
  "response_seconds": "60"
}
```

The app then anchors `deadline = issued_at + response_seconds`, identical to the
photo flow. Fully backward-compatible: absent → arrival-time fallback (today's
behaviour); present → exact. Whatever `response_seconds` the server treats as the
authoritative miss threshold is what it should send, so the guard's countdown
matches the server's.

> The app already treats the **server's `respond()` verdict** (`PASSED`/`FAIL`,
> or a 4xx) as authoritative over its local code compare — so even without
> `issued_at`, a server-side expiry is honoured. `issued_at` just makes the
> *visible* countdown match the server, so the guard never wastes effort on a
> window the server has already closed.

---

## Fallback behaviour (so you know nothing breaks)

The app degrades cleanly when items 2–3 are absent:

1. **`issued_at`/`expires_at` present** → exact server-anchored countdown.
2. **Absent, push arrived while backgrounded (Android)** → the app stamps the
   arrival time in its FCM background isolate and anchors to that. Accurate to
   within delivery latency.
3. **Absent, foreground push or poll** → anchored to the moment the app saw it.
4. **No anchor at all** (e.g. cold-start tap where the background isolate didn't
   run) → falls back to a full **90s** window from when the camera opens.

In every case the window length defaults to **90s** unless `response_seconds`
says otherwise.

---

## App-side reference (already implemented this session)

- Countdown helper: `photoSecondsRemaining(...)` in
  [`lib/providers/photo_provider.dart`](../lib/providers/photo_provider.dart)
  (`kPhotoWindowSeconds = 90`).
- Push parse: `issued_at` / `response_seconds` in
  [`lib/services/push_router.dart`](../lib/services/push_router.dart).
- Background arrival stamp:
  [`lib/services/push_messaging_service.dart`](../lib/services/push_messaging_service.dart)
  `_backgroundHandler` → `SecureStorageService.savePhotoReceipt`.
- Poll parse: `extractPendingPhoto(...)` in
  [`lib/screens/home/home_screen.dart`](../lib/screens/home/home_screen.dart)
  (reads `issued_at`/`expires_at`/`response_seconds`).
- Anchored window opened in
  [`lib/screens/photo/photo_screen.dart`](../lib/screens/photo/photo_screen.dart)
  `_openWindow()`.
