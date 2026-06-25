<?php

namespace App\Domains\Wakefulness\Support;

/**
 * Totp — a self-contained RFC 6238 (TOTP) / RFC 4226 (HOTP) implementation.
 *
 * Phase 5 originally planned to use `pragmarx/google2fa`, but that package's
 * dependency tree (via the already-installed Firebase SDK's lcobucci/jwt) pulls
 * in `ext-sodium`, which is not available on this host. TOTP is a small, fully
 * standard algorithm, so it is implemented here with zero dependencies. The
 * algorithm is identical to what the Flutter app uses to generate offline codes
 * — any RFC-6238 library on the device produces the same 4-digit code for the
 * same (seed, window).
 *
 * Contract:
 *   - secret: a Base32 string (no padding), as stored encrypted on the shift.
 *   - period: 30 seconds (config ironlock.totp_period_seconds).
 *   - digits: 4 (config ironlock.totp_digits).
 *   - window reference: floor(unix_time / period) — the integer time-step
 *     counter recorded on each check as `totp_window_reference`.
 *
 * The HMAC uses SHA-1 per RFC 6238's default, which every standard TOTP client
 * implements. SHA-1 here is a MAC primitive, not a security hash of secrets.
 */
class Totp
{
    /**
     * Generate a cryptographically-random Base32 secret (no padding).
     *
     * @param int $bytes Raw entropy bytes before Base32 encoding (default 20,
     *                   the RFC 4226 recommended HMAC-SHA1 key length).
     */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * The time-step counter (window reference) for a given unix timestamp.
     */
    public static function windowFor(int $unixTime, int $period = 30): int
    {
        return intdiv($unixTime, max(1, $period));
    }

    /**
     * The current time-step counter.
     */
    public static function currentWindow(int $period = 30): int
    {
        return self::windowFor(time(), $period);
    }

    /**
     * Compute the TOTP code for a specific window reference (time-step counter).
     * Returns a zero-padded string of `$digits` length.
     */
    public static function codeForWindow(string $base32Secret, int $window, int $digits = 4): string
    {
        $key = self::base32Decode($base32Secret);

        // 8-byte big-endian counter.
        $counter = pack('N*', 0, $window);

        $hash = hash_hmac('sha1', $counter, $key, true);

        // Dynamic truncation (RFC 4226 §5.3).
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        $otp = $binary % (10 ** $digits);

        return str_pad((string) $otp, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Constant-time verify that `$code` matches the TOTP for `$window`.
     */
    public static function verify(string $base32Secret, int $window, string $code, int $digits = 4): bool
    {
        $expected = self::codeForWindow($base32Secret, $window, $digits);

        return hash_equals($expected, str_pad($code, $digits, '0', STR_PAD_LEFT));
    }

    // ------------------------------------------------------------------
    // Base32 (RFC 4648) — no padding, uppercase alphabet.
    // ------------------------------------------------------------------

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function base32Encode(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $output .= self::ALPHABET[bindec($chunk)];
        }

        return $output;
    }

    public static function base32Decode(string $secret): string
    {
        $secret = strtoupper(trim($secret));
        if ($secret === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($secret) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                continue; // skip padding / stray chars
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
