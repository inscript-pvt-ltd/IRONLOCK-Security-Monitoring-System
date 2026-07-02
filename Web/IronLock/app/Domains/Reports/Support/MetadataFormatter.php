<?php

namespace App\Domains\Reports\Support;

/**
 * Turns an event's raw metadata array into a short, human-readable one-line
 * summary for the Shift Audit "Detail" column (Phase 8).
 *
 * This is presentation only — it never mutates the stored audit metadata (the
 * CSV export still carries the exact JSON for machine/forensic use). It renders
 * snake_case keys as words and formats common value shapes (booleans, *_seconds
 * durations, coordinate/scalar lists) so a non-technical client can read the
 * trail without seeing a JSON blob.
 */
class MetadataFormatter
{
    /**
     * A "Key: value · Key: value" summary of an event's metadata, or "—" when
     * there is nothing to show.
     *
     * @param array<string,mixed> $meta
     */
    public static function humanize(array $meta): string
    {
        $parts = [];

        foreach ($meta as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            // "gap_seconds: 63" reads better as "Gap: 1m 3s".
            if (is_numeric($value) && str_ends_with((string) $key, '_seconds')) {
                $parts[] = self::label(substr((string) $key, 0, -8)) . ': ' . self::seconds((int) round((float) $value));
                continue;
            }

            $parts[] = self::label((string) $key) . ': ' . self::value($value);
        }

        return $parts ? implode(' · ', $parts) : '—';
    }

    /** Acronyms that should stay upper-cased in a humanised label. */
    private const ACRONYMS = ['gps' => 'GPS', 'ntp' => 'NTP', 'hmac' => 'HMAC', 'sha256' => 'SHA-256', 'id' => 'ID', 'ip' => 'IP', 'os' => 'OS', 'sia' => 'SIA', 'wtr' => 'WTR'];

    /** snake_case / camelCase key → "Title Case" words (acronyms preserved). */
    private static function label(string $key): string
    {
        $words = array_map(
            static fn (string $w) => self::ACRONYMS[strtolower($w)] ?? ucfirst($w),
            preg_split('/[\s_]+/', trim($key)) ?: []
        );

        return implode(' ', $words);
    }

    private static function value(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            // A plain list (e.g. coordinates) → comma-joined; an associative
            // array → recurse into the same readable form.
            if (array_is_list($value)) {
                return implode(', ', array_map(
                    static fn ($v) => is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES),
                    $value
                ));
            }

            return self::humanize($value);
        }

        return (string) $value;
    }

    /** Seconds → "Xm Ys" (or "Ys"). */
    private static function seconds(int $s): string
    {
        if ($s <= 0) {
            return '0s';
        }

        $m = intdiv($s, 60);
        $sec = $s % 60;

        return $m > 0 ? "{$m}m {$sec}s" : "{$sec}s";
    }
}
