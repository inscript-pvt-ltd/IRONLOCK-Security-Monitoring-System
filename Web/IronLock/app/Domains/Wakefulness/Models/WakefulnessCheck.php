<?php

namespace App\Domains\Wakefulness\Models;

use App\Domains\Guards\Models\Guard;
use App\Domains\Shifts\Models\Shift;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WakefulnessCheck — one code-challenge issued to a guard (spec §9).
 *
 * Fillable/casts mirror the `wakefulness_checks` migration exactly:
 *   challenge_code         the 4-digit code the guard must transcribe (random
 *                          for ONLINE; the TOTP code for the window for OFFLINE)
 *   submitted_code         what the guard actually typed
 *   totp_window_reference  floor(unix_time / 30) — null for ONLINE checks
 *   scheduled_at           when the check was dispatched / is due (server clock)
 *   delivered_at           when the app confirmed it received the ONLINE push
 *                          (null = never confirmed → push-delivery failure, the
 *                          sweep suppresses the alert rather than crying wolf)
 *   responded_at           device-reported response time (audit only)
 *   server_received_at     authoritative receipt time (the pass/fail clock)
 *   response_time_seconds  recorded for audit; NOT the pass/fail authority
 *   result                 CONFIRMED | FAILED | null (null = PENDING)
 *   is_offline             true when answered from a TOTP/offline challenge
 *   online_or_offline      ONLINE | OFFLINE (the challenge mode)
 *
 * A null `result` is the PENDING state: the check has been dispatched and is
 * awaiting a response or the timeout sweep.
 */
class WakefulnessCheck extends Model
{
    use HasUuids;

    public const RESULT_CONFIRMED = 'CONFIRMED';
    public const RESULT_FAILED = 'FAILED';

    public const MODE_ONLINE = 'ONLINE';
    public const MODE_OFFLINE = 'OFFLINE';

    // How the challenge was triggered (mirrors PhotoRequest::TYPE_*).
    public const TYPE_MANUAL = 'manual';       // a supervisor raised it from the dashboard
    public const TYPE_SCHEDULED = 'scheduled'; // the randomised auto-dispatcher raised it

    /**
     * The table associated with the model.
     */
    protected $table = 'wakefulness_checks';

    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'id';

    /**
     * The "type" of the primary key ID.
     */
    protected $keyType = 'string';

    /**
     * Indicates if the model's ID is auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'shift_id',
        'guard_id',
        'challenge_code',
        'submitted_code',
        'totp_window_reference',
        'scheduled_at',
        'delivered_at',
        'responded_at',
        'response_time_seconds',
        'server_received_at',
        'result',
        'is_offline',
        'online_or_offline',
        'request_type',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'totp_window_reference' => 'integer',
            'response_time_seconds' => 'decimal:2',
            'scheduled_at' => 'datetime',
            'delivered_at' => 'datetime',
            'responded_at' => 'datetime',
            'server_received_at' => 'datetime',
            'is_offline' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Whether the check is still awaiting a response (no result recorded yet).
     */
    public function isPending(): bool
    {
        return $this->result === null;
    }

    /**
     * The shift this check belongs to.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    /**
     * The guard this check was issued to.
     *
     * Named `assignedGuard` (not `guard`) to avoid colliding with Eloquent's
     * base Model::guard() mass-assignment method, mirroring Shift::assignedGuard().
     */
    public function assignedGuard(): BelongsTo
    {
        return $this->belongsTo(Guard::class, 'guard_id');
    }
}
