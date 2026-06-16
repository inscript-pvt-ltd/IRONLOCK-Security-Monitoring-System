const express = require('express');
const multer = require('multer');
const cors = require('cors');
const crypto = require('crypto');

const app = express();
const upload = multer({ storage: multer.memoryStorage() });

app.use(cors());
app.use(express.json());

// ─── Constants ───────────────────────────────────────────────────────────────

const VALID_PASSWORD = 'password123';
const PHOTO_HMAC_SECRET = 'IRONLOCK_PHOTO_SECRET_v1';
const ACCESS_TOKEN_TTL_MS = 2 * 60 * 60 * 1000; // 2 hours
const START_WINDOW_MS = 15 * 60 * 1000; // 15 minutes before scheduled_start
const MAX_LOGIN_ATTEMPTS = 5;

let nextAlertId = 100;

// ─── In-memory state ──────────────────────────────────────────────────────────

const guards = {
  1: {
    id: 'b1f3a2c0-1111-4a2b-9c3d-000000000001',
    employee_code: 'SGM-0042',
    first_name: 'James',
    last_name: 'Smith',
    username: 'j.smith',
    email: 'j.smith@ironlock.co.uk',
    phone: '+44 7700 900123',
    sia_licence_number: 'SP 1234 5678 0001',
    sia_licence_expiry: '2026-12-28',
    sia_licence_type: 'Security Guarding',
    employment_status: 'active',
  },
};

function guardByLegacyId(legacyId) {
  return guards[legacyId];
}

function findGuardByIdentifier(identifier) {
  const key = identifier.trim().toLowerCase();
  return Object.values(guards).find(
    (g) => g.employee_code.toLowerCase() === key || g.email.toLowerCase() === key
  );
}

// One shift per guard for this mock.
const shiftsByGuard = {
  1: {
    id: 'c2a4b6d8-2222-4b3c-8d4e-000000000010',
    guard_id: 1,
    status: 'scheduled', // scheduled | active | completed | cancelled
    scheduled_start: new Date(new Date().setHours(12, 0, 0, 0)).toISOString(),
    scheduled_end: new Date(new Date().setHours(17, 0, 0, 0)).toISOString(),
    actual_start: null,
    actual_end: null,
    role: 'Lone Guard',
    notes: 'Main entrance and car park patrol. Check CCTV room at start of shift.',
    site: {
      id: 'd3b5c7e9-3333-4c4d-9e5f-000000000020',
      name: 'Westfield Shopping Centre A',
      grace_period_minutes: 5,
    },
    geofence: {
      id: 'e4c6d8fa-4444-4d5e-af6a-000000000030',
      name: 'Westfield A — Perimeter',
      coordinates: [
        [51.5074, -0.1278],
        [51.5080, -0.1270],
        [51.5065, -0.1265],
        [51.5070, -0.1280],
      ],
    },
  },
};

// guardId -> { accessToken, refreshToken, deviceId, expiresAt }
const guardSessions = new Map();
// accessToken -> { guardId, deviceId, expiresAt }
const activeSessions = new Map();
// refreshToken -> { guardId, deviceId }
const refreshTokens = new Map();

// identifier (lowercased) -> failed attempt count
const failedAttempts = new Map();
// identifier (lowercased) -> true once locked
const lockedIdentifiers = new Set();

const alerts = {
  a1: {
    id: 'a1',
    severity: 'urgent',
    title: 'Welfare check not completed',
    description: 'A check-in code was not entered in time — your supervisor has been notified',
    time: '4m ago',
    dismissed: false,
  },
  a2: {
    id: 'a2',
    severity: 'notice',
    title: 'Shift handover reminder',
    description: 'Your shift ends in 30 minutes. Please complete the handover checklist.',
    time: '10m ago',
    dismissed: false,
  },
  a3: {
    id: 'a3',
    severity: 'reminder',
    title: 'Photo verification due',
    description: 'Please submit your ID photo for the current shift.',
    time: '1h ago',
    dismissed: false,
  },
};

// GPS pings per shift (last 20 kept for dashboard)
const gpsSamples = {};

// Wakefulness — guardId -> true once a check should be delivered on next poll
const pendingWelfareTrigger = new Map();
// checkId -> { guardId, code, createdAt }
const activeWakefulnessChecks = new Map();

