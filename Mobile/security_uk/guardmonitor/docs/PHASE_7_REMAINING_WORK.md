# IronLock Guard Monitor — Remaining work (not yet done)

**As of:** 2026-06-30. Branch `saduka`. **143 tests pass · `flutter analyze` clean.**

This is the honest "not done yet" list. Phase 7 (offline sync) is **code-complete and
unit-tested** end to end; what's left is mostly **on-device verification** plus a few small
follow-ups and some pre-existing (non-Phase-7) platform tasks. Each item says **what**, **who
owns it** (App / Backend / Device-test / Apple-gated), and rough **effort**.

Legend: 🔴 blocks "fully shipped" · 🟡 should-do · 🟢 optional/later.

---

## A. Phase 7 — offline sync: what's left

Everything implementable in code is done (GPS + wakefulness + photo capture/queue/flush/retry,
SQLCipher queue, NTP-anchored time, the offline-photo schedule trigger). The remainder can only
be ticked on real hardware / the dashboard.

### A1. 🔴 On-device verification — Android + iOS  · Device-test · ~half a day
Nothing left to code; this is a test pass that the simulator/host can't cover.
- [ ] Build with native assets enabled (`flutter config --enable-native-assets` is set on the
      dev machine — confirm it's on for whoever builds), run on a **real Android device** and a
      **real iPhone**.
- [ ] Confirm the encrypted queue **opens on device**: SQLCipher (`source: sqlcipher` build hook)
      links and `PRAGMA cipher_version` is non-empty on both platforms. (Verified on host =
      macOS only; Android/iOS use different prebuilt binaries.)
- [ ] **Force a shift offline** (airplane mode) for >60 s while moving, then reconnect. Confirm:
      GPS backlog flushes as one `pings[]` batch, the queue empties, and the **dashboard shows the
      "offline / backfilled" band** (`COMMS_GAP_START/END` + `SYNC_FLUSH`) — confirm with Jerry.
- [ ] Offline **wakefulness**: answer a scheduled TOTP challenge while offline → reconnect →
      confirm it replays and resolves (or `ALREADY_RESOLVED`).
- [ ] Offline **photo**: let a `photos.schedule` mark fire while offline → capture → reconnect →
      confirm the photo lands as a `SCHEDULED` evidence record on the dashboard.

### A2. 🔴 EXIF ↔ NTP cross-check on a real capture  · App + Device-test · ~half a day
The server flags `CLOCK_MANIPULATION_SUSPECTED` if the photo's **EXIF timestamp** and our
`ntp_reference` differ by **>30 s**. We've not run a real camera capture to verify the EXIF the
`camera` plugin writes.
- [ ] On device, capture an offline photo and inspect the saved JPEG's EXIF `DateTimeOriginal`.
- [ ] Confirm `|EXIF − ntp_reference| ≤ 30 s`. If the plugin **doesn't** write EXIF (or writes a
      wall-clock value that can drift), stamp EXIF ourselves from the NTP-anchored capture time
      (e.g. `native_exif`). **Add a test** asserting the ≤30 s bound.

### A3. 🟡 `elapsed_seconds` — confirm the projection with backend  · Backend Q · ~5 min
The contract example shows `elapsed_seconds: 37.2`. We send **`elapsed_seconds: 0`** and
pre-project `ntp_reference` forward to the shutter instant via the monotonic clock — mathematically
identical (`reconstructed = ntp_reference + elapsed`) and keeps `ntp_reference` aligned with EXIF.
- [ ] One-line confirm with Jerry that `elapsed_seconds: 0` + a pre-projected `ntp_reference` is
      accepted. If the server expects a non-zero elapsed, switch to storing `ntp_reference = anchor`
      + the real monotonic elapsed (small change in `TimeAnchorService`/`OfflinePhotoService`).

### A4. 🟡 Honor `max_photos_per_capture` from the schedule  · App · ~30 min
`PhotoProvisioning.maxPhotosPerCapture` is parsed but the capture screen still caps on the
hard-coded `kMaxPhotosPerRequest = 5`. Both are 5 today, so no live mismatch — but a per-shift
server value below 5 would be ignored.
- [ ] Thread the provisioned `maxPhotosPerCapture` into `PhotoScreen.scheduled()` (and ideally the
      online path) instead of the constant.

---

## B. Pre-existing / non-Phase-7 (already known, still open)

These predate Phase 7 and were tracked in earlier handoffs; listing here so nothing is lost.

### B1. 🔴 iOS APNs (background pushes)  · Apple-gated · see `APPLE_DEVELOPER_ACCOUNT_TODO.md`
iOS gets FCM **through APNs**. Until the `.p8` APNs key is uploaded to Firebase + the Push
Notifications capability is on the provisioning profile, iOS devices **don't receive background**
photo/wakefulness pushes, and a **manual/online wakefulness check can't reach iOS at all** (no poll
fallback for it). Scheduled TOTP wakefulness + foreground photo polling already work without push.
- [ ] Apple Developer account → APNs `.p8` key → upload to Firebase; add Push capability in Xcode.

### B2. 🟡 Universal Links / App Links for the SSO https link  · Apple-gated + App
The `ironlock://` custom scheme works (via the backend bounce page). The raw **https** SSO link
opening the app **directly** needs Associated Domains (`applinks:dashboard.ironlock.co.uk`) +
Apple Team ID, and the Android `assetlinks.json` + signing SHA-256.
- [ ] Add once the Apple account + a signed Android release exist.

### B3. 🟡 Certificate pinning  · App (needs backend input) · `SECURITY.md` P1 #2
HTTPS is live; pinning the backend cert/public key (SPKI hash) in Dio would stop a rogue/mis-issued
CA. **Unblocked — needs the cert/SPKI hash from the backend.**
- [ ] Get the SPKI hash from Jerry; add a `badCertificateCallback`/pinning interceptor.

### B4. 🟢 Release-build obfuscation  · App · `SECURITY.md` P1 #3
`flutter build … --obfuscate --split-debug-info=build/symbols` at release time. Keep the symbols
for crash de-obfuscation.

### B5. 🟡 Confirm the production API host on device  · Device-test
Confirm `https://dashboard.ironlock.co.uk/api/mobile/v1` actually serves the mobile API end to end
from a real device sign-in (was a standing open item after the HTTPS migration).

---

## C. Explicitly NOT our job (per the contracts — do not build)

So no one re-opens these by mistake:
- ❌ No client-side `sync_queue` "sync complete" receipt / endpoint — the server is idempotent
  (`PHASE_7_SYNC_INTEGRITY.md` §6).
- ❌ No client de-duplication before sending — re-send freely.
- ❌ Don't send `COMMS_GAP_*` / `SYNC_FLUSH` — the server derives them.
- ❌ Don't manage retroactive alerts — the server suppresses them for backfilled data.
- ❌ No GPS breadcrumb/history store — `guard_locations` is single-row by design.

---

## Quick priority view

| Priority | Item |
|---|---|
| 🔴 Must, before "shipped" | A1 on-device verify · A2 EXIF/NTP check · B1 iOS APNs |
| 🟡 Should | A3 elapsed_seconds confirm · A4 max_photos · B2 Universal Links · B3 cert pinning · B5 host confirm |
| 🟢 Optional/later | B4 obfuscation |

> Everything in **§A (Phase 7)** that can be done in code is done + tested. The 🔴 Phase 7 items
> (A1, A2) are **device/dashboard verification**, not new features.
