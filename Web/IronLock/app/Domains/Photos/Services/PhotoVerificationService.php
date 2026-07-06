<?php

namespace App\Domains\Photos\Services;

use App\Domains\Admins\Models\Admin;
use App\Domains\Alerts\Services\AlertService;
use App\Domains\Guards\Models\Guard;
use App\Domains\Nonces\Models\Nonce;
use App\Domains\Nonces\Services\NonceService;
use App\Domains\Notifications\Services\PhotoPushNotifier;
use App\Domains\Photos\Models\PhotoEvidence;
use App\Domains\Photos\Models\PhotoRequest;
use App\Domains\Shifts\Models\Shift;
use App\Domains\Shifts\Models\ShiftEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * PhotoVerificationService — the full liveness-proof pipeline (spec §7–8).
 *
 * Liveness is proven by the server's own clock and records: a single-use,
 * short-lived nonce issued at `nonce_issued_at` and a photo received at
 * `server_received_at`. A photo carrying a valid, unexpired, unused nonce MUST
 * have been captured inside that window. The device clock is irrelevant; NTP /
 * EXIF are secondary anomaly signals that never override the nonce window.
 *
 * Two entry points raise a request:
 *   - createRequest()  — admin manual trigger or server scheduler (ONLINE nonce,
 *                        delivered by push).
 *   - submitPhoto()    — the guard uploads. For an online request the request
 *                        already exists; for an offline self-initiated check
 *                        (drawn from the OFFLINE_POOL) the request is created at
 *                        receipt. The uploaded `nonce_value` is the anchor in
 *                        both cases.
 */
class PhotoVerificationService
{
    /** Upload-delay threshold before DELAYED_UPLOAD is flagged (spec §16.4). */
    public const DELAYED_UPLOAD_SECONDS = 10;

    /** Max images a guard may attach to a single verification request (min 1). */
    public const MAX_PHOTOS_PER_REQUEST = 5;

    /** NTP vs EXIF delta before CLOCK_MANIPULATION_SUSPECTED is flagged. */
    public const CLOCK_SKEW_SECONDS = 30;

    public function __construct(
        private readonly NonceService $nonces,
        private readonly AlertService $alerts,
        private readonly PhotoPushNotifier $push,
    ) {}

    /**
     * Raise a photo request (manual admin trigger or scheduler). Issues a fresh
     * ONLINE nonce, records the request, logs the audit event and fires the
     * best-effort push. Returns the created PhotoRequest.
     */
    public function createRequest(Shift $shift, ?Admin $requestedBy, string $type = PhotoRequest::TYPE_MANUAL): PhotoRequest
    {
        $guard = $shift->assignedGuard;
        $nonce = $this->nonces->issueOnline($guard, $shift);

        $request = PhotoRequest::create([
            'shift_id' => $shift->id,
            'guard_id' => $shift->guard_id,
            'nonce_id' => $nonce->id,
            'requested_by' => $requestedBy?->id,
            'request_type' => $type,
            'nonce_issued_at' => $nonce->issued_at,
            'status' => PhotoRequest::STATUS_PENDING,
            'requested_at' => Carbon::now(),
        ]);

        $request->setRelation('nonce', $nonce);

        $this->logEvent($shift, 'PHOTO_REQUEST', [
            'request_id' => $request->id,
            'request_type' => $type,
            'requested_by' => $requestedBy?->id,
            // GDPR §13.x: every photo request is logged with its stated purpose.
            'reason' => 'shift compliance',
        ]);

        $this->push->notify($guard, $request);

        return $request;
    }