// Photos — guardId -> true once a request should be delivered on next poll
const pendingPhotoTrigger = new Map();
// requestId -> { guardId, createdAt }
const activePhotoRequests = new Map();
// single-use nonce enforcement across all uploads
const usedNonces = new Set();

// ─── Envelope helpers ───────────────────────────────────────────────────────

function ok(res, data, status = 200) {
  res.status(status).json({
    success: true,
    data,
    meta: { timestamp: new Date().toISOString() },
  });
}

function fail(res, status, code, message, details) {
  const error = { code, message };
  if (details) error.details = details;
  res.status(status).json({ success: false, error });
}

function makeToken(prefix) {
  return `${prefix}_${crypto.randomBytes(24).toString('hex')}`;
}

function publicGuard(guard) {
  return { ...guard };
}

function authGuard(req, res) {
  const header = req.headers['authorization'] || '';
  const token = header.replace(/^Bearer\s+/i, '');
  if (!token) {
    fail(res, 401, 'UNAUTHENTICATED', 'Authentication required.');
    return null;
  }
  const session = activeSessions.get(token);
  if (!session) {
    fail(res, 401, 'TOKEN_INVALID', 'Token is invalid or has been superseded by a newer login.');
    return null;
  }
  if (Date.now() > session.expiresAt) {
    fail(res, 401, 'TOKEN_EXPIRED', 'Access token has expired.');
    return null;
  }
  return session;
}

function issueSession(guardId, deviceId) {
  // Single active session per guard — invalidate any previous tokens.
  const previous = guardSessions.get(guardId);
  if (previous) {
    activeSessions.delete(previous.accessToken);
    refreshTokens.delete(previous.refreshToken);
  }
  const accessToken = makeToken('mock_access');
  const refreshToken = makeToken('mock_refresh');
  const expiresAt = Date.now() + ACCESS_TOKEN_TTL_MS;
  activeSessions.set(accessToken, { guardId, deviceId, expiresAt });
  refreshTokens.set(refreshToken, { guardId, deviceId });
  guardSessions.set(guardId, { accessToken, refreshToken, deviceId, expiresAt });
  return { accessToken, refreshToken, expiresAt };
}

function computeFlags(shift) {
  const now = Date.now();
  const start = new Date(shift.scheduled_start).getTime();
  const end = new Date(shift.scheduled_end).getTime();
  const canStart = shift.status === 'scheduled' && now >= start - START_WINDOW_MS && now <= end;
  const canEnd = shift.status === 'active';
  return { can_start: canStart, can_end: canEnd };
}

function shiftPayload(shift) {
  const flags = computeFlags(shift);
  return {
    id: shift.id,
    status: shift.status,
    scheduled_start: shift.scheduled_start,
    scheduled_end: shift.scheduled_end,
    actual_start: shift.actual_start,
    actual_end: shift.actual_end,
    can_start: flags.can_start,
    can_end: flags.can_end,
    role: shift.role,
    notes: shift.notes,
    site: shift.site,
    geofence: shift.geofence,
  };
}

function verifyPhotoHmac(nonce, shiftId, capturedAt, signature) {
  const message = `${nonce}:${shiftId}:${capturedAt}`;
  const expected = crypto.createHmac('sha256', PHOTO_HMAC_SECRET).update(message).digest('hex');
  return expected === signature;
}

const BASE = '/api/mobile/v1';

// ─── Health check ───────────────────────────────────────────────────────────

app.get(`${BASE}/status`, (req, res) => {
  res.json({
    status: 'IronLock Mobile API Ready (mock)',
    version: '1.0.0',
    timestamp: new Date().toISOString(),
  });
});

// ─── Auth ───────────────────────────────────────────────────────────────────

