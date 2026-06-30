# IronLock — Guard Monitor Mobile App

### Client Overview & Progress Presentation

**Prepared:** 2026-06-25
**Product:** IronLock Guard Monitor — lone-worker safety & accountability app for security guards

---

## 1. Executive summary

IronLock Guard Monitor is a mobile app that turns a security guard's phone into a
**live safety and accountability device**. It continuously proves three things to the
control room — that the guard is **present**, **alert**, and **in the right place** —
in real time, and in a way the guard **cannot fake**.

The app works hand-in-hand with the IronLock backend and supervisor dashboard: every
action a guard takes is reflected instantly for the control room, and the system raises
alerts automatically the moment something looks wrong.

**Where we are:** the app is **feature-complete and fully working on Android**, with iOS
at feature parity apart from one final Apple-side configuration step for notifications.

---

## 2. The problem it solves

Security guards frequently work **alone, at night, on remote or large sites**. That
creates three persistent risks for any security company:

| Risk | Without IronLock | With IronLock |
|---|---|---|
| **Lone-worker safety** | No way to know if a guard is OK between calls | Continuous welfare checks + live location |
| **Accountability / "ghosting"** | Hard to prove a guard was actually on site and awake | Live photos, code challenges, GPS — all server-verified |
| **Incident evidence** | Disputes come down to one person's word | Time-stamped, tamper-proof, location-bound records |

The result is a **safer guard**, a **provable service** to the end customer, and a
**clear audit trail** when incidents or disputes arise.

---

## 3. Who benefits

- **The guard** — a simple app that keeps them safe, reminds them of tasks, and protects
  them from false "you weren't there" accusations.
- **The control room / supervisor** — a live, single-screen view of every guard's
  location, status, and check results, with automatic alerts so they only act on
  exceptions.
- **The security company / management** — demonstrable proof of service to clients,
  reduced liability, and data to back SLAs and billing.
- **The end client (site owner)** — confidence their site is genuinely being patrolled by
  an alert, present guard.

---

## 4. A typical shift, end to end

1. **Check in** — the guard arrives and signs in. The app only allows sign-in around the
   scheduled start, so check-ins are genuine.
2. **Start shift** — one tap. GPS tracking begins; the control room sees the guard go
   "live" on the map inside their geofence.
3. **On patrol** — location updates flow continuously, even with the phone in a pocket and
   screen locked. If the guard leaves their zone or their phone goes dark, the dashboard
   flags it automatically.
4. **Welfare check** — at a random moment the guard's phone challenges them with a 4-digit
   code to enter within a countdown. Pass = confirmed alert; miss = the control room is
   notified immediately.
5. **Photo request** — the control room (or the schedule) asks for a live photo. The guard
   taps the notification, the camera opens, they capture and submit within the window —
   proving they're physically on site right now.
6. **End shift** — at the scheduled end the guard taps End. To leave early, they request
   approval from a supervisor first. Forgotten shifts are auto-closed by the server as a
   safety net.

Every step is logged, time-stamped by the server, and visible to the control room as it
happens.

---

## 5. Core capabilities (in detail)

### 🔐 Secure sign-in & shift check-in

- **How it works:** guards sign in with their employee code or email; sign-in is restricted
  to a configurable window around the shift start. A successful sign-in checks the guard
  in but does **not** start the shift — Start is a deliberate second step.
- **Smart guidance:** clear, friendly messages for "too early" (with a countdown), "you've
  missed the window" (contact supervisor), or "no upcoming shift".
- **Why it matters:** prevents early/ghost check-ins and gives an accurate attendance
  record. Sessions and keys are stored securely on the device.

### 🕐 Shift management

- **One-tap Start / End**, with all timing decided by the server — no reliance on the
  device clock.
- **Leave-early requests** are routed to a supervisor for approval; a guard cannot end a
  shift early on their own.
