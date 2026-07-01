<?php

namespace App\Domains\Reports\Builders;

use App\Domains\Reports\Support\ReportType;

/**
 * Base for the six report builders (Phase 8). A builder turns a validated
 * parameter set into a fully-structured, server-sourced payload; it never
 * renders or persists anything (that is ReportService's job) and never mutates
 * the audit trail (read-only).
 *
 * build() returns a PreparedReport array:
 *   - title      string  human title for the PDF header / filename
 *   - view       string  Blade view rendered to PDF
 *   - view_data  array   data for that view
 *   - csv        ?array  ['headers' => list, 'rows' => iterable] for CSV export,
 *                        or null for PDF-only types
 *   - shift_id   ?string the shift this report covers (persisted on the row),
 *                        or null for range/roster reports
 *   - slug       string  filename stem, e.g. "shift-welfare-SH-1001"
 */
abstract class ReportBuilder
{
    abstract public function type(): ReportType;

    /**
     * @param  array<string,mixed>  $params
     * @return array{title:string,view:string,view_data:array,csv:?array,shift_id:?string,slug:string}
     */
    abstract public function build(array $params): array;

    /**
     * A filesystem-safe slug fragment built from an arbitrary label.
     */
    protected function slugify(string $value): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?? '';

        return trim(strtolower($slug), '-') ?: 'report';
    }
}