app.post(`${BASE}/auth/login`, (req, res) => {
  const { identifier, password, device } = req.body || {};
  if (!identifier || !password) {
    return fail(res, 422, 'VALIDATION_ERROR', 'Identifier and password are required.', {
      identifier: !identifier ? ['The identifier field is required.'] : undefined,
      password: !password ? ['The password field is required.'] : undefined,
    });
  }

  const key = identifier.trim().toLowerCase();
  if (lockedIdentifiers.has(key)) {
    return fail(res, 423, 'ACCOUNT_LOCKED', 'Account locked after too many failed attempts. Contact your supervisor.');
  }

  const guard = findGuardByIdentifier(identifier);
  if (!guard || password !== VALID_PASSWORD) {
    const attempts = (failedAttempts.get(key) || 0) + 1;
    failedAttempts.set(key, attempts);
    if (attempts >= MAX_LOGIN_ATTEMPTS) {
      lockedIdentifiers.add(key);
      console.log(`[LOGIN] ${key} locked after ${attempts} failed attempts`);
      return fail(res, 423, 'ACCOUNT_LOCKED', 'Account locked after 5 failed attempts. Contact your supervisor.');
    }
    console.log(`[LOGIN] ${key} failed attempt ${attempts}/${MAX_LOGIN_ATTEMPTS}`);
    return fail(res, 401, 'INVALID_CREDENTIALS', 'Incorrect employee ID/email or password.');
  }

  failedAttempts.delete(key);
  const legacyGuardId = 1; // single mock guard
  const deviceId = device?.device_id ?? null;
  const session = issueSession(legacyGuardId, deviceId);

  console.log(`[LOGIN] ${guard.email} → session issued (device=${deviceId ?? 'unknown'})`);
  ok(res, {
    token_type: 'Bearer',
    access_token: session.accessToken,
    refresh_token: session.refreshToken,
    expires_at: new Date(session.expiresAt).toISOString(),
    guard: publicGuard(guard),
  });
});

app.post(`${BASE}/auth/refresh`, (req, res) => {
  const { refresh_token, device } = req.body || {};
  const stored = refreshTokens.get(refresh_token);
  if (!stored) {
    return fail(res, 401, 'TOKEN_INVALID', 'Refresh token is invalid or has expired.');
  }
  const deviceId = device?.device_id ?? stored.deviceId;
  const session = issueSession(stored.guardId, deviceId);
  console.log(`[REFRESH] guard=${stored.guardId} → new token pair issued`);
  ok(res, {
    token_type: 'Bearer',
    access_token: session.accessToken,
    refresh_token: session.refreshToken,
    expires_at: new Date(session.expiresAt).toISOString(),
  });
});

app.post(`${BASE}/auth/logout`, (req, res) => {
  const session = authGuard(req, res);
  if (!session) return;
  const guardSession = guardSessions.get(session.guardId);
  if (guardSession) {
    activeSessions.delete(guardSession.accessToken);
    refreshTokens.delete(guardSession.refreshToken);
    guardSessions.delete(session.guardId);
  }
  console.log(`[LOGOUT] guard=${session.guardId}`);
  ok(res, { message: 'Logged out.' });
});

app.get(`${BASE}/me`, (req, res) => {
  const session = authGuard(req, res);
  if (!session) return;
  ok(res, { guard: publicGuard(guardByLegacyId(session.guardId)) });
});

// ─── Shifts ─────────────────────────────────────────────────────────────────

app.get(`${BASE}/shifts/current`, (req, res) => {
  const session = authGuard(req, res);
  if (!session) return;
  const shift = shiftsByGuard[session.guardId];
  if (!shift) return ok(res, { shift: null });
  ok(res, { shift: shiftPayload(shift) });
});

app.post(`${BASE}/shifts/:id/start`, (req, res) => {
  const session = authGuard(req, res);
  if (!session) return;
  const shift = shiftsByGuard[session.guardId];
  if (!shift || shift.id !== req.params.id) {
    return fail(res, 404, 'NOT_FOUND', 'Shift not found.');
  }
  const flags = computeFlags(shift);
  if (!flags.can_start) {
    const startsAt = new Date(new Date(shift.scheduled_start).getTime() - START_WINDOW_MS);
    const hh = String(startsAt.getHours()).padStart(2, '0');
    const mm = String(startsAt.getMinutes()).padStart(2, '0');
    return fail(
      res,
      409,
      'SHIFT_NOT_STARTABLE',
      `You can begin your shift from ${hh}:${mm}.`
    );
  }
  shift.status = 'active';
  shift.actual_start = new Date().toISOString();
  gpsSamples[shift.id] = [];
  console.log(`[SHIFT START] id=${shift.id}`);
  ok(res, { shift: shiftPayload(shift) });
});