- **Never-left-open:** an end-of-shift reminder fires even if the app is closed, an on-screen
  banner shows when a shift runs over, and the server auto-closes abandoned shifts.
- **Why it matters:** accurate worked-hours data and no "open shift" gaps in the record.

### 📍 Live GPS & geofencing

- **Background tracking:** location keeps reporting with the screen locked or the app
  backgrounded.
- **Server-side geofence:** the control room sees instantly whether the guard is inside or
  outside their assigned zone; zone-exit alerts are raised automatically with a grace
  period.
- **Health signals:** reports real battery level, and the dashboard shows "comms
  interrupted" if updates stop arriving.
- **Why it matters:** real-time proof of presence on the correct site, and early warning if
  a guard goes off-zone or off-grid.

### 🛌 Welfare (wakefulness) checks

- **How it works:** at randomised intervals the guard must transcribe a **4-digit code**
  within a countdown. The countdown is anchored to the server's clock, so it can't be
  paused or gamed.
- **Server-verified:** the server decides pass/fail — a correct-looking answer is still
  reconciled against the authoritative record.
- **Works offline:** if there's no signal, the app generates the code locally and reconciles
  when connectivity returns, so a guard in a dead spot is still covered.
- **Why it matters:** continuous proof the guard is awake and responsive — the core of
  lone-worker duty of care.

### 📸 Live photo verification

- **On-demand:** the control room can request a live photo at any moment.
- **Dual camera:** front and back, with a one-tap flip.
- **Genuinely live:** the response countdown is anchored to when the server raised the
  request, so a stored or stale photo won't pass.
- **Tamper-proof:** every photo is **cryptographically signed** and bound to its capture
  time and location, then independently approved or rejected by a supervisor.
- **Why it matters:** the strongest possible proof a specific guard is physically on site
  right now — strong, tamper-resistant evidence if ever needed.

### 🔔 Instant notifications

- **Locked-screen delivery:** welfare checks and photo requests reach the guard even when
  the phone is locked; they tap the notification and go straight to the task.
- **Never missed:** if a notification ever fails to arrive, the app falls back to polling so
  the task still surfaces.
- **Why it matters:** time-critical checks reach the guard reliably, in seconds.

---

## 6. Integrity & anti-fraud (why results can't be faked)

This is what separates IronLock from a simple "check-in" app:

- **Server-trusted, not device-trusted** — timers, deadlines, pass/fail verdicts and
  timestamps are all decided by the server. Changing the phone's clock or tampering with
  the app changes nothing.
- **Live, not stored** — photo and code challenges are time-boxed to a server-issued window,
  so old or pre-prepared responses are rejected.
- **Signed & location-bound** — photos carry a cryptographic signature and GPS coordinates;
  they can't be swapped, edited, or replayed without detection.
- **Receipt confirmation** — the system can tell the difference between "the guard ignored a
  check" and "the message never reached the phone", avoiding false alarms.
- **Resilient** — if a notification drops or signal is lost, polling and offline modes keep
  the safety checks happening.

---

## 7. Compliance & audit trail

- Captures and surfaces each guard's **SIA licence details** (number, type, expiry) as part
  of their profile.
- Produces a **complete, time-stamped record** of every shift: check-in, location history
  summary, welfare results, photos, and shift end — supporting **lone-worker duty-of-care**
  obligations and client SLAs.
- Tamper-proof evidence supports **dispute resolution** and **incident investigation**.
- A **privacy notice** is presented to the guard, and permissions are requested transparently
  and in context (camera, location, notifications).

---

## 8. Real-world scenarios

**Scenario A — A guard stops responding.**
The guard misses a welfare check and their location stops updating. IronLock flags both to
the control room within seconds, distinguishing "off-grid phone" from "left the zone", so a
supervisor can escalate immediately — potentially a life-safety intervention.

**Scenario B — The client disputes coverage.**
A site owner claims no guard was present overnight. The company pulls the shift record:
continuous GPS inside the geofence, passed welfare checks, and time-stamped live photos —
objective, tamper-proof proof of service.

