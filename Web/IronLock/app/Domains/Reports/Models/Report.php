<?php

namespace App\Domains\Reports\Models;

use App\Domains\Admins\Models\Admin;
use App\Domains\Reports\Support\ReportType;
use App\Domains\Shifts\Models\Shift;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Report — a generated compliance/reporting artifact (Phase 8).
 *
 * Mirrors the `reports` migration: a persisted PDF/CSV lives on the private
 * `reports` disk and this row records what it is, which shift it covers (if
 * any), who generated it and when. The file is served ONLY through the
 * admin-authenticated download route — `file_path` is a disk-relative path,
 * never a public URL, and is never exposed to a view.
 *
 * `generated_at` is authoritative and set explicitly in PHP (UTC) by
 * ReportService at generation time — it is never left to the column's
 * CURRENT_TIMESTAMP default, which would use the DB session timezone.
 *
 * The table has no `report_format` column, so the format is derived from the
 * stored file extension (@see format()).
 */
class Report extends Model
{
    use HasUuids;

    protected $table = 'reports';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'shift_id',
        'generated_by',
        'report_type',
        'file_path',
        'payload',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            // The frozen computed snapshot (pure arrays) the on-screen page and
            // on-demand CSV render from — captured once at generation time.
            'payload' => 'array',
            'generated_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * The shift this report covers, when it is shift-scoped (null for
     * range/roster reports).
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    /**
     * The admin who generated the report.
     */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'generated_by');
    }

    /**
     * The report type as its enum, or null for a legacy/unknown stored value.
     */
    public function type(): ?ReportType
    {
        return ReportType::tryFrom($this->report_type);
    }

    /**
     * Display label for the Previous Exports list (falls back to the raw value
     * for any unknown historical type).
     */
    public function typeLabel(): string
    {
        return $this->type()?->label() ?? $this->report_type;
    }

    /**
     * The output format, derived from the stored file extension ('pdf' | 'csv').
     */
    public function format(): string
    {
        return strtolower(pathinfo((string) $this->file_path, PATHINFO_EXTENSION)) ?: 'pdf';
    }

    /**
     * The formats this report can be downloaded as, from its type (PDF always;
     * CSV for tabular types). Drives the on-screen page's download buttons.
     *
     * @return list<string>
     */
    public function downloadFormats(): array
    {
        return $this->type()?->formats() ?? ['pdf'];
    }

    /**
     * The admin-gated URL of the on-screen report page (the "slug" page).
     */
    public function viewUrl(): string
    {
        return route('admin.reports.show', $this->id);
    }
}