app.post(`${BASE}/shifts/:id/end`, (req, res) => {
  const session = authGuard(req, res);
  if (!session) return;
  const shift = shiftsByGuard[session.guardId];
  if (!shift || shift.id !== req.params.id) {
    return fail(res, 404, 'NOT_FOUND', 'Shift not found.');
  }
  if (shift.status !== 'active') {
    return fail(res, 409, 'SHIFT_NOT_ENDABLE', 'Shift is not currently active.');
  }
  shift.status = 'completed';
  shift.actual_end = new Date().toISOString();
  const durationHours =
    (new Date(shift.actual_end).getTime() - new Date(shift.actual_start).getTime()) / 3600000;
  console.log(`[SHIFT END] id=${shift.id} duration=${durationHours.toFixed(2)}h`);
  ok(res, {
    shift: { ...shiftPayload(shift), duration_hours: Math.round(durationHours * 100) / 100 },
  });
});

// ─── GPS (Phase 3.3 — frozen shape, kept "working" for local dev) ────────────

app.post(`${BASE}/shifts/:id/locations`, (req, res) => {
  const session = authGuard(req, res);
  if (!session) return;
  const shift = shiftsByGuard[session.guardId];
  if (!shift || shift.id !== req.params.id) {
    return fail(res, 404, 'NOT_FOUND', 'Shift not found.');
  }
  const { pings } = req.body || {};
  if (!Array.isArray(pings) || pings.length === 0) {
    return fail(res, 422, 'VALIDATION_ERROR', 'pings array is required.', {
      pings: ['The pings field is required and must be a non-empty array.'],
    });
  }

  if (!gpsSamples[shift.id]) gpsSamples[shift.id] = [];
  gpsSamples[shift.id].push(...pings);
  if (gpsSamples[shift.id].length > 20) {
    gpsSamples[shift.id] = gpsSamples[shift.id].slice(-20);
  }

  const results = pings.map((p) => {
    const insideZone = p.latitude !== 0 && p.longitude !== 0; // mock heuristic
    return {
      recorded_at: p.recorded_at,
      zone_status: insideZone ? 'INSIDE_ZONE' : 'OUTSIDE_ZONE',
      requires_alert: false,
    };
  });

  console.log(`[GPS] shift=${shift.id} pings=${pings.length}`);
  ok(res, { results });
});

// ─── Wakefulness (Phase 3.3 — frozen shape) ──────────────────────────────────

app.post(`${BASE}/wakefulness/:checkId/respond`, (req, res) => {
  const session = authGuard(req, res);
  if (!session) return;
  const entry = activeWakefulnessChecks.get(req.params.checkId);
  if (!entry || entry.guardId !== session.guardId) {
    return fail(res, 404, 'NOT_FOUND', 'Wakefulness check not found or already resolved.');
  }
  activeWakefulnessChecks.delete(req.params.checkId);
  const result = req.body?.code === entry.code ? 'PASSED' : 'FAILED';

  if (result === 'FAILED') {
    const id = `a${nextAlertId++}`;
    alerts[id] = {
      id,
      severity: 'urgent',
      title: 'Welfare check not completed',
      description: 'A check-in code was entered incorrectly — your supervisor has been notified.',
      time: 'Just now',
      dismissed: false,
    };
  }

  console.log(`[WAKEFULNESS] check=${req.params.checkId} result=${result}`);
  ok(res, { result });
});

// ─── Photos (Phase 3.3 — frozen shape) ───────────────────────────────────────

