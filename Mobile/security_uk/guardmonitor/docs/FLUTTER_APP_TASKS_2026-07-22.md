# App tasks from the offline-sync test (2026-07-22)

**From:** backend/dashboard (Jerry)
**To:** Flutter app (Guard Monitor)
**Re:** device test of the offline path — items that are on the **app** side

Good session — offline **wakefulness** (notify → answer → flush → tagged Offline) and offline **photo**
(capture → queue → flush → stored) both worked end to end. Four items are app-side. Backend already
did its half of #1 (see the note under it). The backend-owned items (reconnect clustering, delayed-flag
on offline photos, offline photo badge) are on me and don't need anything from you.

---

## 1. 🟡 Online photo push is silent — please raise a local notification for `PHOTO_REQUEST`

**What we saw:** the online **photo** window opened with **no notification**, while the **wakefulness**
challenge did pop a heads-up notification.

**Backend check:** the server sends the photo push through the **exact same** FCM path as wakefulness —
same `sendToDevice(token, title, body, data)` call. The photo data message is:

```json
{
  "type": "PHOTO_REQUEST",
  "request_id": "...",
  "shift_id": "...",
  "nonce_value": "...",
  "issued_at": "<ISO8601 UTC>",
  "response_seconds": 90
}
```

So both are delivered identically. The window still opened for you because the `GET /shifts/{id}/photos/pending`
poll caught it — that's the fallback, not the push.

**Ask:** handle the `type: "PHOTO_REQUEST"` data message the same way you handle `WAKEFULNESS_CHALLENGE`
— turn it into a visible local notification (title "Photo required", body "Open the app now to capture a
live verification photo"). On iOS, a data-only push won't show on its own; schedule a local notification
on receipt like you already do for wakefulness.

---

## 2. 🔴 Old notification tapped → screen locks under a dark faded layer

**What we saw:** tapping an **old** wakefulness/photo notification opened the app, then a black faded
layer covered the screen — no buttons, no refresh, stuck.

**Diagnosis:** a modal barrier/scrim is shown for a challenge/request that's no longer live (already
expired or answered), and the barrier never dismisses.

**Backend help shipped:** `photos/pending` now filters on the **live deadline**, so it will **not**
return an expired request anymore. So "the request isn't in pending / server says expired" is a reliable
"close this screen" signal.

**Ask:** when opening a notification, validate the request/challenge is still live before showing the
modal:
- Photo: check it's still in `photos/pending` (or the countdown from `issued_at + response_seconds`
  hasn't passed). If gone/expired → show "This request has expired" and **dismiss the barrier**.
- Wakefulness: same idea against `wakefulness/pending` (`expires_at`).
- Never leave a scrim up with no live request behind it.

---

## 3. 🟡 Simultaneous manual wake + photo — one modal kills the other

**What we saw:** when a supervisor fires manual wakefulness and photo at the same time, one opens and the
other flashes and disappears after ~1s. Intermittent.

**Backend check:** both are recorded as independent requests (the timeline shows both). The server isn't
conflating them — this is two challenge modals racing on the client.

**Ask:** serialize challenge presentation — **queue** simultaneous challenges and show them one after
another (e.g. present wakefulness, and on completion present the photo, rather than both trying to own
the screen at once). A single "active challenge" gate with a FIFO queue fixes it.

---

## 4. 🟢 Offline photo "connect internet and retry" — looks resolved, please confirm

Earlier the offline photo capture failed with "connect the internet and retry." In this test it
**worked** — captured offline and flushed on reconnect. Just confirming the offline-pool capture +
on-device queue is now in place and stable on flaky signal. If it was a one-off fix, great; if anything
still forces internet at capture time, that's the thing to close out.

**Reminder (unchanged contract):** offline photos work exactly like offline wakefulness — capture against
a **pre-fetched `OFFLINE_POOL` nonce**, queue on device, and upload to the normal
`POST /shifts/{id}/photos` on reconnect. No special offline endpoint; the `nonce_value` is the anchor.

---

## Not yours — for your awareness (backend is handling)

- **Reconnect clustering:** the online wake + photo firing at the same second right after reconnect is a
  **backend** dispatch issue (we retroactively fired marks that came due during your outage). I'm adding
  a staleness guard so we don't re-fire marks you already answered offline. You may keep seeing it until
  I deploy.
- **`DELAYED_UPLOAD` on offline photos:** the flag on your offline flush is a backend false-positive
  (we measured capture→upload without accounting for the offline hold). I'm suppressing it for offline
  captures. Nothing to change on your side.
- **Offline photo not tagged "Offline" on the dashboard:** display gap on my end; the data you send is
  fine.

Ping me with raw request/response if any of the app items behave differently against the branded host.
