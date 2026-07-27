# Investigation — Offline wakefulness challenge shows a 3-digit code (some devices)

**Date:** 2026-07-27
**Reported by:** field / QA
**Owner:** Flutter app
**Status:** ✅ **FIXED — the real cause was a DISPLAY-CLIPPING bug in the overlay (current code), NOT a stale build.** The earlier "stale build" call was wrong: a device confirmed updated still showed 3 digits, which disproves it.

---

## ✅ Real root cause (2026-07-27, cont.) — the code was clipped, not short

The wakefulness logic is correct — `_normalizeCode` always pads to 4 and the TOTP vectors
pass. The bug was **purely visual, in `_CodeDisplay` (`wakefulness_overlay.dart`)**:

- The code was drawn as `code.split('').join('   ')` (triple-spaced) at `fontSize: sp(40)`
  **plus** `letterSpacing: 8` — an extremely wide string — inside a `Container` with **no width
  cap**, with `maxLines: 1` and **`overflow: TextOverflow.visible`**.
- `overflow.visible` does **not** shrink or wrap: when the string is wider than the screen it
  paints past both edges (`TextAlign.center`) and the ancestor **clips** it. On a **narrow /
  smaller device** the outermost digit — usually the padded **leading `0`** — is cut off the
  edge, so a true `0472` renders on-screen as `472`: a **3-digit-looking code** the 4-cell pad
  can never complete.
- Fits every qualifier: **"some devices"** = narrow screens; **overlay shows 3** = its own
  rendering; **online *and* offline** = same widget; **persists after update** = the widget was
  still in current code.

**Fix:** wrap the code `Text` in `FittedBox(fit: BoxFit.scaleDown)` and give the container
`width: double.infinity`, so 4 digits always fit (scaled down if tight) and can never be
clipped. `flutter analyze` clean.

The TOTP-parity work below still stands (it proved the *logic* was never the problem, which is
what pointed the finger at rendering). Keep the backend length-mismatch WARNING log as a belt-
and-braces monitor.

---

## ⛔️ Superseded conclusion (kept for history) — "stale build"

Both remaining non-stale-build theories are now **eliminated**, and the current app is **provably
correct online and offline**:

- **Our offline TOTP is byte-for-byte identical to the backend** — verified all **13** of Jerry's
  test vectors incl. every **leading-zero** case (`window 58000004 → 0690`, etc.); each returns a
  4-char code with the zero kept. Locked in as `test/services/totp_backend_vectors_test.dart`.
- **`totp_digits` is always 4** (global config, **never** per-site; clamped 4–8 server-side) → **Cause C dead.**
- **The server has never emitted a 3-char code** — `str_pad(..,4)` / `random_int(1000,9999)`, and
  the tray body + `data.code` come from the **same** variable so they can't disagree → **Cause B impossible.**
- The current app pads/normalises on **both** paths (`trigger` + `triggerLocal` → `_normalizeCode`,
  overlay shows `state.code`); `472 → 0472` is asserted by passing tests.

⇒ Since the current build cannot render 3 digits and the server never sent them, a device showing a
3-digit code **must be running an app binary older than the 2026-07-21 padding fix.** **The fix is
to update those devices.** The decisive artefact is the **app version on an affected device.**

Backend also (a) widened the code columns `varchar(4)→(8)` (a 5th char was 500-ing instead of
FAILing) and (b) added a length-mismatch `WARNING` log — if it fires after our build ships, a live
device is still dropping a zero and Jerry can name the guard/check. Online codes move to 1000–9999
(no leading zero at all) on the next deploy; our online leading-zero restore then becomes harmless
dead code. Rollout is **not blocked** either way.

**Optional (endorsed, not urgent):** the step-3 hardening below now only guards the **5–8 digit**
case (digits can no longer be < 4), so it's defence-in-depth for a future deliberate config, not a
fix for this report.

---

## Symptom

On **some devices**, a guard receives a wakefulness challenge and sees a **3-digit code**
(instead of 4). The 4-cell entry pad can't be completed / the code doesn't match.

Reported qualifiers:

- First seen **offline** — notification arrives, guard opens it, and the **overlay** shows 3 digits.
- **Update:** it also happens **online**.

