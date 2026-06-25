<?php

namespace App\Domains\Photos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * PhotoEvidence — immutable record of a stored photo and its verification result.
 *
 * Append-only (spec §12.4): rows are written once when a photo is accepted and
 * never mutated. The fillable mirrors the canonical `photo_evidences` schema
 * exactly — anomaly outcomes live in `flags` (JSON) and any extra capture
 * context (device-claimed time, EXIF, NTP, upload delay, filename) lives in
 * `metadata` (JSON); there is intentionally no `verification_status` column.
 */
class PhotoEvidence extends Model
{
    use HasUuids;

    // Anomaly flags written into the `flags` JSON column (spec §23.3).
    public const FLAG_DELAYED_UPLOAD = 'DELAYED_UPLOAD';
    public const FLAG_CLOCK_MANIPULATION = 'CLOCK_MANIPULATION_SUSPECTED';
    public const FLAG_TIMELINE_ANOMALY = 'TIMELINE_ANOMALY';
    public const FLAG_NTP_UNAVAILABLE = 'NTP_UNAVAILABLE';

    /**
     * The table associated with the model.
     */
    protected $table = 'photo_evidences';

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
     * The attributes that are mass assignable — the REAL columns only.
     */
    protected $fillable = [
        'photo_request_id',
        'file_path',
        'sha256_hash',
        'captured_at',
        'ntp_timestamp_at_capture',
        'exif_timestamp',
        'gps_latitude',
        'gps_longitude',
        'metadata',
        'flags',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'gps_latitude' => 'decimal:8',
            'gps_longitude' => 'decimal:8',
            'captured_at' => 'datetime',
            'ntp_timestamp_at_capture' => 'datetime',
            'exif_timestamp' => 'datetime',
            'metadata' => 'array',
            'flags' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * The photo request this evidence fulfils.
     */
    public function photoRequest(): BelongsTo
    {
        return $this->belongsTo(PhotoRequest::class, 'photo_request_id');
    }

    /**
     * The admin's manual review of this photo, if one has been recorded. One
     * review per evidence (UNIQUE), so a hasOne is sufficient.
     */
    public function review(): HasOne
    {
        return $this->hasOne(PhotoReview::class, 'photo_evidence_id');
    }

    /**
     * Whether an admin has manually reviewed this photo.
     */
    public function isReviewed(): bool
    {
        return $this->review !== null;
    }

    /**
     * Derived verification status — there is no stored column. A photo that
     * carried a TIMELINE_ANOMALY is never stored as evidence (the request is
     * marked ANOMALY instead), so an evidence row is either VALIDATED (no
     * flags) or FLAGGED (carries one or more secondary anomaly flags).
     */
    public function status(): string
    {
        return empty($this->flags) ? 'VALIDATED' : 'FLAGGED';
    }
}