**Scenario C — A guard tries to "phone it in".**
Someone attempts to cover a shift remotely. The live photo request demands a fresh,
location-bound image within a server-timed window, and GPS must sit inside the geofence —
there's no way to satisfy both from off site.

**Scenario D — A genuine early departure.**
A guard falls ill and needs to leave. They submit an early-end request with a reason; a
supervisor approves it from the dashboard; the shift closes cleanly with the reason on
record — no ambiguity, no abandoned shift.

---

## 9. The control-room companion

The mobile app is one half of the system; the **supervisor dashboard** is the other. The
two are designed together so that everything the guard does appears for the control room in
real time:

- **Live map** with each guard's latest position and zone status (inside / outside /
  comms-interrupted).
- **Exception-based alerting** — supervisors are notified of missed checks, zone exits, and
  failed verifications, so they manage by exception rather than watching screens.
- **On-demand actions** — supervisors can trigger a welfare check or request a live photo
  for any active guard.
- **Review & approve** — supervisors approve or reject submitted photos and decide on
  early-end requests.

> The guard never has to "report in" manually — the app does it continuously, and the
> dashboard turns that stream into a clear operational picture.

---

## 10. Reporting & data insights

Because every action is captured and server-time-stamped, IronLock turns day-to-day
operations into usable data:

- **Per-shift records** — attendance, on-site time, welfare pass rate, photos submitted.
- **Exception reports** — missed checks, zone exits, late check-ins, early ends with reasons.
- **Proof-of-service packs** — exportable evidence to share with end clients or for
  investigations.
- **Workforce insight** — patterns across guards and sites to inform scheduling and training.

*(Reporting surfaces are delivered through the IronLock backend/dashboard; the mobile app is
the trusted data source that feeds them.)*

---

## 11. Business case (value to the company)

- **Reduced liability** — demonstrable duty-of-care for lone workers and an evidence trail
  that protects the company in disputes and investigations.
- **Stronger client retention & sales** — provable, auditable service quality becomes a
  competitive differentiator and supports premium SLAs.
- **Operational efficiency** — exception-based monitoring lets one supervisor oversee many
  guards, reducing manual check-in calls and paperwork.
- **Accurate billing & payroll** — server-stamped start/end and worked-hours data reduce
  disputes over hours.
- **Deterrence** — guards know the checks are real and unfakeable, which itself drives better
  on-site behaviour.

---

## 12. Technical foundation (in plain terms)

- **Cross-platform** — a single app for both **Android** and **iPhone**, built on Flutter
  for a consistent experience and efficient maintenance.
- **Real-time backend integration** — secure, token-based connection to the IronLock server
  and dashboard.
- **Push notifications** via industry-standard infrastructure (Firebase / Apple), with a
  polling safety net.
- **Built to be reliable in the field** — background operation, offline tolerance, automatic
  retries, and secure on-device storage.
- **Quality-assured** — an automated test suite and continuous static analysis guard against
  regressions as the app evolves.

---

## 13. Deployment & onboarding

- **Distribution** — installable via the app stores (or enterprise/managed distribution for
  a controlled rollout).
- **Guard onboarding** — guards sign in with their existing employee credentials; the app
  walks them through the necessary permissions (location, camera, notifications) with a
  clear privacy notice.
- **Minimal training** — the day-to-day flow is essentially Sign in → Start → respond to
  prompts → End; most guards are productive within minutes.
- **Low IT overhead** — no special hardware; runs on the guards' standard smartphones.

---

## 14. Current status

| Capability | Android | iOS |
|---|---|---|
| Secure sign-in & shift check-in | ✅ Live | ✅ Live |
| Shift start/end + early-end approval | ✅ Live | ✅ Live |
| Live GPS & geofencing | ✅ Live | ✅ Live |
| Welfare (wakefulness) checks | ✅ Live | ✅ Live |
| Live photo verification (dual camera) | ✅ Live | ✅ Live |
| Push notifications (locked-screen delivery) | ✅ Live | 🟡 Final setup pending* |

