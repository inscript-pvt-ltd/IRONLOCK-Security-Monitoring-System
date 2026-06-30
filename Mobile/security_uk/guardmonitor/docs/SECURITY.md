# Security posture & hardening plan

Honest assessment of the IronLock Guard Monitor app's security, prioritised by **real risk** —
not theatre. Each item says what it protects against and who must do it (app vs backend).

Principle: the device is **not** a trusted environment. Anything shipped in the app is
extractable. So security = (1) don't ship global secrets, (2) protect data **in transit**,
(3) protect per-user secrets **at rest**, (4) raise the reverse-engineering bar. Config files
(`config/*.json`, `--dart-define`) are **not** security — they're env management.

---

## ✅ P0 — RESOLVED (2026-06-26): traffic is now HTTPS

### 1. ~~Traffic is sent over plain HTTP (cleartext)~~ — FIXED
The backend moved to **HTTPS** (`https://dashboard.ironlock.co.uk/api/mobile/v1`), so JWT tokens,
the `hmac_secret`, photos and GPS now travel **encrypted (TLS)** — the previous HIGH-risk MITM
exposure is closed.

Cleartext is now **denied** on both platforms (production HTTPS-only):
- iOS: the `NSAppTransportSecurity` cPanel exception was removed; only `NSAllowsLocalNetworking`
  remains (local mock dev only — does not relax ATS for any remote host).
- Android: `network_security_config.xml` is now `base-config cleartextTrafficPermitted="false"`,
  with cleartext allowed only for `localhost`/`127.0.0.1`/`10.0.2.2` (the dev mock).

Closes audit **C1/H4**. The next item (cert pinning) builds on this.

---

## 🟠 P1 — Strong hardening (now unblocked — HTTPS is live)

### 2. Certificate pinning
Once on HTTPS, pin the backend's certificate / public key in the Dio client so a **rogue or
mis-issued CA** can't MITM even with a "valid" cert. App-side, but needs the cert/public-key
hash from the backend. Add a `CertificatePinningInterceptor` (or Dio `badCertificateCallback`
comparing the SPKI hash). **Now unblocked (HTTPS live) — needs the cert/SPKI hash from the backend.**

### 3. Release build obfuscation
Raises the cost of decompiling the shipped binary (symbol names → meaningless). Pure app-side,
easy:
```
flutter build apk     --obfuscate --split-debug-info=build/symbols --dart-define-from-file=config/prod.json
flutter build ipa     --obfuscate --split-debug-info=build/symbols --dart-define-from-file=config/prod.json
```
Keep the `build/symbols` output (needed to de-obfuscate crash reports). `.gitignore` already
ignores `app.*.map.json` / `app.*.symbols`.

---

## 🟢 P2 — Already done / good hygiene (keep it that way)

| Control | Status |
|---|---|
| Per-user secrets (JWT, `hmac_secret`) in **secure storage** (Keychain / Keystore-backed) | ✅ done |
| No global secrets / 3rd-party private keys hardcoded in the app | ✅ verified |
| `hmac_secret` is **per-login**, server-issued, rotatable — extraction only exposes that one guard | ✅ by design |
| Screen-capture protection (Android `FLAG_SECURE` + iOS capture cover) | ✅ done |
| Single session per guard (new login invalidates old device) | ✅ backend-enforced |
| JWT auto-refresh + forced sign-out on dead refresh token | ✅ done |
| Photo uploads HMAC-signed (integrity / anti-tamper) | ✅ done |
| SSO links single-use, 1-hour expiry, never auto-retried | ✅ done |

### Logging hygiene (action)
`debugPrint` is stripped from release builds, so logs don't ship — but:
- The temp `[poll-debug]` line prints the photo **`nonce_value`**, and `[cam]` prints lens info.
  These are **debug-only diagnostics** — **remove them** once the photo/camera fixes are
  confirmed on-device (already flagged in HANDOFF).
- Rule: never log tokens, `hmac_secret`, refresh tokens, or full auth responses. Gate any
  verbose diagnostic on `!ApiConfig.isProduction`.

---

## ⚪ Optional / lower value (decide later)

- **Jailbreak/root detection** — can warn or block on compromised devices. Moderate value for a
  lone-worker safety app; also adds friction and is bypassable. Consider, don't rush.
- **Firebase App Check** — attests requests come from a genuine app instance; mostly a
  backend/Firebase-console config. Worth it once push is live.
- **Biometric app-lock** — re-auth with Face/Touch ID on resume. UX call.

---

## Summary — what actually moves the needle

1. **HTTPS on the backend** (+ remove the cleartext exceptions) — by far the biggest win. **Backend.**
2. **Certificate pinning** — after HTTPS. **App.**
3. **Obfuscated release builds** — easy app-side win, do at release time. **App.**
4. **Strip debug diagnostics** before shipping. **App.**

Everything else is already in good shape. A `.env`/config file does **not** appear on this list —
it's convenience, not security.
