# IronLock Guard Monitor — Improvement Report

**Date:** 2026-06-24
**Scope:** App-side improvements not already tracked in [`SECURITY_AUDIT.md`](SECURITY_AUDIT.md), plus a few tracked items re-surfaced by priority.

This report is grounded in the current codebase. Items already covered by the security
audit are listed under P4 so the overall priority is clear, but the detail for those
lives in the audit and backend docs.

---

## 🔴 P1 — Functional gaps that matter for a lone-guard safety app

### 1. No offline queue — location / photo / welfare data is silently dropped offline

The biggest gap. A failed GPS ping is swallowed in
[`gps_service.dart:140-142`](../lib/services/gps_service.dart#L140-L142) with the comment
*"offline queue phase will handle persistence"* — but that phase doesn't exist. The same
fire-and-forget pattern applies to photo uploads and welfare responses.

For a guard in a basement / car park / dead-spot, this loses the exact location trail and
evidence the system exists to capture.

**Fix:** add a persistent queue (Hive / sqflite / Drift) that records pings and pending
uploads on failure and replays them on reconnect. `isOnlineProvider` already exists and can
trigger the flush.

### 2. Errors are swallowed everywhere with no observability

Many `catch (_) {}` blocks (GPS, polling, photo, token refresh). In the field this means
zero visibility when something breaks — there is no crash or error reporting.

**Fix:** integrate Sentry or Firebase Crashlytics, and report swallowed exceptions instead
of discarding them.

### 3. Welfare / photo checks don't arrive when backgrounded

Tracked as **H5** in the audit. Background GPS is done, but the 20-second foreground poll
means a backgrounded / locked phone never receives a welfare or photo request — a guard
could miss a wakefulness check without knowing.

**Fix:** FCM / APNs push delivery (backend work).

---

## 🟠 P2 — Test coverage is thin for the risky parts

Only 22 tests, and **none** cover the highest-churn, highest-risk areas:

- **Permission gate** — the double-pop bug fixed on 2026-06-24 had no test and would
  silently regress.
- **Photo state machine** — the new `idle → capturing → reviewing → uploading → result`
  flow, retake-keeps-timer, and expiry-during-review.
- **GPS service**, **wakefulness FSM**, and **shift start/end orchestration** end-to-end.

**Fix:** add widget / unit regression tests for these. The retake timer logic and the
`_finish()` idempotency are pure logic and cheap to pin down.

---

## 🟡 P3 — UX & polish

- **Photo upload has no real progress feedback** — only a static "Submitting…". On a slow
  link the guard can't tell if it's working. Use Dio `onSendProgress` → a progress bar.
- **FLAGGED auto-pops the same as VALIDATED** — the guard barely registers that their photo
  was flagged. Consider holding FLAGGED on screen until acknowledged.
- **Further photo size reduction** — resolution was dropped to `.medium` (2026-06-24); the
  bigger win is compressing before upload (`flutter_image_compress`) if quality ever needs
  to go back up.
- **Build flavors / env safety** — `baseUrl` in
  [`api_config.dart:6`](../lib/config/api_config.dart#L6) defaults to the **real production
  backend**. One forgotten `--dart-define` and a debug build hits prod (or a demo hits a
  dead host). Add dev/prod flavors or a visible environment banner.

---

## ⚪ P4 — Already tracked in the audit (re-surfaced by priority)

Documented in [`SECURITY_AUDIT.md`](SECURITY_AUDIT.md) / [`BACKEND_REQUIREMENTS.md`](BACKEND_REQUIREMENTS.md);
listed here so the priority is visible:

- **C1 — HTTP, not HTTPS** (highest security priority, backend) — everything is cleartext today.
- **C2 — Photo HMAC / nonce is "security theatre"** until the server verifies it with a
  server-issued nonce + per-device key.
- **H1 / H2 — Early-end decision & welfare scoring are client-side** — the device clock and
  the app decide pass/fail; both must move server-side to be trustworthy.

---

## Recommended sequencing

1. **Offline queue (P1.1)** — app-side, shippable now without backend, directly protects the
   core safety / evidence function.
2. **Error reporting (P1.2)** — app-side, gives field visibility.
3. **Regression tests (P2)** — lock in the recent permission + photo work.
4. **P4 items** — gated on backend, already on the books.

P1.1 is the highest-impact change; P2 is the lowest-risk.
