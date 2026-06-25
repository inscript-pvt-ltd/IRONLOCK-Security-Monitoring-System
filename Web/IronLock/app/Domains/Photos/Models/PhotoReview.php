<?php

namespace App\Domains\Photos\Models;

use App\Domains\Admins\Models\Admin;
use App\Domains\Guards\Models\Guard;
use App\Domains\Shifts\Models\Shift;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PhotoReview — an admin's manual decision on a stored verification photo.
 *
 * One review per PhotoEvidence (enforced by a UNIQUE index). Created once and
 * treated idempotently like an alert acknowledgement so the evidence row stays
 * immutable. The decision (APPROVED / REJECTED) flows into the shift compliance
 * summary and is pushed/polled back to the guard's app.
 */
class PhotoReview extends Model
{
    use HasUuids;

    // decision values.
    public const DECISION_APPROVED = 'APPROVED';
    public const DECISION_REJECTED = 'REJECTED';

    protected $table = 'photo_reviews';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'photo_evidence_id',
        'photo_request_id',
        'shift_id',
        'guard_id',
        'reviewed_by',
        'decision',
        'note',
        'reviewed_at',
        'server_received_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'server_received_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(PhotoEvidence::class, 'photo_evidence_id');
    }

    public function photoRequest(): BelongsTo
    {
        return $this->belongsTo(PhotoRequest::class, 'photo_request_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    public function assignedGuard(): BelongsTo
    {
        return $this->belongsTo(Guard::class, 'guard_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by');
    }
}