\* *iOS push delivery requires one Apple-side configuration step (an APNs authentication
key) to go fully live; all other iOS features are working. Android is fully operational
end-to-end.*

**Overall:** **feature-complete and working on Android**, with iOS at parity apart from the
final push-notification configuration step.

---

## 15. Roadmap (next steps)

- **iOS push go-live** — complete the Apple notification configuration so locked-screen
  delivery works on iPhone, bringing it to full parity with Android.
- **Photo review feedback** — show the guard the supervisor's approve/reject decision (and
  any note) on a photo they submitted, closing the verification loop in-app.
- **On-device acceptance testing** — final field validation across a range of devices and
  network conditions.
- **Future phases** (indicative) — richer in-app activity history, deeper offline support,
  and expanded reporting.

---

## 16. Indicative delivery timeline

*(Relative phases — exact dates to be confirmed with the client.)*

| Phase | Focus | Status |
|---|---|---|
| **Phase 1** | Core app, sign-in, shift lifecycle | ✅ Complete |
| **Phase 2** | Live GPS & geofencing | ✅ Complete |
| **Phase 3** | Welfare checks (online + offline) | ✅ Complete |
| **Phase 4** | Live photo verification | ✅ Complete |
| **Phase 5** | Push notifications (Android) | ✅ Complete |
| **Phase 6** | iOS push go-live + photo-review loop | 🟡 In progress |
| **Phase 7** | Field acceptance testing & launch | ⏳ Upcoming |

---

## 17. Support & maintenance

- **Ongoing maintenance** — the app is built on a maintained, cross-platform framework with
  an automated test suite, so updates and OS changes are handled with low risk.
- **Backend-driven configuration** — many behaviours (check windows, timings, schedules) are
  controlled server-side, so they can be tuned without an app release.
- **Monitoring & diagnostics** — issues can be diagnosed from backend logs and clear in-app
  error states.

---

## 18. Frequently asked questions

**Does it drain the battery?**
Tracking is tuned for efficiency and reports the guard's battery level so the control room
can see it. Steady, lightweight updates rather than constant heavy polling.

**What if the guard loses signal?**
Welfare checks still run offline and reconcile when signal returns; location resumes
automatically. The dashboard clearly distinguishes "off-grid" from "off-zone".

**Can a guard cheat the system?**
No practical way — verdicts and timers are server-controlled, photos are live, signed and
location-bound, and stale or pre-prepared responses are rejected.

**Does it work on both iPhone and Android?**
Yes — one app for both. Android is fully live; iPhone is at parity bar a single
notification-setup step.

**What about privacy?**
Guards see a clear privacy notice, permissions are requested in context, and tracking only
runs during an active shift.

**Does it need special hardware?**
No — it runs on the guards' standard smartphones.

---

## 19. Glossary

| Term | Meaning |
|---|---|
| **Check-in** | Signing in around the shift start; readies the shift but doesn't start it |
| **Welfare / wakefulness check** | A 4-digit code challenge proving the guard is awake and responsive |
| **Geofence** | The virtual boundary of the guard's assigned area |
| **Zone status** | Whether the guard is inside, outside, or out of contact |
| **Live photo verification** | An on-demand, time-boxed photo proving real-time presence |
| **Early-end request** | A guard's request to leave before scheduled end, pending supervisor approval |
| **Auto-close** | The server safely closing a shift the guard forgot to end |

---

## 20. In one line

> **IronLock Guard Monitor turns a guard's phone into a live safety and accountability
> device — proving presence, alertness, and location in real time, with verification the
> guard cannot fake.**

---

*For technical detail, integration status, and engineering notes, see the project's
internal handoff and specification documents.*
