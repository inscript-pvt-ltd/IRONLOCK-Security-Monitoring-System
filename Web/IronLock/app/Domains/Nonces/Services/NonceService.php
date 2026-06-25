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
 *   OFFLINE_POOL — pre-fetched batch (10–20) for offline capture, 15-min expiry.
 *
 * Every timestamp here is server-assigned (UTC) and set explicitly in PHP so it
 * never falls back to a DB CURRENT_TIMESTAMP default (which uses the DB session
 * timezone, not UTC) — see the db-session-tz memo.
 */
class NonceService
{
    /** Offline-pool nonce lifetime in minutes (spec §16.4 #12). */
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
     * Issue a batch of OFFLINE_POOL nonces for the guard to draw from while
     * offline. Returns the freshly created collection.
     */
    public function issuePool(Guard $guard, Shift $shift, int $count = self::DEFAULT_POOL_SIZE): Collection
    {
        $count = max(1, min($count, 50)); // sane upper bound
        $now = Carbon::now();
        $expiresAt = $now->copy()->addMinutes(self::OFFLINE_TTL_MINUTES);

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
     * upload time (an OFFLINE_POOL photo can be captured inside its 15-min
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
