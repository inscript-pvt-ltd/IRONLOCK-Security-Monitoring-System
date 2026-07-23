<?php

namespace App\Domains\Nonces\Services;

use App\Domains\Guards\Models\Guard;
use App\Domains\Nonces\Models\Nonce;
use App\Domains\Shifts\Models\Shift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * NonceService — single source of truth for the cryptographic nonce lifecycle
 * that underpins photo liveness proof (PROJECT_MASTER_SPEC.md §7.3, §16.4).
 *
 * A nonce is the server's proof of when a photo could have been captured:
 * issued with a server timestamp, single-use, and short-lived. The device
 * clock is irrelevant — a photo carrying a valid, unexpired, unused nonce MUST
 * have been captured inside the server-defined window.
 *
 *   ONLINE       — issued live per request; TTL = config('ironlock.photo_response_seconds')
 *                  (default 90s — see onlineTtlSeconds()).
 *   OFFLINE_POOL — pre-fetched batch (10–20) for offline capture; validity spans
 *                  the whole shift (offlineExpiryFor()) so a start-time prefetch
 *                  covers every 50–70-min photo mark while disconnected.
 *
 * Every timestamp here is server-assigned (UTC) and set explicitly in PHP so it
 * never falls back to a DB CURRENT_TIMESTAMP default (which uses the DB session
 * timezone, not UTC) — see the db-session-tz memo.
 */
class NonceService
{
    /**
     * Offline-pool nonce MINIMUM lifetime in minutes — a floor, not the normal
     * life. Offline photo marks fall 50–70 min apart across a whole shift
     * (photo_min/max_gap_minutes), so a pool nonce prefetched at start must stay
     * valid for the ENTIRE shift or it would be dead (at +15 min) long before
     * the first mark (~+50 min) — and an offline device cannot refill. So
     * offlineExpiryFor() spans a pool nonce to the shift's scheduled end (+
     * grace); this floor only guards a pathological prefetch made at/after the
     * scheduled end. Replay safety is independent of the window — single-use is
     * enforced atomically in markUsed().
     */
    public const OFFLINE_TTL_MINUTES = 15;

    /** Default offline pool batch size (spec §12.5: 10–20). */
    public const DEFAULT_POOL_SIZE = 20;

    /**
     * Online nonce lifetime in seconds. The SINGLE source of truth for the
     * online photo window, shared with the photo-request timeout sweep
     * (photos:timeout-sweep) and the `response_seconds` surfaced to the app, so
     * the on-screen countdown matches the deadline the server actually enforces.
     * Owner-set to 90s — supersedes the spec's original 60s (§16.4 #12); see
     * config/ironlock.php → photo_response_seconds.
     */
    public static function onlineTtlSeconds(): int
    {
        return (int) config('ironlock.photo_response_seconds', 90);
    }

    /**
     * Issue a single ONLINE nonce for a live photo request.
     */
    public function issueOnline(Guard $guard, Shift $shift): Nonce
    {
        $now = Carbon::now();

        return Nonce::create([
            'guard_id' => $guard->id,
            'shift_id' => $shift->id,
            'nonce_value' => $this->generateValue(),
            'issued_at' => $now,
            'expires_at' => $now->copy()->addSeconds(self::onlineTtlSeconds()),
            'type' => Nonce::TYPE_ONLINE,
        ]);
    }

    /**
     * When an OFFLINE_POOL nonce issued now for this shift should expire: the
     * shift's scheduled end plus a grace margin (covers scheduled-end overrun
     * and the auto-close grace), floored to now + OFFLINE_TTL_MINUTES so an
     * edge-case prefetch made at/after scheduled_end still yields a usable
     * window. This is what lets a single prefetch at shift start cover every
     * 50–70-min offline photo mark for the whole shift — see OFFLINE_TTL_MINUTES.
     */
    public static function offlineExpiryFor(Shift $shift): Carbon
    {
        $now = Carbon::now();
        $floor = $now->copy()->addMinutes(self::OFFLINE_TTL_MINUTES);
        $grace = (int) config('ironlock.offline_nonce_grace_minutes', 120);

        $end = $shift->scheduled_end
            ? Carbon::parse($shift->scheduled_end)->addMinutes($grace)
            : $floor;

        return $end->greaterThan($floor) ? $end : $floor;
    }

