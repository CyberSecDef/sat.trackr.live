<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

/**
 * Alpha-5 NORAD ID encoder/decoder.
 *
 * The legacy TLE format only has a 5-character slot for the catalog
 * number, which caps at 99999. CelesTrak announced that satellites
 * crossing 6 digits (sometime mid-2026) will be encoded with the
 * Alpha-5 scheme inside that same 5-char slot:
 *
 *   100000 → A0000        (A = 10)
 *   148493 → E8493        (E = 14)
 *   239999 → P9999        (P = 23 — 'I' and 'O' are skipped)
 *   339999 → Z9999        (Z = 33)
 *
 * The leading character uses A-Z minus I and O (24 letters), giving
 * digits 10-33; combined with the 4-digit tail, the encodable range
 * is 100000-339999.  Values < 100000 are emitted as plain decimal.
 *
 * Reference: https://celestrak.org/NORAD/documentation/alpha-5.php
 */
final class NoradId
{
    /** Alpha-5 alphabet: A-Z minus I (would look like 1) and O (looks like 0). */
    private const ALPHA = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    /** Decode a 5-char TLE slot into an integer (handles plain or Alpha-5). */
    public static function decode(string $slot): int
    {
        $slot = trim($slot);
        if ($slot === '') {
            throw new InvalidTleException('Empty NORAD slot');
        }
        if (ctype_digit($slot)) {
            return (int) $slot;
        }
        if (strlen($slot) !== 5) {
            throw new InvalidTleException("Alpha-5 slot must be 5 chars, got '{$slot}'");
        }
        $head = strtoupper($slot[0]);
        $tail = substr($slot, 1);
        if (!ctype_digit($tail)) {
            throw new InvalidTleException("Alpha-5 tail must be 4 digits, got '{$tail}'");
        }
        $idx = strpos(self::ALPHA, $head);
        if ($idx === false) {
            throw new InvalidTleException("Invalid Alpha-5 prefix '{$head}'");
        }
        return ($idx + 10) * 10000 + (int) $tail;
    }

    /** Encode an integer for a 5-char TLE slot (Alpha-5 above 99999). */
    public static function encode(int $norad): string
    {
        if ($norad < 0) {
            throw new InvalidTleException("Cannot encode negative NORAD {$norad}");
        }
        if ($norad < 100000) {
            return str_pad((string) $norad, 5, '0', STR_PAD_LEFT);
        }
        if ($norad > 339999) {
            throw new InvalidTleException("NORAD {$norad} exceeds Alpha-5 ceiling 339999");
        }
        $head = self::ALPHA[intdiv($norad, 10000) - 10];
        $tail = str_pad((string) ($norad % 10000), 4, '0', STR_PAD_LEFT);
        return $head . $tail;
    }
}