    /**
     * Validate and (on success) store a single uploaded photo.
     *
     * Backward-compatible thin wrapper over submitPhotos() — existing single
     * `photo` + `signature` uploads keep working unchanged.
     *
     * @param array $payload {
     *   request_id?: string, nonce_value: string, signature: string,
     *   captured_at?: string, latitude?: string|float, longitude?: string|float,
     *   exif_timestamp?: string, ntp_timestamp?: string,
     *   ntp_reference?: string, elapsed_seconds?: int|float
     * }
     * @return array{result: 'VALIDATED'|'FLAGGED'|'REJECTED', flags: array<string>, reason: ?string, evidence: ?PhotoEvidence, evidences: array<PhotoEvidence>, count: int}
     */
    public function submitPhoto(Guard $guard, Shift $shift, UploadedFile $file, array $payload): array
    {
        return $this->submitPhotos(
            $guard,
            $shift,
            [['file' => $file, 'signature' => (string) ($payload['signature'] ?? '')]],
            $payload
        );
    }

    /**
     * Validate and (on success) store 1–5 uploaded photos for ONE verification
     * request. Each image is verified independently (its own HMAC over its own
     * bytes) but the request, nonce and capture-time/window checks are evaluated
     * once for the whole submission — the nonce is single-use and is consumed
     * exactly once, no matter how many images accompany it.
     *
     * The submission is all-or-nothing for the hard checks: if any image's HMAC
     * fails, or the nonce/window/timeline check fails, nothing is stored and the
     * request is marked ANOMALY (mirrors the single-photo behaviour exactly).
     * On success every image becomes its own immutable PhotoEvidence row under
     * the request, and the request is fulfilled once.
     *
     * @param array<int, array{file: UploadedFile, signature: string}> $items
     * @param array $payload shared fields (no `photo`/`signature` — those are per item)
     * @return array{result: 'VALIDATED'|'FLAGGED'|'REJECTED', flags: array<string>, reason: ?string, evidence: ?PhotoEvidence, evidences: array<PhotoEvidence>, count: int}
     */
    public function submitPhotos(Guard $guard, Shift $shift, array $items, array $payload): array
    {
        $serverReceivedAt = Carbon::now();

        // Defensive: at least one, at most five images per request.
        $items = array_values(array_filter($items, fn ($i) => ($i['file'] ?? null) instanceof UploadedFile));
        if (count($items) < 1) {
            return $this->reject('NO_IMAGE');
        }
        if (count($items) > self::MAX_PHOTOS_PER_REQUEST) {
            return $this->reject('TOO_MANY_IMAGES');
        }

        // 1. Resolve the nonce (existence / ownership / not-used). Expiry is
        //    judged later against the capture time, per nonce type.
        $nonceValue = (string) ($payload['nonce_value'] ?? '');
        $check = $this->nonces->validate($nonceValue, $guard);

        if (!$check['ok']) {
            return $this->reject($check['reason']);
        }

        /** @var Nonce $nonce */
        $nonce = $check['nonce'];

        // 1b. A nonce is minted for ONE specific shift; a photo may only be
        //     submitted under that same shift. The nonce's own shift is
        //     authoritative — the URL shift is not trusted. Without this, an
        //     offline photo flushed to a *different* shift's endpoint (e.g. a
        //     pool nonce from a shift that just ended, uploaded after the next
        //     shift starts) would be filed against the wrong shift's timeline.
        if ($nonce->shift_id !== $shift->id) {
            return $this->reject('NONCE_WRONG_SHIFT');
        }

        // 2. Resolve or create the photo request this upload fulfils.
        $request = $this->resolveRequest($guard, $shift, $nonce, $payload, $serverReceivedAt);
        if ($request === null) {
            return $this->reject('REQUEST_NOT_FOUND');
        }

        // 3. HMAC integrity per image — recompute over the canonical payload
        //    string (with THIS image's bytes) using the guard's shared secret.
        //    Any mismatch ⇒ reject the whole submission (tampered/forged).
        foreach ($items as $item) {
            if (!$this->verifyHmac($guard, $item['file'], $payload + ['signature' => $item['signature']])) {
                $this->markRequest($request, PhotoRequest::STATUS_ANOMALY, $serverReceivedAt);
                return $this->reject('HMAC_INVALID');
            }
        }

        // 4. Reconstruct the effective capture time and apply the nonce window.
        [$captureTime, $ntpAtCapture, $ntpAvailable] = $this->reconstructCaptureTime($payload, $serverReceivedAt, $nonce);
        $windowReason = $this->checkWindow($nonce, $captureTime, $serverReceivedAt);

        if ($windowReason !== null) {
            // TIMELINE_ANOMALY and NONCE_EXPIRED are hard failures: never stored.
            $this->markRequest($request, PhotoRequest::STATUS_ANOMALY, $serverReceivedAt);
            if ($windowReason === PhotoEvidence::FLAG_TIMELINE_ANOMALY) {
                $this->logEvent($shift, 'TIMELINE_ANOMALY', ['request_id' => $request->id]);
                $this->raiseAlert($guard, $shift, 'TIMELINE_ANOMALY', 'Photo capture time predates its nonce — rejected.');
            }
            return $this->reject($windowReason);
        }

        // 5. Secondary anomaly flags — do NOT reject, only annotate + alert.
        //    Computed once for the submission (the capture context is shared)
        //    and applied to every stored image.
        $flags = [];
        $exifTimestamp = $this->parse($payload['exif_timestamp'] ?? null);

        if (!$ntpAvailable) {
            $flags[] = PhotoEvidence::FLAG_NTP_UNAVAILABLE;
            $this->logEvent($shift, 'NTP_UNAVAILABLE', ['request_id' => $request->id]);
        } elseif ($exifTimestamp && $ntpAtCapture && abs($ntpAtCapture->diffInSeconds($exifTimestamp)) > self::CLOCK_SKEW_SECONDS) {
            $flags[] = PhotoEvidence::FLAG_CLOCK_MANIPULATION;
            $this->logEvent($shift, 'CLOCK_MANIPULATION_SUSPECTED', [
                'request_id' => $request->id,
                'ntp' => $ntpAtCapture->toISOString(),
                'exif' => $exifTimestamp->toISOString(),
            ]);
            $this->raiseAlert($guard, $shift, 'CLOCK_MANIPULATION_SUSPECTED', 'Photo EXIF time differs from NTP by more than 30s.');
        }

        if ($captureTime->diffInSeconds($serverReceivedAt) > self::DELAYED_UPLOAD_SECONDS) {
            $flags[] = PhotoEvidence::FLAG_DELAYED_UPLOAD;
            $this->logEvent($shift, 'DELAYED_UPLOAD', [
                'request_id' => $request->id,
                'delay_seconds' => $captureTime->diffInSeconds($serverReceivedAt),
            ]);
        }

        // 6. Accept: consume the nonce (atomic single-use), store, finalise.
        if (!$this->nonces->markUsed($nonce)) {
            // Lost the race — another upload already consumed this nonce.
            $this->markRequest($request, PhotoRequest::STATUS_ANOMALY, $serverReceivedAt);
            return $this->reject('NONCE_ALREADY_USED');
        }

        // An OFFLINE_POOL nonce means the guard captured this while disconnected
        // and it flushed on reconnect — recorded so the timeline and the welfare
        // report can label the attempt "Offline" (the report reads this flag off
        // the first evidence's metadata; without it every photo read as Online).
        $capturedOffline = $nonce->type !== Nonce::TYPE_ONLINE;

        $evidences = [];
        $total = count($items);
        foreach ($items as $i => $item) {
            $evidences[] = $this->store(
                $guard, $shift, $item['file'], $request, $payload,
                $captureTime, $ntpAtCapture, $exifTimestamp, $flags, $serverReceivedAt, $i, $total, $capturedOffline
            );
        }

        $request->update([
            'status' => PhotoRequest::STATUS_FULFILLED,
            'submitted_at' => $captureTime,
            'server_received_at' => $serverReceivedAt,
        ]);

        $this->logEvent($shift, 'PHOTO_SUBMITTED', [
            'request_id' => $request->id,
            'evidence_ids' => array_map(fn (PhotoEvidence $e) => $e->id, $evidences),
            'image_count' => $total,
            'flags' => $flags,
        ]);

        return [
            'result' => empty($flags) ? 'VALIDATED' : 'FLAGGED',
            'flags' => $flags,
            'reason' => null,
            'evidence' => $evidences[0] ?? null,
            'evidences' => $evidences,
            'count' => $total,
        ];
    }