**Why "online too" matters:** the online and offline paths both run the code through the same
`_normalizeCode` (pad-to-4) in the current build. If **both** show 3 digits, the cause is almost
certainly **NOT** a per-shift `totp_digits` value (that only affects the offline TOTP), and instead
points to either a **stale app build** (no padding on either path) or the guard reading the code
from the **notification tray** (server-authored text the app can't rewrite). See the refined
root-cause section.

---

## What the app does today (traced)

Everything the current build controls is **4-digit**:

- **Overlay display:** `_CodeDisplay(code: state.code)` renders `state.code`.
- **`state.code`:** always set from `_normalizeCode(...)`, which pads a short code to 4
  (`digits.padLeft(kWakefulnessDigits, '0')`, `kWakefulnessDigits = 4`) — so `472` → `0472`.
- **Keypad:** fixed at 4 cells (`List.generate(kWakefulnessDigits, …)`).
- **Offline code source:** `Totp.codeForWindow(seed, win, digits: prov.digits)`, which itself
  `padLeft(digits, '0')`.

**Offline path:** `WakefulnessScheduleNotifier.checkSchedule()` → `Totp.codeForWindow(digits: prov.digits)`
→ `WakefulnessNotifier.triggerLocal(code)` → `_normalizeCode` → overlay.

So in the **current build**, an offline code is displayed as **4 digits, always**. For a device to
show **3**, one of the two causes below must be true.

---

## Root cause — ranked (now that ONLINE is affected too)

Because online + offline **both** go through `_normalizeCode` (pad-to-4) in the current build, and
both show 3 digits, the ranking has shifted:

### A) The affected devices are on an OLDER app build  ← most likely

`_normalizeCode` pad-to-4 is applied to **both** `trigger` (online) and `triggerLocal` (offline),
and shipped on **2026-07-21**. A build **older** than that pads **neither** path → both online and
offline show the raw 3-digit code. "Both paths, only some devices" is a textbook mixed-version
fleet — **this is the single best fit for the new symptom.**

- **Confirm:** check the installed app version on an affected device vs a working one.
- **Fix:** update those devices to the current build. **No code change needed.**

### B) The guard is reading the code from the NOTIFICATION tray (online only)

Online, the wakefulness FCM push carries a server-authored `notification` block that the OS draws
in the tray. If the server didn't zero-pad the code there, the **tray** shows 3 digits even though
the in-app **overlay** shows 4. (Offline the local notification shows **no** code, so this can't
explain the offline sighting — but it can explain an online one.)

- **Confirm:** on an affected device, compare the **tray** text vs the big gold **overlay** code.
  Tray = 3, overlay = 4 ⇒ this case.
- **Fix:** backend zero-pads the `code` in `data.code` **and** the `notification` body/title
  (`docs/BACKEND_ASKS_2026-07-24.md` #3).

### C) The backend is sending `totp_digits` ≠ 4  ← now unlikely as the sole cause

`prov.digits` comes from the backend's **`totp_digits`** at shift start and only affects the
**offline** TOTP length. It **cannot** make an **online** code 3 digits (online takes the server's
`code` verbatim). So if online is genuinely 3 digits in the overlay, this isn't the cause on its
own — but it's still worth confirming, because a non-4 value would independently break offline
(see the latent bug below).

- **Confirm:** ask backend (Jerry) what `totp_digits` is for the affected site(s).

---

## ⚠️ Latent bug this exposes (regardless of A or B)

The app **hardcodes 4-digit codes** in three places:

1. `kWakefulnessDigits = 4` — drives the keypad width and the "entry complete" check.
2. `_normalizeCode` **force-pads to 4** — for the ONLINE path this is correct (restores a dropped
   leading zero the server still validates as 4). For the **OFFLINE** path it is only safe **if
   `prov.digits == 4`**.
3. The keypad renders exactly 4 cells.

If the backend ever sends `totp_digits != 4`:

- **`totp_digits = 3`** → the offline TOTP is `472`; `_normalizeCode` force-pads to `0472`; the app
  sends `0472`; the server recomputes `472` → **MISMATCH → a correct answer is recorded as a miss.**
- **`totp_digits = 5+`** → won't fit the 4-cell pad; `_normalizeCode` returns null (`> 4`) → the
  challenge never raises → recorded as a miss.

So a non-4 backend config silently breaks offline wakefulness even beyond the display glitch.

---

## Recommended action

1. **Decisive check (2 minutes, do first):** on one affected device —
   - note the **app version**, and
   - open the challenge and compare the **big gold overlay code** vs the **notification tray** code.

   | Overlay | Tray | Verdict |
   |---|---|---|
   | 3 digits | (any) | **Old build (A)** → update the device; no code change |
   | 4 digits | 3 digits | **Server tray text (B)** → backend pads the push body |
   | 4 digits | 4 digits | Already fixed on that device |

2. **Confirm with backend:** zero-pad the wakefulness `code` in `data.code` **and** the push
   `notification` body/title, and confirm `totp_digits` is **always 4** (`docs/BACKEND_ASKS_2026-07-24.md`
   #3).
3. **App hardening (defense-in-depth, do regardless):** make the wakefulness code length respect
   `prov.digits` end-to-end —
   - keypad width + "entry complete" check use the code's actual length, not the fixed `4`;
   - **stop force-padding the OFFLINE code** (it's already correct per `prov.digits`; padding it is
     what would cause the server mismatch); keep the ONLINE leading-zero restore;
   - if a code's length is outside a sane range (e.g. 3–8), fail safe (stay idle → server raises its
     own miss) rather than showing an un-enterable code.
   This makes the app correct for any digit count the backend chooses, and removes the offline
   mismatch risk.

---

## Files involved (for whoever implements the hardening)

- `lib/providers/wakefulness_provider.dart` — `kWakefulnessDigits`, `_normalizeCode`, `trigger`,
  `triggerLocal`; `WakefulnessProvisioning` (`digits`), `WakefulnessScheduleNotifier.checkSchedule`.
- `lib/overlays/wakefulness_overlay.dart` — the 4-cell keypad + `_CodeDisplay`.
- `lib/services/totp_service.dart` — `codeForWindow(..., digits:)` (already pads to `digits`).

---

## Answer we need

- The **app version** on an affected device (confirms/eliminates cause A), and
- Backend confirmation that **`totp_digits` is always 4** (confirms/eliminates cause B).

Whichever it is, the app-side hardening in step 3 is worth doing so a future non-4 config can't
regress offline wakefulness.