app.post(`${BASE}/shifts/:id/photos`, upload.single('photo'), (req, res) => {
  const session = authGuard(req, res);
  if (!session) return;
  const shift = shiftsByGuard[session.guardId];
  if (!shift || shift.id !== req.params.id) {
    return fail(res, 404, 'NOT_FOUND', 'Shift not found.');
  }

  const { request_id, captured_at, nonce, signature } = req.body;
  const sizeKb = req.file ? Math.round(req.file.size / 1024) : 0;

  // Extra anti-replay fields kept alongside the spec's required fields (not
  // part of the contract — backend may ignore them once the real API lands).
  if (nonce) {
    if (usedNonces.has(nonce)) {
      console.log(`[PHOTO] REJECTED — nonce already used: ${nonce}`);
      return fail(res, 422, 'VALIDATION_ERROR', 'Nonce already used.', { nonce: ['Already used.'] });
    }
    if (signature && captured_at && !verifyPhotoHmac(nonce, shift.id, captured_at, signature)) {
      console.log('[PHOTO] REJECTED — invalid HMAC signature');
      return fail(res, 422, 'VALIDATION_ERROR', 'Signature verification failed.', {
        signature: ['Signature verification failed.'],
      });
    }
    usedNonces.add(nonce);
  }

  if (activePhotoRequests.has(request_id)) {
    activePhotoRequests.delete(request_id);
  }

  const result = Math.random() < 0.1 ? 'FLAGGED' : 'VALIDATED';
  console.log(
    `[PHOTO] shift=${shift.id} request=${request_id ?? '-'} size=${sizeKb}kb nonce=${nonce ? '✓' : '✗'} → ${result}`
  );
  ok(res, { result });
});

// ─── Alerts (app-only feature, not part of the official contract) ───────────

app.get(`${BASE}/alerts`, (req, res) => {
  const session = authGuard(req, res);
  if (!session) return;
  const list = Object.values(alerts).filter((a) => !a.dismissed);
  ok(res, list);
});

app.post(`${BASE}/alerts/:id/dismiss`, (req, res) => {
  const session = authGuard(req, res);
  if (!session) return;
  const alert = alerts[req.params.id];
  if (!alert) return fail(res, 404, 'NOT_FOUND', 'Alert not found.');
  alert.dismissed = true;
  console.log(`[ALERT DISMISS] id=${req.params.id}`);
  ok(res, { message: 'Alert dismissed.' });
});

// ─── Pending check queues ────────────────────────────────────────────────────
// Not part of the official contract — interim polling mechanism standing in
// for the real push delivery (FCM/APNs, §9.3) until that's built.

app.get(`${BASE}/welfare/pending`, (req, res) => {
  const session = authGuard(req, res);
  if (!session) return;

  if (pendingWelfareTrigger.get(session.guardId)) {
    pendingWelfareTrigger.delete(session.guardId);
    const checkId = crypto.randomUUID();
    const code = String(Math.floor(1000 + Math.random() * 9000));
    activeWakefulnessChecks.set(checkId, { guardId: session.guardId, code, createdAt: Date.now() });
    console.log(`[POLL welfare] pending=true check=${checkId} code=${code}`);
    return ok(res, { pending: true, check_id: checkId, code });
  }

  ok(res, { pending: false });
});

app.get(`${BASE}/photos/pending`, (req, res) => {
  const session = authGuard(req, res);
  if (!session) return;

  if (pendingPhotoTrigger.get(session.guardId)) {
    pendingPhotoTrigger.delete(session.guardId);
    const requestId = crypto.randomUUID();
    activePhotoRequests.set(requestId, { guardId: session.guardId, createdAt: Date.now() });
    console.log(`[POLL photo] pending=true request=${requestId}`);
    return ok(res, { pending: true, request_id: requestId });
  }

  ok(res, { pending: false });
});

// Admin endpoints — curl these to trigger checks
app.post('/admin/trigger-welfare', (req, res) => {
  pendingWelfareTrigger.set(1, true);
  console.log('[ADMIN] Welfare check queued');
  res.json({ queued: true });
});

app.post('/admin/trigger-photo', (req, res) => {
  pendingPhotoTrigger.set(1, true);
  console.log('[ADMIN] Photo request queued');
  res.json({ queued: true });
});

// ─── Start ────────────────────────────────────────────────────────────────────

const PORT = 8000;
app.listen(PORT, () => {
  console.log(`\nIronlock mock backend  http://127.0.0.1:${PORT}${BASE}`);
  console.log(`\nCredentials:  SGM-0042 (or j.smith@ironlock.co.uk) / ${VALID_PASSWORD}`);
  console.log(`\nAdmin triggers:`);
  console.log(`  curl -X POST http://127.0.0.1:${PORT}/admin/trigger-welfare`);
  console.log(`  curl -X POST http://127.0.0.1:${PORT}/admin/trigger-photo\n`);
});