    /**
     * A short-lived signed URL for an admin to view stored evidence. The file
     * lives on the private `photos` disk and is never publicly served.
     */
    public function signedUrlFor(PhotoEvidence $evidence, int $minutes = 5): ?string
    {
        if (empty($evidence->file_path)) {
            return null;
        }

        return Storage::disk('photos')->temporaryUrl($evidence->file_path, Carbon::now()->addMinutes($minutes));
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Find the pre-existing online request, or create one for an offline
     * self-initiated check. For an online request the uploaded nonce must be
     * the one we issued for it.
     */
    private function resolveRequest(Guard $guard, Shift $shift, Nonce $nonce, array $payload, Carbon $serverReceivedAt): ?PhotoRequest
    {
        $requestId = $payload['request_id'] ?? null;

        if (!empty($requestId)) {
            $request = PhotoRequest::where('id', $requestId)
                ->where('guard_id', $guard->id)
                ->where('shift_id', $shift->id)
                ->first();

            // Must exist and be bound to the nonce being presented.
            if (!$request || ($request->nonce_id && $request->nonce_id !== $nonce->id)) {
                return null;
            }

            $request->setRelation('nonce', $nonce);
            return $request;
        }

        // Offline self-initiated check (pool nonce) — materialise the request.
        $request = PhotoRequest::create([
            'shift_id' => $shift->id,
            'guard_id' => $guard->id,
            'nonce_id' => $nonce->id,
            'requested_by' => null,
            'request_type' => PhotoRequest::TYPE_SCHEDULED,
            'nonce_issued_at' => $nonce->issued_at,
            'status' => PhotoRequest::STATUS_PENDING,
            'requested_at' => $nonce->issued_at,
        ]);
        $request->setRelation('nonce', $nonce);

        return $request;
    }

    /**
     * Reconstruct the photo's effective capture time and whether trusted
     * network time was available.
     *
     *   ONLINE      — uploaded live, so server_received_at is the authoritative
     *                 anchor; ntp_timestamp (if present) is the trusted capture.
     *   OFFLINE_POOL — reconstruct NTP-anchored time: ntp_reference + elapsed.
     *
     * @return array{0: Carbon, 1: ?Carbon, 2: bool} [captureTime, ntpAtCapture, ntpAvailable]
     */
    private function reconstructCaptureTime(array $payload, Carbon $serverReceivedAt, Nonce $nonce): array
    {
        $ntpTimestamp = $this->parse($payload['ntp_timestamp'] ?? null);
        $ntpReference = $this->parse($payload['ntp_reference'] ?? null);
        $captured = $this->parse($payload['captured_at'] ?? null);

        // Trusted shutter-time NTP wins outright.
        if ($ntpTimestamp) {
            return [$ntpTimestamp, $ntpTimestamp, true];
        }

        // Offline NTP-anchor reconstruction: reference + elapsed device time.
        if ($ntpReference && isset($payload['elapsed_seconds']) && is_numeric($payload['elapsed_seconds'])) {
            $reconstructed = $ntpReference->copy()->addSeconds((int) round((float) $payload['elapsed_seconds']));
            return [$reconstructed, $reconstructed, true];
        }

        // No NTP at all. For an ONLINE nonce the upload is live, so the server
        // receipt is the best honest anchor; for OFFLINE we can only fall back
        // to the device-claimed capture time (used for the timeline check).
        if ($nonce->type === Nonce::TYPE_ONLINE) {
            return [$captured ?? $serverReceivedAt, null, false];
        }

        return [$captured ?? $serverReceivedAt, null, false];
    }

    /**
     * Apply the nonce window to the capture time. Returns a hard-failure flag
     * (TIMELINE_ANOMALY / NONCE_EXPIRED) or null if the photo is inside window.
     */
    private function checkWindow(Nonce $nonce, Carbon $captureTime, Carbon $serverReceivedAt): ?string
    {
        $issuedAt = $nonce->issued_at;

        // Capture can never predate nonce issuance — the nonce did not exist.
        if ($captureTime->lt($issuedAt)) {
            return PhotoEvidence::FLAG_TIMELINE_ANOMALY;
        }

        if ($nonce->type === Nonce::TYPE_ONLINE) {
            // Online window evaluated at receipt — a live upload that arrives
            // after the window closed is an expired online nonce. The window
            // length is the single config value shared with the timeout sweep
            // and the app countdown (default 90s).
            if ($serverReceivedAt->gt($issuedAt->copy()->addSeconds(NonceService::onlineTtlSeconds()))) {
                return 'NONCE_EXPIRED';
            }
            return null;
        }

        // OFFLINE_POOL — 15-min window judged against the reconstructed capture.
        if ($captureTime->gt($issuedAt->copy()->addMinutes(NonceService::OFFLINE_TTL_MINUTES))) {
            return 'NONCE_EXPIRED';
        }

        return null;
    }

    /**
     * Verify the HMAC signature over the canonical payload. See the mobile
     * contract for the exact string — it MUST match byte-for-byte.
     *
     *   message = nonce_value \n request_id \n captured_at \n latitude
     *             \n longitude \n sha256_hex(image bytes)
     *   signature = hash_hmac('sha256', message, guard.hmac_secret)   (lc hex)
     */
    private function verifyHmac(Guard $guard, UploadedFile $file, array $payload): bool
    {
        $signature = (string) ($payload['signature'] ?? '');
        if ($signature === '' || empty($guard->hmac_secret)) {
            return false;
        }

        $message = implode("\n", [
            (string) ($payload['nonce_value'] ?? ''),
            (string) ($payload['request_id'] ?? ''),
            (string) ($payload['captured_at'] ?? ''),
            (string) ($payload['latitude'] ?? ''),
            (string) ($payload['longitude'] ?? ''),
            hash_file('sha256', $file->getRealPath()),
        ]);

        $expected = hash_hmac('sha256', $message, $guard->hmac_secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Persist the image to the private disk and write the immutable evidence
     * row. Extra capture context lives in `metadata` (there is no dedicated
     * column for it by design).
     */
    private function store(
        Guard $guard,
        Shift $shift,
        UploadedFile $file,
        PhotoRequest $request,
        array $payload,
        Carbon $captureTime,
        ?Carbon $ntpAtCapture,
        ?Carbon $exifTimestamp,
        array $flags,
        Carbon $serverReceivedAt,
        int $index = 0,
        int $total = 1,
        bool $capturedOffline = false,
    ): PhotoEvidence {
        $path = $this->buildStoragePath($guard, $shift, $file, $serverReceivedAt, $index, $total);
        Storage::disk('photos')->put($path, file_get_contents($file->getRealPath()));

        return PhotoEvidence::create([
            'photo_request_id' => $request->id,
            'file_path' => $path,
            'sha256_hash' => hash_file('sha256', $file->getRealPath()),
            'captured_at' => $captureTime,
            'ntp_timestamp_at_capture' => $ntpAtCapture,
            'exif_timestamp' => $exifTimestamp,
            'gps_latitude' => isset($payload['latitude']) && is_numeric($payload['latitude']) ? (float) $payload['latitude'] : null,
            'gps_longitude' => isset($payload['longitude']) && is_numeric($payload['longitude']) ? (float) $payload['longitude'] : null,
            'metadata' => [
                'device_captured_at' => $payload['captured_at'] ?? null,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'server_received_at' => $serverReceivedAt->toISOString(),
                // Drawn from an OFFLINE_POOL nonce → captured offline, flushed on
                // reconnect (read by ShiftReportComposer::photos() for the mode).
                'captured_offline' => $capturedOffline,
            ],
            'flags' => empty($flags) ? null : array_values($flags),
        ]);
    }

    /**
     * Build the evidence file path under the date/shift folder tree:
     *
     *   {YYYY}/{MM}/{DD}/{shiftReference}/{employeeCode}-{FullName}_{HHMMSS}.{ext}
     *   e.g. 2026/06/25/SH-1001/GRD3577-GunaseelanJerryson_143052.jpg
     *
     * The date + time are taken from the authoritative server-receipt timestamp
     * (UTC) — never the device clock — so the tree reflects when the server
     * actually stored the evidence and an offline upload lands in the day it was
     * received. The `_HHMMSS` suffix keeps photos on the same shift+day from
     * overwriting one another; when a single request carries multiple images
     * (1–5) they all share the same second, so a `_NN` sequence is appended to
     * keep each filename unique and collision-safe. Folders are created lazily by
     * the disk's put() on first write; empty day/shift folders never appear.
     */
    private function buildStoragePath(Guard $guard, Shift $shift, UploadedFile $file, Carbon $serverReceivedAt, int $index = 0, int $total = 1): string
    {
        $stamp = $serverReceivedAt->copy()->utc();

        // Employee code, sanitised to a filesystem-safe token (fallback GRD).
        $code = preg_replace('/[^A-Za-z0-9]/', '', (string) $guard->employee_code);
        $code = $code !== '' ? $code : 'GRD';

        // Full name → PascalCase with no separators (e.g. "Gunaseelan Jerryson"
        // → "GunaseelanJerryson"); strip anything non-alphanumeric.
        $name = preg_replace('/[^A-Za-z0-9]/', '', str_replace(' ', '', ucwords(trim(
            $guard->first_name . ' ' . $guard->last_name
        ))));
        $name = $name !== '' ? $name : 'Guard';

        // Shift reference (SH-####); fall back to the UUID if somehow unset.
        $ref = preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($shift->reference ?: $shift->id));

        $ext = strtolower($file->getClientOriginalExtension() ?: ($file->extension() ?: 'jpg'));

        // Single image keeps the original name exactly; a multi-image submission
        // appends a 1-based sequence (all images share the same second).
        $seq = $total > 1 ? '_' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) : '';
        $filename = "{$code}-{$name}_{$stamp->format('His')}{$seq}.{$ext}";

        return "{$stamp->format('Y')}/{$stamp->format('m')}/{$stamp->format('d')}/{$ref}/{$filename}";
    }

    private function markRequest(PhotoRequest $request, string $status, Carbon $serverReceivedAt): void
    {
        $request->update([
            'status' => $status,
            'server_received_at' => $serverReceivedAt,
        ]);
    }

    /**
     * @return array{result: 'REJECTED', flags: array<string>, reason: string, evidence: null}
     */
    private function reject(string $reason): array
    {
        return [
            'result' => 'REJECTED',
            'flags' => [],
            'reason' => $reason,
            'evidence' => null,
        ];
    }

    private function raiseAlert(Guard $guard, Shift $shift, string $type, string $description): void
    {
        try {
            $name = trim($guard->first_name . ' ' . $guard->last_name);
            $this->alerts->createAlert($guard->id, $shift->id, [
                'type' => $type,
                'severity' => 'CRITICAL',
                'title' => "{$type} — {$name}",
                'description' => $description,
            ]);
        } catch (\Throwable $e) {
            // Alerting is best-effort; never block the upload response.
        }
    }

    private function logEvent(Shift $shift, string $eventType, array $metadata = []): void
    {
        try {
            ShiftEvent::create([
                'id' => (string) Str::uuid(),
                'shift_id' => $shift->id,
                'guard_id' => $shift->guard_id,
                'event_type' => $eventType,
                'metadata' => $metadata,
                'recorded_at' => Carbon::now(),
                'server_received_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            // Audit is non-critical to the request path.
        }
    }

    private function parse(?string $value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
