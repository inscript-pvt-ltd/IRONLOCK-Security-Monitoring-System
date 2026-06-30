# IronLock Guard Monitor — Backend Requirements

**Audience:** backend developer (Laravel API).
**Purpose:** one place for everything the **server** must do, drawn from the mobile security audit (`SECURITY_AUDIT.md`) and the shift-end spec (`BACKEND_SHIFT_END_SPEC.md`). The Flutter app is already built and reports honestly — but it runs on the guard's own phone, so **none of its decisions can be trusted**. The items below are the controls that must live server-side to be real.

> **The one principle:** the app runs on an untrusted device. The clock can be changed, the binary inspected, the network observed, any client check bypassed. Anything that matters for **payroll, compliance, or evidence** must be decided and enforced by the server. The app *requests* and *reflects*; the server *decides*.

---

## How to read this

Each item has a stable audit ID (e.g. `H1`) you can quote in commits/PRs. Each is structured as:

- **Why** — the hole, and what a malicious/careless client can do today.
- **App already does** — so you can test end-to-end; you're filling the server half.
- **Backend must** — the concrete requirement.

The detailed shift-end request/response shapes live in **`BACKEND_SHIFT_END_SPEC.md`**; this doc references it rather than duplicating it.

---

## Priority checklist

| Order | ID | Item | Effort | Detail |
|---|---|---|---|---|
| 1 | **C1** | Serve the API over **HTTPS** | Infra | [§1](#c1--https) |
| 2 | **H1** | Server decides early-vs-normal end from its **own clock** | Medium | [§2](#h1--server-decides-early-vs-normal-end) · spec §4.1 |
| 2 | **H2** | Server **scores welfare** checks + owns the tally | Medium | [§3](#h2--server-scores-welfare-checks) · spec §4.2 |
| 2 | **M1** | Echo `early_end_request` on **every** poll while outstanding | Small | [§4](#m1--echo-early_end_request-on-every-poll) · spec §4.3 |
| 3 | — | Early-end **approval endpoints** + auto-close job | Medium | spec §0–§3 |
| 3 | **BUG** | `GET /shifts/current` returns `null` for an **active** shift | Small | [§5](#bug--shiftscurrent-returns-null-for-an-active-shift) |
| 3 | **H5** | **Push** (FCM/APNs) for welfare/photo so backgrounded phones don't miss checks | Medium | [§H5](#h5-welfare-half--server-push-for-background-welfare-checks) |
| 4 | **L4** | Stamp **server receipt time** on photos / GPS pings | Small | [§6](#l4--server-authoritative-timestamps) |
| 4 | **C2** | Decide the photo **anti-replay** model (or drop it) | Design | [§7](#c2--photo-anti-replay-model) |
| 5 | — | Deploy the human-readable **`reference`** field on shifts | Small | [§8](#reference-field) |

**Why the grouping:** C1 (HTTPS) unblocks everything and also retires a temporary iOS workaround the app is carrying. H1/H2/M1 are the trust-critical decisions. The approval endpoints + auto-close are the functional backbone of ending a shift. The rest is hardening and hygiene.

---

## C1 — HTTPS

**Why.** The API is served over plain `http://`. The login **password**, the bearer **JWT**, the refresh token, every GPS ping, and every photo cross the network unencrypted. Anyone on the same Wi-Fi or any network hop can capture credentials and replay them. This undermines every other control — there's no point scoring welfare server-side if the token to impersonate the guard is sniffable.

**App already does.** Points `baseUrl` at the cPanel host. Android has a *scoped* cleartext exception (one host only), and iOS is carrying a **temporary** ATS exception for the same host (`ios/Runner/Info.plist`) purely so it can reach the HTTP backend at all.

**Backend must.**

- Serve the API over **HTTPS** with a valid TLS certificate.
- Once live, the app switches `baseUrl` to `https://…` and **both** platform cleartext exceptions are removed (Android `network_security_config.xml` + the iOS ATS block). Tell the mobile side when the HTTPS URL is ready.
- Optional, defence-in-depth: certificate pinning.

---

## H1 — Server decides early-vs-normal end

**Spec:** `BACKEND_SHIFT_END_SPEC.md` §4.1 (full contract).

**Why.** The app sends `ended_early` on `POST /end`, but it computes that flag from the **device clock**. A guard sets the phone clock past `scheduled_end`, `ended_early` becomes `false`, and the entire supervisor-approval requirement is skipped — the shift closes early with no reason and no sign-off. Bypassable from Settings → Date & Time.

**App already does.** Before `scheduled_end`, the END button routes through a request→approval flow (reason + note required). After `scheduled_end`, it sends a normal end. It sends `ended_early` as a hint, plus the approved `reason`/`note`.

**Backend must.**

- On `POST /shifts/{id}/end`, compute `is_early = server_now < scheduled_end` from the **server clock**. Ignore the client's `ended_early` for the decision (log it only as a claim).
- If `is_early`, allow the end **only** when an `approved` `early_end_request` exists; otherwise reject with `409 EARLY_END_NOT_APPROVED` and do **not** close the shift.
- If the client claimed `ended_early:false` but the server clock says early (clock tampering), treat it as early → require approval, and **flag the mismatch** for supervisor review.

---

## H2 — Server scores welfare checks

**Spec:** `BACKEND_SHIFT_END_SPEC.md` §4.2.

**Why.** The welfare code is server-issued, but today the **app** compares the entry locally, keeps its own passed/total counters, and only *posts* the result (swallowing the response). A tampered client reports a flawless attentiveness record the guard never earned — and supervisors have no trustworthy data.

**App already does.** Shows the server-issued code, accepts the guard's entry, compares locally for instant UX feedback, then `POST /wakefulness/{checkId}/respond`. It currently counts pass/fail locally for the end-of-shift summary.

**Backend must.**

- On `POST /wakefulness/{checkId}/respond`, compare the submitted entry against the code **the server issued**; record the authoritative pass/fail + `responded_at`. The client's self-assessed result is advisory.
- Score a **timeout as a fail** — if no valid response arrives in the window, the server marks it failed (don't rely on the app to report its own miss).
- Maintain the per-shift welfare summary (`welfare_passed` / `welfare_total`) from server records and expose it (e.g. on `GET /shifts/current` and/or the shift-end payload) so the app can display the **server's** tally wherever it feeds compliance/pay.

---

## M1 — Echo `early_end_request` on every poll

**Spec:** `BACKEND_SHIFT_END_SPEC.md` §4.3 / §0.3.

**Why.** The app drives the locked END button off `early_end_request.status` from `GET /shifts/current`. If the backend returns the object once and then drops it on later polls, the guard's UI loses the real server state (and a one-time `approved` can be missed).

**App already does.** Holds the `pending` lock locally as a safety net so a single dropped poll won't unlock the button — but this is a fallback, not a substitute for real server state.

**Backend must.**

- Include the `early_end_request` object on **every** `GET /shifts/current` response while it's `pending`, or `approved`/`rejected` but the shift hasn't ended yet.
- Echo it consistently across the whole `pending → approved/rejected → ended` lifecycle; stop only once the shift is `completed`/`cancelled`.

---

## Early-end approval endpoints + auto-close

**Spec:** `BACKEND_SHIFT_END_SPEC.md` §0–§3 (full request/response shapes). Summary of what's needed:

- **`POST /shifts/{id}/early-end-request`** — guard asks to leave early (`{reason, note}`); creates a `pending` `early_end_request`; does **not** end the shift.
- **Supervisor decision** (admin/dashboard endpoint, shape your call) — sets status to `approved`/`rejected` + `decided_at`/`decided_by`.
- **`GET /shifts/current`** — exposes `early_end_request` (see M1 / spec §0.3).
- **`POST /shifts/{id}/end`** — accepts `{ended_early, reason?, note?}`; persists `end_type` ∈ {`guard`, `early`, `auto`}.
- **Auto-close job** — the guarantee that every shift closes: for any `active` shift where `now > scheduled_end + GRACE` (suggest 30–60 min), set `completed` / `end_type:auto`, `actual_end = scheduled_end` (recommended), compute duration, flag `auto_closed:true`. Without this, a forgotten/dead/offline phone leaves a shift open forever.

---

## BUG — `/shifts/current` returns `null` for an active shift

**Why.** Once `scheduled_start` passes, `GET /shifts/current` reportedly returns `null` for a shift that is actually `active`. That's a contract violation: the app can't resume an in-progress shift after a relaunch, and the END button can't render. (The app guards against this by not letting a `null` poll wipe an active local shift — but it can't *recover* a shift it never learned about.)

**Backend must.** Return the active shift from `GET /shifts/current` for its whole lifecycle — `scheduled` → `active` → until `completed`/`cancelled` — including `can_start`/`can_end` computed server-side.

---

## L4 — Server-authoritative timestamps

**Why.** `captured_at` on photos and `recorded_at` on GPS pings are set from the **device clock** — spoofable. Fine for telemetry, not for evidence.

**Backend must.** Stamp the **server receipt time** as the authoritative timestamp on photos and location pings; treat the client-sent times as advisory only (keep them for diagnostics/anomaly detection if useful).

---

## C2 — Photo anti-replay model

**Why.** The app signs photo uploads with `signature = HMAC-SHA256(secret, "nonce:shiftId:capturedAt")` and 15 client-generated `nonce`s. But the secret is a **constant compiled into the app** (extractable from the APK/IPA) and the nonces are **client-issued** — the server never handed them out, so it has no list to detect replay against. As shipped this prevents only *accidental* duplication; it provides no real integrity, and the app's docs say the backend may ignore these fields.

**Backend must — decide one of:**

- **If anti-replay matters:** the **server issues nonces** (one per photo request, tracked, single-use) and holds a **per-session or per-device key** that never ships in the binary. Verify the signature server-side; reject reused nonces.
- **If it doesn't:** drop the `nonce`/`signature` fields honestly rather than implying integrity that isn't there. Either way, server-side, reject/flag implausible images (e.g. a 1×1 pixel — see audit H6).

This is a design decision for whoever owns photo-evidence requirements; flag your choice back to the mobile side so the client fields can be kept or removed in sync.

---

## `reference` field

Deploy the human-readable shift **`reference`** (e.g. `"SH-2847"`) on shift payloads. The app prefers it for display and falls back to a UUID-derived code when absent — so this is cosmetic, but it makes shifts legible to guards and supervisors.

---

## H5 (welfare half) — server push for background welfare checks

**Why.** The app now tracks **location** in the background (foreground service on Android, background location on iOS). But the welfare/photo **poll** is a foreground timer — on iOS it's suspended when the app is backgrounded, so a guard who locks the phone can miss welfare checks. There's no app-side fix for this on iOS: background Dart execution is too restricted.

**Backend must (to fully close H5).** Deliver welfare and photo prompts via **push notification** (FCM for Android, APNs for iOS) instead of relying on the client poll. The app would register a device push token at login and react to the push by showing the welfare overlay / photo screen. This is the standard, reliable mechanism for waking a backgrounded app. Until it exists, background welfare is best-effort on Android and foreground-only on iOS — location tracking is unaffected.

---

## Out of scope here (app-side / not backend)

For completeness, these audit items are handled in the app and need **nothing** from the backend: H3 (token-refresh sign-out), H6 (placeholder-photo block — though server-side 1×1 rejection is a nice backstop), M2/M3 (client teardown/poll robustness), M4 (location-off warning), M5 (photo lat/long — the server just needs to accept the existing `latitude`/`longitude` fields), M6 (zone honesty), M7 (release signing), M8 (sign-out bypass), and **H5 background GPS** (done app-side). The H5 **welfare** half above is the only background-execution item that needs the backend.

---

## Quick reference — endpoints touched

| Endpoint | Change |
|---|---|
| *all* | Serve over HTTPS (C1) |
| `GET /shifts/current` | Echo `early_end_request` every poll (M1); return active shifts, not `null` (BUG); expose server welfare tally (H2); include `reference` |
| `POST /shifts/{id}/end` | Decide early-vs-normal by server clock + require approval (H1); persist `end_type` |
| `POST /shifts/{id}/early-end-request` | New — create pending request (spec §0.1) |
| admin approve/reject | New — set decision (spec §0.2) |
| `POST /wakefulness/{checkId}/respond` | Server scores pass/fail; timeout = fail (H2) |
| `POST /shifts/{id}/photos` | Server receipt-time timestamp (L4); decide nonce model (C2); reject implausible images |
| `POST /shifts/{id}/locations` | Server receipt-time timestamp (L4) |
| *(job)* | Auto-close overdue shifts (spec §2) |
