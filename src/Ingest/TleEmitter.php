<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

/**
 * Builds the two 69-character TLE lines from an already-parsed set of
 * mean-element fields.  Used when ingesting via OMM JSON (chunk 7B):
 * the rest of the system — `tle_current` storage, the SPA worker via
 * satellite.js, the `/text/satellite/{norad}` raw-data section —
 * still expects byte-perfect TLE strings, so the OMM ingest path
 * synthesizes them instead of forking storage.
 *
 * Reference column layout: https://celestrak.org/columns/v04n03/
 */
final class TleEmitter
{
    /**
     * @return array{0: string, 1: string}
     */
    public static function format(
        int $norad,
        string $intlDesignator,
        string $classification,
        string $epochIso,
        float $meanMotion,
        float $meanMotionDot,
        float $meanMotionDdot,
        float $bstar,
        float $eccentricity,
        float $inclinationDeg,
        float $raanDeg,
        float $argPerigeeDeg,
        float $meanAnomalyDeg,
        int $revNumber,
        int $elementSetNo = 999,
    ): array {
        $noradSlot = NoradId::encode($norad);
        $cls       = $classification === '' ? 'U' : substr($classification, 0, 1);
        $intlSlot  = self::formatIntlDesignator($intlDesignator);
        $epochSlot = self::formatEpoch($epochIso);

        $mmDotSlot   = self::formatMeanMotionDot($meanMotionDot);
        $mmDdotSlot  = self::formatAssumedExponent($meanMotionDdot);
        $bstarSlot   = self::formatAssumedExponent($bstar);
        $elsetSlot   = str_pad((string) ($elementSetNo % 10000), 4, ' ', STR_PAD_LEFT);

        $line1 = sprintf(
            '1 %s%s %s %s %s %s %s 0 %s',   // col 0..67, checksum appended below
            $noradSlot,                       // 2-6
            $cls,                             // 7
            $intlSlot,                        // 9-16
            $epochSlot,                       // 18-31
            $mmDotSlot,                       // 33-42
            $mmDdotSlot,                      // 44-51
            $bstarSlot,                       // 53-60
            $elsetSlot,                       // 64-67
        );
        // Force exactly 68 chars (sprintf can drop trailing space when an arg is short).
        $line1 = str_pad(substr($line1, 0, 68), 68);

        // Eccentricity: 7 implied-decimal digits ("0.0007535" → "0007535").
        $eccDigits = str_pad(substr(sprintf('%.7f', $eccentricity), 2, 7), 7, '0');
        $mmSlot    = self::formatMeanMotionAtEpoch($meanMotion);

        $line2 = sprintf(
            '2 %s %s %s %s %s %s %s%s',
            $noradSlot,                                                       // 2-6
            str_pad(number_format($inclinationDeg, 4, '.', ''),  8, ' ', STR_PAD_LEFT),
            str_pad(number_format($raanDeg, 4, '.', ''),         8, ' ', STR_PAD_LEFT),
            $eccDigits,
            str_pad(number_format($argPerigeeDeg, 4, '.', ''),   8, ' ', STR_PAD_LEFT),
            str_pad(number_format($meanAnomalyDeg, 4, '.', ''),  8, ' ', STR_PAD_LEFT),
            $mmSlot,
            str_pad((string) ($revNumber % 100000), 5, ' ', STR_PAD_LEFT),
        );
        $line2 = str_pad(substr($line2, 0, 68), 68);

        return [
            $line1 . self::checksum($line1),
            $line2 . self::checksum($line2),
        ];
    }

    private static function formatIntlDesignator(string $intl): string
    {
        // "1998-067A" → "98067A  " (8 chars, space-padded right)
        if ($intl === '') {
            return '        ';
        }
        if (preg_match('/^(\d{4})-(\d{3})([A-Za-z ]*)$/', $intl, $m) === 1) {
            $year = (int) $m[1];
            $yy   = $year % 100;
            $piece = str_pad(rtrim($m[3]), 3, ' ');
            return sprintf('%02d%s%s', $yy, $m[2], $piece);
        }
        return str_pad(substr($intl, 0, 8), 8);
    }

    private static function formatEpoch(string $iso): string
    {
        // "2026-05-14T04:45:57.957408Z" → "26134.19858747"
        $dt = new \DateTimeImmutable($iso);
        $year = (int) $dt->format('Y');
        $yy = $year % 100;
        $startOfYear = new \DateTimeImmutable("{$year}-01-01T00:00:00Z");
        $secondsIntoYear = (float) $dt->format('U.u') - (float) $startOfYear->format('U.u');
        $dayFraction = 1 + $secondsIntoYear / 86400.0;        // DDD.FFFFFFFF (1-based)
        return sprintf('%02d%012.8f', $yy, $dayFraction);
    }

    /**
     * "5.122e-05" -> " .00005122" (10-char column, leading space if positive).
     * We never have | value | >= 1.0 here.
     */
    private static function formatMeanMotionDot(float $value): string
    {
        $sign = $value < 0 ? '-' : ' ';
        $abs  = abs($value);
        $digits = str_pad(substr(sprintf('%.8f', $abs), 2, 8), 8, '0');
        return $sign . '.' . $digits;
    }

    /**
     * 1.234e-3 -> "12340-3" -> "+12340-3" (8-char assumed-exponent column).
     * Zero (or near-zero) is encoded as " 00000+0".
     */
    private static function formatAssumedExponent(float $value): string
    {
        if ($value === 0.0) {
            return ' 00000+0';
        }
        $sign = $value < 0 ? '-' : ' ';
        $abs  = abs($value);
        $exp  = (int) floor(log10($abs)) + 1;             // shift so mantissa ∈ [0.1, 1)
        $mantissa = $abs / (10 ** $exp);
        $mDigits = str_pad(substr(sprintf('%.5f', $mantissa), 2, 5), 5, '0');
        $expSign = $exp < 0 ? '-' : '+';
        return $sign . $mDigits . $expSign . abs($exp);
    }

    private static function formatMeanMotionAtEpoch(float $value): string
    {
        // 11-char column, NN.NNNNNNNN (8 fractional digits, leading space if < 10).
        return str_pad(number_format($value, 8, '.', ''), 11, ' ', STR_PAD_LEFT);
    }

    private static function checksum(string $line68): string
    {
        $sum = 0;
        for ($i = 0; $i < 68; $i++) {
            $c = $line68[$i];
            if ($c >= '0' && $c <= '9') {
                $sum += (int) $c;
            } elseif ($c === '-') {
                $sum += 1;
            }
        }
        return (string) ($sum % 10);
    }
}
