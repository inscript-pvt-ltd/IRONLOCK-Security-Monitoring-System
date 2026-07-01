<?php

namespace App\Domains\Reports\Support;

/**
 * Minimal CSV serialiser for tabular report exports (D-09 ↓CSV).
 *
 * Emits a UTF-8 BOM so Excel opens the file in the correct encoding, and uses
 * fputcsv so any value containing a comma, quote or newline is quoted/escaped
 * correctly — a report cell must never be able to break the row structure.
 */
class CsvWriter
{
    /**
     * @param list<string>        $headers column headings
     * @param iterable<array>     $rows    each an ordered list of cell values
     */
    public static function toString(array $headers, iterable $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        // Pass the escape argument explicitly ('' = no legacy backslash escaping,
        // RFC-4180 clean output that Excel reads correctly). Required on PHP 8.4+
        // where omitting it is deprecated.
        fputcsv($handle, $headers, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn ($v) => $v ?? '', $row), ',', '"', '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        // UTF-8 BOM for spreadsheet apps.
        return "\xEF\xBB\xBF" . $csv;
    }
}
