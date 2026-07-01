<?php

namespace App\Domains\Reports\Support;

/**
 * The catalogue of compliance/reporting artifacts IronLock can generate
 * (Phase 8 · spec §REP + wireframe D-09). One enum case per report type; the
 * string value is what is persisted in `reports.report_type` and posted from
 * the Reports & Exports form, so these values are a stable contract — do not
 * rename an existing case without a migration for historical rows.
 *
 * `formats()` is the allow-list of output formats each type supports: every
 * report renders a PDF (the primary evidentiary artifact); tabular types also
 * offer a CSV export for spreadsheet analysis (D-09 ↓CSV).
 */
enum ReportType: string
{
    case ShiftWelfare = 'shift_welfare';         // REP-002 — one completed shift
    case ComplianceSummary = 'compliance_summary'; // REP-001 — shift or date range
    case AlertHistory = 'alert_history';         // date range · guard/site
    case SiaLicenceStatus = 'sia_licence_status'; // REP-004 — all active guards
    case ShiftAudit = 'shift_audit';             // SEC-001/003 — full event trail
    case WtrCompliance = 'wtr_compliance';       // REP-005 — guard · 17-week window

    /**
     * Human label for the Reports form dropdown and the PDF header.
     */
    public function label(): string
    {
        return match ($this) {
            self::ShiftWelfare => 'Shift Welfare Report',
            self::ComplianceSummary => 'Compliance Summary',
            self::AlertHistory => 'Alert History',
            self::SiaLicenceStatus => 'SIA Licence Status',
            self::ShiftAudit => 'Shift Audit Trail',
            self::WtrCompliance => 'Working Time Compliance',
        };
    }

    /**
     * The output formats this type may be rendered in. PDF is always first
     * (the primary artifact); a type that also lists 'csv' is exportable to a
     * spreadsheet.
     *
     * @return list<string>
     */
    public function formats(): array
    {
        // Every type renders a PDF (the primary evidentiary artifact) and a CSV
        // (a flat, spreadsheet-friendly export of the same frozen snapshot).
        return ['pdf', 'csv'];
    }

    /**
     * The Blade view that renders this type's on-screen report page (the "slug"
     * page). The shift report gets the full rich layout; the others share the
     * same visual system in a lighter, mostly-tabular form.
     */
    public function webView(): string
    {
        return 'reports.web.' . $this->value;
    }

    /**
     * The Blade view that renders this type's downloadable PDF (dompdf-friendly,
     * table-based layout).
     */
    public function pdfView(): string
    {
        return 'reports.pdf.' . $this->value;
    }

    /**
     * Whether this report is scoped to a single shift (drives which form
     * fields the Reports page requires, and whether the Shift Detail one-click
     * button can produce it).
     */
    public function isShiftScoped(): bool
    {
        return in_array($this, [self::ShiftWelfare, self::ShiftAudit], true);
    }

    public function supportsFormat(string $format): bool
    {
        return in_array($format, $this->formats(), true);
    }

    /**
     * The optional-include toggles (nonce audit trail, SHA-256 hashes) only
     * apply to the shift-scoped evidentiary reports (D-09).
     */
    public function supportsEvidenceIncludes(): bool
    {
        return in_array($this, [self::ShiftWelfare, self::ComplianceSummary, self::ShiftAudit], true);
    }

    /**
     * All cases as value => label, for building the form dropdown.
     *
     * @return array<string,string>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
