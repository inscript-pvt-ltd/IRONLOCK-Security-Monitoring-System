# Smart Security Guard Monitoring System

**Software Project Proposal**
Version 2.0 · June 1, 2026

**Prepared by:** Software Development Team
**Classification:** Academic / Business Proposal
**Status:** Draft — Open for Review

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Problem Statement](#2-problem-statement)
3. [Proposed Solution Overview](#3-proposed-solution-overview)
4. [System Features — Detailed Specification](#4-system-features--detailed-specification)
   - [4.1 Real-Time Location Tracking and Alerts](#41-real-time-location-tracking-and-alerts)
   - [4.2 Live Photo Verification with Anti-Manipulation Controls](#42-live-photo-verification-with-anti-manipulation-controls)
   - [4.3 Wakefulness Verification — Code Challenge Protocol](#43-wakefulness-verification--code-challenge-protocol)
5. [Offline Capabilities](#5-offline-capabilities)
6. [System Architecture Overview](#6-system-architecture-overview)
7. [Technology Stack](#7-technology-stack)
8. [Data Flow Description](#8-data-flow-description)
9. [Security Considerations](#9-security-considerations)
10. [UK Regulatory Compliance](#10-uk-regulatory-compliance)
11. [Scalability Considerations](#11-scalability-considerations)
12. [Implementation Roadmap](#12-implementation-roadmap)
13. [Business Value & Benefits](#13-business-value--benefits)
14. [Limitations](#14-limitations)
15. [Cost Plan](#15-cost-plan)
16. [Conclusion](#16-conclusion)

---

## 1. Executive Summary

The security services industry is built on trust — the assurance that a contracted guard is present at a facility, is alert and responsive throughout their shift, and can summon help when an incident occurs. Yet the mechanisms currently used to verify these assurances are largely manual, easily circumvented, and unsuited to the operational scale and accountability demands of modern security management.

This proposal presents the **Smart Security Guard Monitoring System**, a single unified digital platform designed to close the accountability gap in security guard operations. The system serves two roles — the **Security Firm Administrator** and the **Security Guard** — and is built around three core operational goals:

1. **Continuous location tracking with real-time alerts** — Verify that every guard is within their assigned zone throughout their shift, and alert supervisors immediately when they are not
2. **Live photo verification with anti-manipulation controls** — Allow administrators to request photographic proof of presence, with cryptographic controls that prevent guards from submitting pre-taken or timestamp-manipulated images
3. **Active wakefulness verification** — Confirm that a guard is alert and responsive at randomised intervals using a timed code challenge, without reliance on voice calls

The system is designed to operate in both online and offline environments. Location data, wakefulness results, and photo evidence are queued locally on the device during connectivity gaps and synchronised to the server upon reconnection, ensuring no operational data is lost during periods of poor signal.

The platform is developed specifically for deployment in the United Kingdom and is designed in full compliance with UK GDPR, the Data Protection Act 2018, the Working Time Regulations 1998, the BS 8484:2016 lone worker services standard, and the requirements of the Security Industry Authority.

---

## 2. Problem Statement

Security guard falsification and negligence represent a persistent, under-reported, and financially significant risk in the physical security industry. Security firms face reputational and legal exposure when guards fail to perform their duties, and the facilities they are contracted to protect bear the direct safety risk. Despite these stakes, the majority of patrol verification systems in use today remain fundamentally inadequate.

### 2.1 The Core Challenge

The standard approach to guard verification typically relies on one or more of the following:

- **Paper-based patrol logs** — completed retrospectively at the end of a shift, with no mechanism for independent verification
- **Basic barcode or NFC wand systems** — easily defeated by leaving the wand device at a checkpoint without the guard being present
- **Periodic supervisor spot-checks** — infrequent, resource-intensive, and easily anticipated by guards

None of these methods provide real-time visibility. None can confirm that the registered guard is the person using the device. None offer a scalable, auditable record suitable for contractual compliance or legal proceedings.

### 2.2 Stakeholder Pain Points

**Security Firms** are unable to verify guard activity in real time, creating blind spots in operations management. When incidents occur, the absence of reliable records exposes firms to liability. Guard negligence or absenteeism is often discovered days after it occurs — too late to prevent harm or document the failure.

**Security Guards** face a lack of effective safety infrastructure. A guard who falls ill, encounters a threatening situation, or becomes incapacitated during a patrol has limited means of alerting supervisors quickly. The same systems that fail to verify genuine attendance also fail to protect the guard when it matters most.

### 2.3 Identified Attack Vectors and Failure Modes

| Failure Mode              | Description                                                    | This System's Response                                                                                   |
| ------------------------- | -------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| Phantom attendance        | Guard marks themselves present without attending the location  | Continuous GPS + randomised live photo verification                                                      |
| Device handoff            | One guard uses another's credentials or device                 | Session-bound to single device; one active session at a time                                             |
| Alert fatigue / sleeping  | Guard is present but inactive or asleep during shift           | Code challenge wakefulness protocol — 10-second response                                                 |
| Backdated records         | Patrol logs manually adjusted after the fact                   | Append-only records; server-assigned timestamps throughout                                               |
| GPS manipulation          | Mobile app location spoofed to fake checkpoint visits          | Cross-validated with photo GPS EXIF and NTP timestamp                                                    |
| Device clock manipulation | Guard sets device clock to forge photo EXIF timestamps         | Irrelevant — liveness proved by server-side nonce issuance window; NTP cross-check adds secondary signal |
| Photo replay attack       | Guard submits a previously captured photo as a live submission | Single-use cryptographic nonce bound to each photo request                                               |

The system described in this proposal addresses each of these failure modes directly.

---

## 3. Proposed Solution Overview

The Smart Security Guard Monitoring System is a two-tier platform consisting of a **mobile application** for security guards and a **web-based admin dashboard** for security firm supervisors, both backed by a central **API and processing engine** that acts as the authoritative source of truth.

### 3.1 Roles

**Security Firm Administrator** — The security firm is the primary operator of the platform. Administrators create and manage guard accounts, onboard sites and geofences, assign guards to shifts, monitor real-time guard activity, and receive alerts when compliance or safety issues arise. The dashboard provides a complete operational view of all active guards across all managed sites.

**Security Guard** — The guard is the system's mobile user. The app handles shift start and end, continuous location reporting, photo capture on request, and wakefulness code responses. It is designed to be fast, simple, and usable in field conditions with minimal training required.

### 3.2 Three Core Goals

The entire system is designed to achieve three specific operational outcomes:

| Goal                       | What it does                                                                          | How manipulation is addressed                                                                 |
| -------------------------- | ------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| Location tracking + alerts | Continuous GPS reporting, geofence monitoring, real-time zone-exit alerts             | Server-assigned timestamps; GPS cross-validated with photo metadata                           |
| Photo verification         | Admin-triggered or randomly scheduled live photo capture                              | Nonce binding + NTP timestamp cross-check prevents pre-taken or clock-manipulated submissions |
| Wakefulness verification   | Randomised code challenge; guard must type the correct 4-digit code within 10 seconds | No calls needed; TOTP-style local generation provides offline fallback                        |

---

## 4. System Features — Detailed Specification

### 4.1 Real-Time Location Tracking and Alerts

#### 4.1.1 Continuous GPS Reporting

The mobile application transmits the guard's GPS coordinates to the backend at regular intervals (default: every 15 seconds) throughout the active shift. Each transmission includes latitude, longitude, GPS accuracy radius, device battery level, and a server-receipt timestamp assigned by the backend at the moment of receipt — never by the client device.

The backend performs a **point-in-polygon test** against the guard's assigned geofence using PostGIS spatial queries. The result — `INSIDE_ZONE` or `OUTSIDE_ZONE` — is stored as an append-only event record and pushed to the admin dashboard in real time via WebSocket.

Geofences support **custom polygon boundaries**, not circular radii, enabling accurate mapping of irregular site shapes including building perimeters, car parks, patrol corridors, and multi-floor facilities.

#### 4.1.2 Alert Generation

If a guard's status transitions to `OUTSIDE_ZONE` and remains outside beyond the configured grace period (default: 5 minutes), the backend raises a `ZONE_EXIT` alert:

- The alert appears on the admin dashboard with colour-coded severity — amber (within grace period), red (beyond grace period or unresponsive)
- A CRITICAL push notification is dispatched to the on-duty supervisor's app

#### 4.1.3 Live Map View

The admin dashboard renders each active guard's position as a moving icon on an interactive map, with a trailing breadcrumb path showing their route over the previous hour. Supervisors can verify active movement, identify route deviations from expected patrol patterns, and pinpoint a guard's exact location when responding to an alert.

#### 4.1.4 Shift Scheduling and Guard Management

Administrators create guard accounts, configure site locations and geofence boundaries, and assign guards to shifts. Guards authenticate only within their scheduled shift window — login attempts outside this window are rejected and logged. All shift and event records are append-only; they cannot be modified after creation.

---

### 4.2 Live Photo Verification with Anti-Manipulation Controls

#### 4.2.1 Trigger Mechanisms

A photo verification request is raised in one of two ways:

1. **Manual request** — An administrator clicks "Request Photo" on the dashboard for a specific guard
2. **Automated random request** — The system scheduler fires a photo request at a randomised interval within the shift (configurable range, e.g., once every 1–3 hours, randomised per occurrence to prevent predictability)

In both cases the guard receives a push notification and must open the app to capture a live photo within **90 seconds**.

#### 4.2.2 Gallery Bypass Enforcement

The application bypasses the device photo gallery entirely. The camera is launched directly within the app and the captured image is transmitted immediately. There is no mechanism for the guard to select a previously taken image.

- **Android:** `ACTION_IMAGE_CAPTURE` intent with a direct-to-upload URI
- **iOS:** `UIImagePickerController` configured with `.camera` as the exclusive source type

Images must be transmitted within **10 seconds** of capture. Submissions arriving after this window are flagged as `DELAYED_UPLOAD`.

#### 4.2.3 Anti-Manipulation Controls — Device Clock and EXIF

EXIF metadata embedded in a photo is written by the device operating system using the device's system clock. A guard who sets their device clock to a manipulated time produces a photo with a forged EXIF timestamp — any verification that relies on EXIF alone is therefore trivially defeated. The system does not rely on EXIF.

**Primary control — Nonce window proof of liveness**

The backend records two server-side timestamps that the guard's device cannot influence in any way:

- `nonce_issued_at` — the exact moment the server created and sent the nonce for this photo request
- `server_received_at` — the exact moment the server received and logged the upload

Because the nonce was single-use and short-lived (60-second expiry), and because the server issued it only in response to this specific photo request, the photo **must** have been captured between those two server timestamps. There is no scenario in which a previously taken photo can produce a valid, unexpired nonce — the nonce did not exist yet when any earlier photo was taken.

This means the server can prove liveness (the photo was taken during the request window) using nothing but its own clock and its own records. The guard's device clock is entirely irrelevant to this proof.

**Secondary control — NTP cross-check**

At the moment the shutter fires, the app queries a trusted NTP server to get network time independently of the device clock. This NTP timestamp is embedded in the signed upload payload alongside the EXIF timestamp. The backend compares them: a deviation greater than **30 seconds** is flagged as `CLOCK_MANIPULATION_SUSPECTED` and triggers supervisor review.

This control adds a secondary signal for anomaly detection. It is not required for liveness proof — the nonce window already provides that. If the NTP query fails (guard offline), the upload proceeds and is evaluated against the nonce window alone; the `NTP_UNAVAILABLE` flag is recorded in the audit log for that submission.

**What this means in practice:** A guard cannot submit a pre-taken photo regardless of what their device clock says. A guard cannot forge the timing of a submission. Any attempt to manipulate the device clock is surfaced as a flag in the audit trail, but even without that flag the nonce window constraint cannot be circumvented.

#### 4.2.4 Photo Storage and Evidence Chain

Each submitted photo is stored in AWS S3 (immutable, server-side encrypted) alongside:

- Guard ID and shift reference
- GPS coordinates at capture time
- Server-receipt timestamp
- NTP timestamp at capture
- Request nonce reference
- SHA-256 hash of the image (deduplication and tamper detection)

If the guard fails to respond within 90 seconds, the system logs a `PHOTO_TIMEOUT` event and raises an alert to the supervisor.

---

### 4.3 Wakefulness Verification — Code Challenge Protocol

#### 4.3.1 How it Works

At randomised intervals throughout the shift (default: every 30–45 minutes, randomised per occurrence to prevent predictability), the system initiates a wakefulness challenge:

1. The backend generates a random **4-digit numeric code** and delivers it to the guard's device via push notification
2. The app displays the code on-screen with a **10-second countdown timer**
3. The guard must type the exact code and confirm before the timer expires

A correct, on-time submission is logged as `WAKEFULNESS_CONFIRMED` with a server timestamp and GPS coordinates.

**No voice calls are used.** Typing a specific four-digit code on a strict countdown requires genuine alertness — answering a ringing phone does not. This approach is also faster, lower-cost, and more reliable than automated call protocols.

#### 4.3.2 Non-Response Escalation

If the guard fails to submit the correct code within 10 seconds:

1. `WAKEFULNESS_FAILED` is logged immediately with server timestamp
2. A `GUARD_UNRESPONSIVE` alert is raised on the admin dashboard
3. A CRITICAL push notification is dispatched to the on-duty supervisor's app
4. The supervisor is directed to conduct a physical welfare check at the guard's last confirmed location

There is no automated retry or grace extension — a failed challenge is an immediate supervisor alert by design.

#### 4.3.3 Offline Fallback — TOTP-Based Local Challenge

When the guard's device has no data connectivity, push notifications cannot be delivered. The system handles this using a **TOTP-style pre-shared seed** stored in the device's secure keychain at shift start:

- The app independently generates the expected challenge code for the current time window using the same algorithm as the server
- A **local notification** fires at the scheduled check interval
- The guard responds identically to the online flow; the 10-second enforcement is maintained by the local app timer
- The result is stored in the encrypted local queue and verified server-side on reconnection — the server independently generates the expected code for that time window and validates the uploaded response

The guard experiences no change in flow during offline periods.

---

## 5. Offline Capabilities

Network connectivity in physical security environments is not guaranteed. Guards operate in warehouses, basements, multi-storey car parks, rural estates, and construction sites where mobile data may be intermittent or absent. The system is designed so that **all three core goals continue to function during connectivity gaps** and recover without data loss on reconnection.

The admin dashboard displays a `COMMS_INTERRUPTED` indicator for any guard whose last received ping exceeds the expected interval, alerting supervisors to a connectivity gap without triggering a zone-exit alert.

---

### 5.1 Goal 1 — Location Tracking Offline

The device GPS subsystem operates entirely independently of mobile data. Location coordinates are collected at the normal 15-second interval and written to an **encrypted local SQLite queue** on the device.

On reconnection, the full queued history is uploaded in chronological order. The backend assigns **server-receipt timestamps at the moment of upload** — not at the time the event was originally recorded — and renders the offline period as a historical breadcrumb trail on the dashboard, with a distinct visual marker over the offline window.

Zone-exit alerts are not raised retroactively for the offline period. Supervisors see the `COMMS_INTERRUPTED` gap and can review the uploaded trail once reconnection occurs.

---

### 5.2 Goal 2 — Photo Verification Offline

Photo verification offline involves two separate problems: _delivering the request to the guard_ and _ensuring the captured photo is still tamper-proof_ without a live server connection.

**Delivering the request**

Push notifications cannot reach a device with no data connection. Two mechanisms handle this:

- **Pre-scheduled random checks** — the scheduler interval is provisioned to the app at shift start, so the app can fire a local notification at the expected time even without server contact
- **Admin manual requests** — these cannot be delivered during an offline period; they are queued on the server and delivered as a push notification the moment connectivity is restored

**Maintaining liveness proof offline — offline nonce window**

Online, liveness is proved by the server's `nonce_issued_at` and `server_received_at` timestamps. Offline, the server cannot record `server_received_at` at capture time. The offline approach compensates with two controls:

1. **Pre-fetched nonce pool** — At shift start (and periodically while online), the app pre-fetches a pool of 10–20 single-use nonces, each with a **15-minute expiry**, stored in the device's secure keychain. Each nonce carries the server's issuance timestamp embedded in it. When a photo is taken offline, the app draws the next unexpired nonce from the pool and embeds it in the upload payload.

2. **Device-signed capture timestamp + NTP cross-check on reconnection** — At shutter-fire, the app records a local timestamp and (if any NTP query has succeeded recently, within the last 5 minutes) includes the most recent confirmed NTP reference alongside the elapsed device time since that reference. On upload, the server reconstructs the NTP-anchored capture time from this data and cross-validates it against the nonce's issuance timestamp. A photo taken more than 15 minutes after the nonce was issued is rejected as `NONCE_EXPIRED`. A reconstructed capture time that is implausible (e.g., before the nonce was issued, or far outside the shift window) is flagged as `TIMELINE_ANOMALY`.

**What this means in practice:** A guard cannot use a photo taken before the shift began — no valid nonce existed yet. A guard cannot use a photo taken in a prior connectivity window — that nonce is already invalidated on the server. The 15-minute nonce expiry caps the window in which an offline photo could theoretically be staged.

**Nonce pool exhaustion**

If the nonce pool is exhausted before connectivity is restored, the photo request is logged as `NONCE_POOL_EXHAUSTED` and remains pending. The supervisor sees this on the dashboard as an unresolved verification request.

---

### 5.3 Goal 3 — Wakefulness Verification Offline

Push notifications cannot be delivered without connectivity, but the wakefulness challenge must still fire at its scheduled interval. The system handles this with a **TOTP-style pre-shared seed**:

- At shift start, the backend provisions a TOTP seed to the app's secure keychain alongside the wakefulness check schedule for the shift
- At each scheduled interval, the app fires a **local notification** entirely on-device
- The app generates the expected 4-digit code for that time window using the same TOTP algorithm as the server
- The guard responds identically to the online flow — the 10-second countdown and code entry are enforced by the local app timer
- The result is written to the encrypted local queue with the device timestamp and the TOTP window reference

On reconnection, the server independently generates the expected code for each queued time window and validates the uploaded response. A correct code confirms the guard was alert and responsive at that moment. An incorrect or missing response triggers `WAKEFULNESS_FAILED` retroactively and raises a `GUARD_UNRESPONSIVE` alert.

The guard experiences no change in flow during offline periods.

---

## 6. System Architecture Overview

The system is structured as a **three-tier architecture** consisting of a mobile client layer, a backend application layer, and a data persistence layer. All tiers communicate exclusively over HTTPS, with WebSocket connections used for real-time data push to the admin dashboard.

### 6.1 Mobile Application Layer

The mobile application runs on iOS and Android devices used by security guards in the field. It is built using **Flutter**, enabling a single codebase to target both platforms while retaining full access to native device APIs. It is responsible for:

- Authenticating the guard and managing their session
- Collecting and transmitting GPS location data during active shifts (15-second intervals)
- Presenting wakefulness code challenges and capturing guard responses within 10 seconds
- Launching the camera for live photo capture (no gallery access) and transmitting images directly
- Querying NTP at shutter-fire and embedding the verified timestamp in the photo payload
- Managing the local encrypted event queue (SQLite) during offline periods
- Maintaining the pre-fetched nonce pool and TOTP seed in the device's secure keychain

### 6.2 Backend Application Layer

The backend is a **Laravel RESTful API** that acts as the authoritative source of truth for all system state. It is responsible for:

- Authenticating all requests from the mobile app and admin dashboard
- Assigning server-side timestamps to all incoming events at receipt
- Validating geofence membership for incoming location coordinates (PostGIS)
- Validating photo submissions (nonce expiry, HMAC signature, NTP cross-check, EXIF delta)
- Evaluating compliance rules and generating alerts
- Serving the admin dashboard with real-time data via WebSocket (Laravel Echo)
- Orchestrating the wakefulness monitoring schedule and code generation
- Interfacing with third-party services (push notifications)

### 6.3 Data Persistence Layer

All persistent data is stored in a **PostgreSQL** relational database. **PostGIS** is used for geofence boundary storage and server-side point-in-polygon validation. **Redis** is used as an in-memory cache for active session tokens, rate limiting counters, and real-time dashboard state.

**AWS S3 (UK region)** is used for photo evidence storage. All uploaded photos are stored as immutable objects with access controlled via signed URLs. Server-side encryption (SSE-S3) is applied to all stored objects.

### 6.4 Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                         SYSTEM OVERVIEW                              │
│                                                                      │
│  ┌───────────────────────┐  HTTPS / Push  ┌──────────────────────┐  │
│  │  Mobile App           │ ─────────────► │  Backend API Server  │  │
│  │  (Guard Device)       │ ◄───────────── │  (Laravel / PHP)     │  │
│  │  Flutter (iOS/Android)│  Notifications │                      │  │
│  │  ─────────────────    │                │  ┌─────────────────┐ │  │
│  │  Local Queue (SQLite) │                │  │ Wakefulness     │ │  │
│  │  Nonce Pool (Keychain)│                │  │ Scheduler       │ │  │
│  │  TOTP Seed (Keychain) │                │  ├─────────────────┤ │  │
│  └───────────────────────┘                │  │ Photo Validator │ │  │
│                                           │  │ (Nonce + NTP)   │ │  │
│  ┌───────────────────────┐  HTTPS / WS    │  └─────────────────┘ │  │
│  │  Admin Dashboard      │ ─────────────► │                      │  │
│  │  (Web Browser)        │ ◄───────────── └──────────┬───────────┘  │
│  └───────────────────────┘  Real-time feed           │              │
│                                                      ▼              │
│                        ┌─────────────────────────────────────────┐  │
│                        │  PostgreSQL + PostGIS  │  Redis Cache   │  │
│                        └─────────────────────────────────────────┘  │
│                                                      │              │
│                        ┌─────────────────────────────▼───────────┐  │
│                        │  External Services                      │  │
│                        │  AWS S3 UK Region (Photo Evidence)      │  │
│                        │  Firebase FCM / APNs (Push)             │  │
│                        └─────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 7. Technology Stack

### 7.1 Mobile Application

The mobile application is developed using **Flutter**, Google's open-source UI toolkit that compiles to native iOS and Android applications from a single codebase. Flutter provides direct access to platform-level device APIs — including GPS, camera, push notifications, and secure credential storage — while significantly reducing development and maintenance overhead compared to maintaining two separate native codebases.

Key capabilities provided by the mobile layer include background location collection for geofence validation and real-time tracking, push notification delivery for wakefulness checks and alerts, direct camera launch for gallery-bypass photo capture, and secure local storage of authentication tokens using each platform's native secure enclave.

### 7.2 Backend

The backend API is built on **Laravel**, the PHP framework widely used for enterprise web application development. Laravel provides a structured MVC architecture, a robust ORM (Eloquent) for database interaction, built-in support for task scheduling, queue-based background job processing, and a comprehensive authentication and authorisation system. Its mature ecosystem of packages covers the full range of backend requirements — from real-time broadcasting and job queues to third-party telephony and notification integrations.

The backend exposes a RESTful API consumed by both the mobile application and the admin dashboard. All timestamps are assigned server-side. Role-based middleware enforces access control across all endpoints.

### 7.3 Database and Storage

The system uses a **relational database** as its primary data store, selected for its ACID compliance and strong support for spatial query extensions — which are required for server-side geofence boundary validation. An in-memory caching layer handles session state, rate limiting counters, and real-time dashboard data. Cloud object storage (AWS S3, UK region) provides durable, immutable storage of photo evidence with server-side encryption.

### 7.4 Admin Dashboard

The admin dashboard is a web application built on top of Laravel's server-side rendering capabilities, optionally extended with a reactive frontend layer for real-time updates. Map rendering for geofence management and live guard tracking uses an open-source mapping library. Real-time data delivery to the dashboard is handled via WebSocket broadcasting, using Laravel's native broadcast system connected to the backend event pipeline.

### 7.5 Infrastructure

The system is deployed on standard cloud hosting infrastructure. The backend and database run on virtual private servers in a managed cloud environment, with a reverse proxy handling TLS termination and load distribution across application instances. Automated deployment pipelines ensure that code changes can be tested, reviewed, and deployed to production in a controlled and repeatable manner. System health and uptime are monitored with alerting configured for service degradation or outages.

---

## 8. Data Flow Description

### 8.1 Shift Lifecycle

The following describes the complete data flow for a guard's shift, from login through to completion:

1. **Authentication and Shift Provisioning** — The guard opens the mobile application and enters their credentials. The backend validates the credentials (bcrypt comparison), checks that the current time falls within the guard's assigned shift window, and issues a signed JWT access token. Simultaneously, the backend provisions a **TOTP seed** for wakefulness offline fallback and delivers an initial **nonce pool** of 10–20 single-use photo nonces to the guard's device secure keychain.

2. **Shift Start** — The guard taps "Begin Shift." The application requests location permissions if not already granted. The backend records a `SHIFT_START` event with a server-assigned timestamp.

3. **Presence Monitoring** — Every 15 seconds, the app reads the device's GPS coordinates and writes them to the local encrypted queue. If online, the queue flushes immediately and the backend performs a point-in-polygon test using PostGIS. The result (`INSIDE_ZONE` or `OUTSIDE_ZONE`) is stored with a server-receipt timestamp and pushed to the admin dashboard via WebSocket. During offline gaps, the queue accumulates events; the dashboard shows `COMMS_INTERRUPTED` for that guard rather than a zone-exit alert.

4. **Alert Generation** — If the guard's status is `OUTSIDE_ZONE` beyond the configured grace period, the backend raises a `ZONE_EXIT` alert — pushed to the dashboard in real time and as a CRITICAL push notification to the on-duty supervisor's app.

5. **Photo Verification** — An admin clicks "Request Photo" or the random scheduler fires. The backend draws one nonce from the guard's issued pool (or sends a fresh one if online), creates a `PHOTO_REQUEST` record, and pushes a notification to the guard. The guard opens the app; the camera launches directly. At shutter-fire, the app queries NTP and embeds the verified timestamp. The signed payload (image + nonce + NTP timestamp + GPS) is transmitted within 10 seconds. The backend validates: nonce validity, HMAC signature, NTP vs. EXIF delta (<30 s), and GPS plausibility. A delta >30 s returns `CLOCK_MANIPULATION_SUSPECTED`. On timeout (>90 s), a `PHOTO_TIMEOUT` alert is raised.

6. **Wakefulness Challenge** — At randomised intervals (~30–45 min), the backend generates a 4-digit code and pushes it to the guard via FCM/APNs. The app displays the code with a 10-second countdown. A correct, on-time response is logged as `WAKEFULNESS_CONFIRMED`. A missed or wrong response immediately logs `WAKEFULNESS_FAILED`, raises a `GUARD_UNRESPONSIVE` alert on the dashboard, and dispatches a CRITICAL push notification to the supervisor's app. **No phone calls are made.** During offline periods, the app uses the provisioned TOTP seed to generate the expected code locally; the guard's response is queued and verified by the server on reconnection.

7. **Shift End** — The guard taps "End Shift." All remaining queued events are flushed. The backend records `SHIFT_END`, calculates a compliance summary (zone time, photo responses, wakefulness results, any alerts), and stores the completed shift record.

8. **Supervisor Review** — The administrator reviews the shift from the dashboard, acknowledges any open alerts, and can export a compliance and audit report for retention or regulatory submission.

---

## 9. Security Considerations

Security of the system itself — both the software platform and the data it handles — is treated with the same rigour applied to the physical security operations it supports.

### 9.1 Authentication and Session Security

All guard and administrator accounts are created by administrators — self-registration is not permitted at any tier. Passwords are stored exclusively as bcrypt hashes with an appropriate work factor; plaintext passwords are never persisted or logged anywhere in the system. JWT access tokens carry a 2-hour expiry and are paired with a rotating refresh token stored in the device's platform-native secure keychain (iOS Keychain / Android Keystore). A guard can hold only one active session at a time; a new login automatically invalidates any prior session.

Login attempts are rate-limited. After five consecutive failed attempts from the same device or IP address, the account is temporarily locked and an alert is dispatched to the administrator. All authentication events — successful and failed — are logged with device fingerprint, IP address, and timestamp for audit purposes.

### 9.2 Data in Transit and at Rest

All communication between the mobile application, the admin dashboard, and the backend API is enforced over **TLS 1.3**. HTTP connections are rejected. WebSocket connections used for real-time dashboard updates are established over WSS (WebSocket Secure).

Sensitive data fields in the database — including credentials, personal identifiers, and GPS history — are stored with encryption at rest using AES-256. AWS S3 buckets are configured with server-side encryption and access restricted exclusively to signed URLs with short expiry times.

### 9.3 API Security

All API endpoints require a valid JWT in the `Authorization` header. Endpoints are scoped by role — a guard's JWT cannot access administrative endpoints, and vice versa. Request rate limiting is applied at both the IP level and the user account level using Redis counters, preventing both brute-force attacks and accidental abuse from malfunctioning clients.

Database queries throughout the application use parameterised statements via an ORM (Object-Relational Mapper). Raw SQL constructed from user-supplied input is not used anywhere in the codebase, eliminating SQL injection as an attack surface.

### 9.4 Data Integrity and Audit Trail

All event records in the system — shift events, presence checks, alerts, wakefulness confirmations, photo submissions — are written as **append-only records**. Once created, they cannot be modified or deleted. This immutability is enforced at the database level through role permissions — the application database user does not hold `UPDATE` or `DELETE` privileges on audit-sensitive tables.

All timestamps are assigned by the backend server at the moment of event receipt, not by the client device. This ensures that manipulation of the device clock — a known circumvention technique — has no effect on the authoritative record.

### 9.5 Privacy Considerations

Location data is collected exclusively during active, administrator-assigned shifts. The application provides no passive tracking capability outside working hours. Guards are clearly informed within the application that their location is being recorded while a shift is active.

Photo evidence is stored only in response to an explicit administrator request or a guard submission associated with a specific shift record. Images are not retained beyond the configured data retention period (default: 12 months, configurable by the security firm).

| Threat                    | Mitigation                                                                                         |
| ------------------------- | -------------------------------------------------------------------------------------------------- |
| Credential theft          | HTTPS only, bcrypt hashing, rate-limited login, account lockout                                    |
| Session hijacking         | JWT short expiry, refresh token rotation, secure keychain storage                                  |
| Device clock manipulation | All timestamps assigned server-side at receipt                                                     |
| API abuse                 | JWT-scoped access, rate limiting via Redis, parameterised queries                                  |
| Privilege escalation      | Role-based access control with two distinct permission levels                                      |
| Data interception         | TLS 1.3 enforced on all endpoints; WSS for WebSocket connections                                   |
| SQL injection             | ORM parameterised queries throughout; no raw user-input SQL                                        |
| Unauthorised data access  | S3 signed URLs, AES-256 at rest, append-only audit tables                                          |
| Device clock manipulation | NTP-verified capture timestamp at shutter-fire; server-receipt timestamps on all events            |
| Photo replay attack       | Single-use HMAC-signed nonce per photo request; 60-second nonce expiry                             |
| EXIF metadata forgery     | EXIF timestamp cross-validated against NTP timestamp; >30 s delta = `CLOCK_MANIPULATION_SUSPECTED` |
| Offline queue tampering   | Local SQLite queue encrypted with AES-256; server re-validates all queued events on upload         |

---

## 10. UK Regulatory Compliance

This system is designed and deployed specifically for operation within the United Kingdom. Compliance with the following statutory frameworks and codes of practice is built into the product design, not retrofitted after the fact.

### 10.1 UK GDPR and Data Protection Act 2018

The system processes personal data — names, shift times, GPS coordinates, and photographs — in the context of employee monitoring. The applicable lawful basis under UK GDPR Article 6 is **legitimate interests** (security firm's duty to ensure site security and guard welfare), with a corresponding Legitimate Interests Assessment documented in the Data Processing Agreement.

Implemented compliance measures:

- **Data minimisation** — Only the data directly required for the three operational goals is collected. No passive tracking outside active shifts. No audio recording. No biometric data.
- **Retention policy** — GPS history, photos, and shift records are retained for **12 months** by default and automatically purged thereafter. Retention periods are configurable per firm but require documented justification.
- **Right of access / erasure** — Guard account data can be exported or deleted on request through the admin dashboard. Audit trail records (which are legally required for compliance purposes) are retained separately per the BS 8484 requirements.
- **ICO registration** — The security firm operating the system is responsible for registering their data processing activities with the Information Commissioner's Office. The Data Processing Agreement between the security firm and the development team establishes the developer as a **Data Processor** under Article 28.
- **Data residency** — All data — including the database, API servers, and S3 photo storage — is hosted in **UK-region cloud infrastructure**. No personal data is routed through or stored in non-UK servers.
- **Privacy notice** — Guards are presented with a clear, plain-English privacy notice at first login, explaining what data is collected, for what purpose, and for how long.

### 10.2 BS 8484:2016 — Lone Worker Services

The system's three core features collectively address the lone worker protection obligations defined under BS 8484:

| BS 8484 Requirement        | System Implementation                                                      |
| -------------------------- | -------------------------------------------------------------------------- |
| Regular welfare checks     | Wakefulness code challenge at randomised intervals                         |
| Escalation on non-response | `GUARD_UNRESPONSIVE` alert → push notification → supervisor physical check |

| Audit trail of all welfare check events | Append-only event log with server-assigned timestamps |
| Documented escalation procedures | Configurable per-site escalation policy stored in shift record |

Phase 4 of the implementation roadmap includes integration with a UK Alarm Receiving Centre (ARC) to bring the system to full BS 8484 Level 3 compliance for clients who require ARC-monitored lone worker protection.

### 10.3 Security Industry Authority — Private Security Industry Act 2001

The SIA regulates all security operatives working in the UK private security industry. To support firms in meeting their SIA licensing compliance obligations:

- The guard account record includes a mandatory **SIA licence number** field
- The system generates an alert **30 days before** a guard's licence expiry date, ensuring administrators can take action before a guard operates without a valid licence
- SIA licence references are included in all exported compliance and audit reports

Security firms are responsible for verifying licence validity at onboarding. The system's licence expiry tracking is a compliance aid, not a replacement for the firm's own verification process.

### 10.4 Working Time Regulations 1998

The Working Time Regulations limit working hours and mandate rest periods for workers in the UK. The admin dashboard includes the following compliance aids for shift scheduling:

- **Maximum shift duration warning** — The system warns at 12 hours and blocks scheduling beyond 16 hours for a single shift
- **Rest period enforcement** — A warning is raised if a guard is scheduled for a shift starting less than 11 hours after the end of their previous shift
- **Weekly hours tracking** — The dashboard tracks total scheduled hours per guard per week and flags where the 48-hour average (over the 17-week reference period) is at risk of being exceeded

These controls are advisory by default; final scheduling responsibility remains with the security firm. An override log records when any automated limit is manually bypassed.

### 10.5 Health and Safety at Work Act 1974 and Management of Health and Safety at Work Regulations 1999

Security firms have a duty of care for lone workers under the Health and Safety at Work Act. The system directly supports discharge of this duty through:

- Continuous location monitoring and zone-exit alerting
- Active wakefulness verification at regular intervals
- Automatic supervisor escalation on non-response to any welfare check

The system generates **Per-Shift Welfare Reports** that document every welfare check performed, the response status, and any escalation actions taken. These reports provide evidence that the security firm has implemented reasonable measures to monitor guard welfare in compliance with the MHSWR 1999 requirement to identify and mitigate risks to lone workers.

### 10.6 Surveillance Camera Code of Practice and Protection of Freedoms Act 2012

Photo verification involves capturing images of individuals (guards) and their environment. The Surveillance Camera Code of Practice requires that surveillance activities have a clear, documented legitimate purpose and that individuals are informed of when and why images are captured.

Implemented compliance measures:

- **Privacy notice at first login** — Guards are informed that photo verification may be requested during shifts, under what circumstances, and how images will be stored and used
- **Purpose limitation** — Photos are used exclusively for shift compliance verification; they are not used for any other purpose, not shared with third parties, and not used for performance management
- **Admin request logging** — Every photo request (manual or scheduled) is logged with the administrator's identity, timestamp, and stated reason (shift compliance). There is a complete audit trail of who requested what and when
- **Retention limit** — Photo evidence is retained for a maximum of 12 months and then automatically deleted

---

The system is architected to scale with the operational growth of the security firm, from a single site with a handful of guards to a multi-site enterprise operation managing hundreds of simultaneous shifts.

**Stateless API design** — The API server holds no session state in memory. All session data is externalised to Redis. This means any number of API server instances can run behind a load balancer, each capable of serving any request without coordination with other instances. Horizontal scaling of the API tier requires no application-level changes.

**Database read scaling** — The primary PostgreSQL instance handles all write operations. One or more read replicas can be added to serve dashboard queries and reporting workloads without competing with write traffic. The 15-second GPS ping cadence generates substantial write volume; read replicas allow the dashboard to query historical data without impacting live event recording.

**Location data partitioning** — The `LocationPings` table, which grows continuously during active shifts, is partitioned by date from initial deployment. This keeps query performance stable as historical data accumulates and allows older partitions to be archived or deleted based on the configured data retention policy without affecting live data.

**Worker process separation** — Background jobs (wakefulness schedulers, compliance checks, report generation) are run in a dedicated worker process, not on the main API server. This ensures that a spike in background processing load does not degrade API response times for active guards.

**Media storage** — AWS S3 (UK region) provides effectively unlimited storage for photo evidence, with no backend performance impact as volume grows.

**Multi-site and multi-firm support** — The data model is designed for multi-tenancy from the outset. Each security firm operates as an isolated tenant with their own guard accounts, client locations, and shift data. Tenant data isolation is enforced at the database query level — no cross-tenant data access is possible through the application layer.

---

## 11. Scalability Considerations

The system is architected to scale with the operational growth of the security firm, from a single site with a handful of guards to a multi-site enterprise operation managing hundreds of simultaneous shifts.

**Stateless API design** — The Laravel API server holds no session state in memory. All session data is externalised to Redis. Any number of API server instances can run behind a load balancer without coordination between instances. Horizontal scaling of the API tier requires no application-level changes.

**Database read scaling** — The primary PostgreSQL instance handles all write operations. One or more read replicas can be added to serve dashboard queries and reporting workloads without competing with write traffic. The 15-second GPS ping cadence generates substantial write volume; read replicas allow the dashboard to query historical data without impacting live event recording.

**Location data partitioning** — The `LocationPings` table is partitioned by date from initial deployment. This keeps query performance stable as historical data accumulates and allows older partitions to be archived based on the configured data retention policy (default: 12 months, aligned with UK regulatory requirements).

**Worker process separation** — Background jobs (wakefulness schedulers, compliance checks, report generation) run in a dedicated Laravel queue worker, not on the main API server. A spike in background processing load does not degrade API response times for active guards.

**Media storage** — AWS S3 (UK region) provides effectively unlimited storage for photo evidence, with no backend performance impact as volume grows.

**Multi-site and multi-firm support** — The data model is designed for multi-tenancy from the outset. Each security firm operates as an isolated tenant with their own guard accounts, client locations, and shift data. Tenant data isolation is enforced at the query level — no cross-tenant data access is possible through the application layer.

---

## 12. Implementation Roadmap

The system is delivered as a single integrated product across four phases.

### Phase 1 — Core Platform (Months 1–2)

- Backend API: authentication, role-based middleware, shift scheduling
- Database schema: users, roles, sites, geofences, shifts, events, alerts
- Guard mobile app: login, shift start/end, geofence presence check (Flutter)
- Admin dashboard: guard management, site management, geofence map editor

### Phase 2 — Full Feature Set (Months 2–4)

- GPS 15-second streaming + live map with breadcrumb trail
- Photo verification: FCM push, gallery-bypass camera, nonce binding, NTP cross-check, EXIF validation
- Wakefulness code challenge: scheduler, FCM delivery, 10-second countdown, `GUARD_UNRESPONSIVE` alert
- TOTP-based offline wakefulness fallback
- Encrypted local event queue + offline sync

### Phase 3 — QA, Security Review, and UK Compliance Audit (Month 5)

- End-to-end QA testing across iOS and Android
- Penetration testing: authentication, photo upload chain, API endpoints
- UK GDPR compliance review: privacy notice text, data minimisation audit, DPA documentation
- BS 8484 welfare check completeness review
- SIA licence field and expiry alerting verification
- Working Time Regulations scheduling controls testing
- Load testing: simulate peak concurrent guard shifts
- Deployment to UK-region production infrastructure

### Phase 4 — ARC Integration and Advanced Compliance (Month 6+)

- Integration with a UK Alarm Receiving Centre (ARC) for BS 8484 Level 3 lone worker compliance
- Multi-firm admin portal for enterprise deployments
- Advanced analytics dashboard: shift compliance trends, guard performance reports
- Automated PDF compliance report generation for regulatory submission

---

## 13. Business Value & Benefits

### 13.1 For Security Firms

The primary value proposition is **operational accountability and risk reduction**:

- **Contractual compliance** — Demonstrate SLA adherence to clients with auditable digital records rather than unreliable paper logs, reducing contract disputes and enabling stronger SLA commitments
- **Incident liability protection** — In the event of a security incident, the system provides a timestamped, immutable record of guard activity usable in legal or insurance proceedings
- **Regulatory compliance posture** — Built-in BS 8484, SIA, Working Time Regulations, and UK GDPR controls reduce the compliance burden on the firm's management team
- **Operational efficiency** — Supervisors are alerted automatically when issues arise rather than conducting frequent manual check-ins; supervisory time is redirected to higher-value activities

### 13.2 For Security Guards

The wakefulness monitoring system provides a structured, automatic safety net. A guard who becomes unwell or is unresponsive during a shift triggers an immediate supervisor alert and physical welfare check. The system's immutable records also protect guards from false accusations — the audit trail confirms what actually happened during a shift.

---

## 14. Limitations

The system is designed with honesty about its operational boundaries.

| Limitation                          | Description                                                                            | Notes                                        |
| ----------------------------------- | -------------------------------------------------------------------------------------- | -------------------------------------------- |
| No biometric identity verification  | The system cannot confirm the person using the device is the registered guard          | Photo verification provides a visual check   |
| GPS accuracy indoors                | Location accuracy degrades in basements, dense buildings, or signal-poor areas         | Photo verification provides corroboration    |
| Network dependency (partial)        | Real-time dashboard updates require connectivity; offline queue covers local recording | Section 5 details offline capabilities       |
| Battery drain                       | Continuous GPS and 15-second uploads reduce device battery life during a shift         | Advise guards to use chargers on long shifts |
| Wakefulness check notification miss | Guards in loud environments or with notification issues may miss checks legitimately   | Supervisor discretion on escalation applies  |
| No AI scene classification          | Photo content is not automatically analysed; it requires human review                  | Phase 4 feature candidate                    |
| ARC integration (Phase 4)           | Full BS 8484 Level 3 compliance requires Alarm Receiving Centre integration            | Included in Phase 4 roadmap                  |

The system is most effective as a deterrent (guards modify their behaviour when monitored) and as an audit tool (verifiable records support investigation and action after the fact). It should be deployed as part of a broader security management programme.

---

## 15. Cost Plan

### 15.1 Development Costs

| Deliverable                                  | Estimated Duration | Cost Indicator         |
| -------------------------------------------- | ------------------ | ---------------------- |
| Full system build (Phases 1–3)               | 5 months           | Fixed-price engagement |
| Phase 4 ARC integration + advanced analytics | +1 month           | Separate engagement    |
| Post-launch maintenance (per month)          | Ongoing            | Fixed monthly retainer |

The team required for delivery: Flutter mobile developer, Laravel backend developer, frontend developer (admin dashboard), QA engineer. Engagements may be structured as fixed-price (budget certainty, requires defined scope) or time-and-materials (flexibility, requires ongoing client involvement).

### 15.2 Third-Party Service Costs

| Service Category    | Basis of Charge                       | Notes                                                |
| ------------------- | ------------------------------------- | ---------------------------------------------------- |
| Push notifications  | Per notification (FCM/APNs)           | Negligible at most operational scales                |
| Cloud photo storage | Per GB stored + data transfer (S3 UK) | Grows with photo evidence volume; 12-month retention |
| NTP queries         | Free                                  | Public NTP pool or AWS Time Sync Service             |

No voice call charges apply. The wakefulness protocol uses push notifications and code challenges only.

### 15.3 Infrastructure and Hosting Costs

| Component          | Configuration                                        |
| ------------------ | ---------------------------------------------------- |
| Application server | Mid-spec VPS or two instances behind a load balancer |
| Database server    | Separate managed PostgreSQL instance (UK region)     |
| Media storage      | AWS S3 UK region (usage-based)                       |
| SSL certificate    | Free (Let's Encrypt)                                 |
| Data residency     | All components hosted in UK cloud infrastructure     |

### 15.4 Ongoing Maintenance and Support

Post-launch maintenance covers security patching, mobile OS compatibility updates (iOS/Android annual releases), feature iterations, and infrastructure monitoring. A structured support retainer is strongly recommended from day one of go-live.

### 15.5 Payment Policy

All development engagements follow a three-stage payment schedule:

- **50% on project commencement** — Confirms commitment, enables resource allocation, covers initial infrastructure and architectural design. No development begins until this payment is received.
- **25% at mid-project milestone** — Due at the end of Month 3, when the core mobile application, backend API, and admin dashboard are functionally complete in a staging environment.
- **25% on project completion** — Due upon delivery of the production-deployed system with signed client acceptance. Acceptance criteria are agreed in writing prior to project commencement.

All invoices are payable within 14 calendar days of issue. Recurring operational costs (third-party services and hosting) are billed separately on a monthly basis from go-live and are not included in the development fee schedule.

---

## 16. Conclusion

The Smart Security Guard Monitoring System addresses a genuine, commercially significant problem in UK security guard operations. The current industry standard — paper logs, infrequent spot-checks, and informal check-ins — provides neither the real-time visibility that effective supervision requires nor the evidential quality that legal and regulatory accountability demands.

This system delivers three clearly defined operational outcomes: continuous location tracking with immediate alerts, live photo verification with cryptographic anti-manipulation controls, and active wakefulness confirmation via a timed code challenge. All three features operate in both online and offline environments, ensuring no operational data is lost during periods of poor connectivity — a routine condition in physical security deployments.

The platform is built for the United Kingdom regulatory environment. UK GDPR and Data Protection Act 2018 compliance is designed into the data model, not bolted on afterwards. The BS 8484:2016 lone worker welfare requirements are addressed by the wakefulness monitoring system. SIA licence tracking and Working Time Regulations controls are integrated into shift scheduling. The infrastructure is hosted exclusively in UK-region cloud infrastructure.

The proposal presented here is a complete specification ready for review, scoping, and implementation.

---

_Version 2.0 — June 1, 2026._