    /**
     * The offline window as whole minutes from now — the shift-spanning value
     * surfaced to the app (the shift-start payload and the prefetch response) in
     * place of the old fixed 15. The authoritative per-nonce deadline is still
     * each nonce's own expires_at; this is just the convenience aggregate for a
     * prefetch made right now (rounded up so it never under-reports the window).
     */
    public static function offlineTtlMinutesFor(Shift $shift): int
    {
        return (int) ceil(Carbon::now()->diffInSeconds(self::offlineExpiryFor($shift)) / 60);
    }

    /**
     * Issue a batch of OFFLINE_POOL nonces for the guard to draw from while
     * offline. Returns the freshly created collection.
     */
    public function issuePool(Guard $guard, Shift $shift, int $count = self::DEFAULT_POOL_SIZE): Collection
    {
        $count = max(1, min($count, 50)); // sane upper bound
        $now = Carbon::now();
        // Span the pool nonce to the whole shift (see offlineExpiryFor) — a fixed
        // 15-min TTL dies before the first ~50-min photo mark and can't be
        // refilled offline. Single-use (markUsed) still caps each to one photo.
        $expiresAt = self::offlineExpiryFor($shift);

        return collect(range(1, $count))->map(fn () => Nonce::create([
            'guard_id' => $guard->id,
            'shift_id' => $shift->id,
            'nonce_value' => $this->generateValue(),
            'issued_at' => $now,
            'expires_at' => $expiresAt,
            'type' => Nonce::TYPE_OFFLINE_POOL,
        ]));
    }

    /**
     * Validate a nonce value for a given guard — the UNIVERSAL checks only:
     * it exists, belongs to this guard, and has not been used. Expiry is
     * deliberately NOT checked here because the window semantics differ by
     * type and must be evaluated against the photo's *capture* time, not the
     * upload time (an OFFLINE_POOL photo can be captured inside its shift-long
     * window but uploaded much later when connectivity returns). The photo
     * pipeline owns that window logic — see PhotoVerificationService.
     *
     * @return array{ok: bool, nonce: ?Nonce, reason: ?string}
     *         reason ∈ NONCE_NOT_FOUND | NONCE_ALREADY_USED
     */
    public function validate(string $nonceValue, Guard $guard): array
    {
        $nonce = Nonce::where('nonce_value', $nonceValue)
            ->where('guard_id', $guard->id)
            ->first();

        if (!$nonce) {
            return ['ok' => false, 'nonce' => null, 'reason' => 'NONCE_NOT_FOUND'];
        }

        if (!is_null($nonce->used_at)) {
            return ['ok' => false, 'nonce' => $nonce, 'reason' => 'NONCE_ALREADY_USED'];
        }

        return ['ok' => true, 'nonce' => $nonce, 'reason' => null];
    }

    /**
     * Atomically mark a nonce as used. Single-use is enforced at the DB level:
     * the UPDATE only matches while `used_at` is still NULL, so two concurrent
     * uploads racing the same nonce cannot both succeed. Returns true if this
     * call was the one that consumed it.
     */
    public function markUsed(Nonce $nonce): bool
    {
        $affected = Nonce::where('id', $nonce->id)
            ->whereNull('used_at')
            ->update(['used_at' => Carbon::now()]);

        if ($affected === 1) {
            $nonce->used_at = Carbon::now();
            return true;
        }

        return false;
    }

    /**
     * Count the guard's currently usable OFFLINE_POOL nonces for this shift —
     * used to decide whether the app should refill (refill when < 5 remain).
     */
    public function remainingPoolCount(Guard $guard, Shift $shift): int
    {
        return Nonce::where('guard_id', $guard->id)
            ->where('shift_id', $shift->id)
            ->where('type', Nonce::TYPE_OFFLINE_POOL)
            ->whereNull('used_at')
            ->where('expires_at', '>', Carbon::now())
            ->count();
    }

    /**
     * Cryptographically random, URL-safe nonce value. 64 hex chars (256 bits).
     */
    private function generateValue(): string
    {
        return bin2hex(random_bytes(32));
    }
}
