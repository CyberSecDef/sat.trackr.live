<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Parses NORAD 3-line TLE sets into ParsedTle value objects, validating
 * line lengths, mod-10 checksums, and epoch sanity. Computes derived
 * orbital elements (period, semi-major axis, perigee, apogee) from the
 * mean motion + eccentricity using standard Kepler formulas.
 *
 * Reference: https://celestrak.org/columns/v04n03/
 */
final class TleParser
{
    /** Earth gravitational parameter (km^3/s^2) */
    private const MU = 398600.4418;

    /** Earth equatorial radius (km) — used as the reference altitude */
    private const R_EARTH_KM = 6378.137;

    /** Seconds per solar day */
    private const SECONDS_PER_DAY = 86400.0;

    public function parse(string $name, string $line1, string $line2): ParsedTle
    {
        $name = trim($name);
        $line1 = rtrim($line1);
        $line2 = rtrim($line2);

        $this->validateStructure($line1, $line2);
        $this->validateChecksum($line1);
        $this->validateChecksum($line2);

        $noradId1 = (int) trim(substr($line1, 2, 5));
        $noradId2 = (int) trim(substr($line2, 2, 5));
        if ($noradId1 !== $noradId2) {
            throw new InvalidTleException(
                "NORAD ID mismatch between lines: {$noradId1} vs {$noradId2}"
            );
        }

        $classification = substr($line1, 7, 1);
        $intlDesignator = $this->parseIntlDesignator(substr($line1, 9, 8));
        $epochIso = $this->parseEpoch(substr($line1, 18, 14));
        $meanMotionDot = (float) trim(substr($line1, 33, 10));
        $meanMotionDdot = $this->parseAssumedExponent(substr($line1, 44, 8));
        $bstar = $this->parseAssumedExponent(substr($line1, 53, 8));

        $inclinationDeg = (float) trim(substr($line2, 8, 8));
        $raanDeg = (float) trim(substr($line2, 17, 8));
        $eccentricity = (float) ('0.' . trim(substr($line2, 26, 7)));
        $argPerigeeDeg = (float) trim(substr($line2, 34, 8));
        $meanAnomalyDeg = (float) trim(substr($line2, 43, 8));
        $meanMotion = (float) trim(substr($line2, 52, 11));
        $revNumber = (int) trim(substr($line2, 63, 5));

        if ($meanMotion <= 0.0) {
            throw new InvalidTleException("Non-positive mean motion: {$meanMotion}");
        }
        if ($eccentricity < 0.0 || $eccentricity >= 1.0) {
            throw new InvalidTleException(
                "Eccentricity out of range [0, 1): {$eccentricity}"
            );
        }

        // Derived orbital elements
        $angularVelocityRadPerSec = $meanMotion * 2.0 * M_PI / self::SECONDS_PER_DAY;
        $semimajorKm = (self::MU / ($angularVelocityRadPerSec ** 2)) ** (1.0 / 3.0);
        $periodMin = 1440.0 / $meanMotion;
        $perigeeKm = $semimajorKm * (1.0 - $eccentricity) - self::R_EARTH_KM;
        $apogeeKm = $semimajorKm * (1.0 + $eccentricity) - self::R_EARTH_KM;

        return new ParsedTle(
            noradId:        $noradId1,
            name:           $name,
            intlDesignator: $intlDesignator,
            classification: $classification,
            epochIso:       $epochIso,
            meanMotion:     $meanMotion,
            meanMotionDot:  $meanMotionDot,
            meanMotionDdot: $meanMotionDdot,
            bstar:          $bstar,
            eccentricity:   $eccentricity,
            inclinationDeg: $inclinationDeg,
            raanDeg:        $raanDeg,
            argPerigeeDeg:  $argPerigeeDeg,
            meanAnomalyDeg: $meanAnomalyDeg,
            revNumber:      $revNumber,
            line1:          $line1,
            line2:          $line2,
            periodMin:      $periodMin,
            perigeeKm:      $perigeeKm,
            apogeeKm:       $apogeeKm,
            semimajorKm:    $semimajorKm,
        );
    }

    private function validateStructure(string $line1, string $line2): void
    {
        if (strlen($line1) !== 69) {
            throw new InvalidTleException(
                'Line 1 must be 69 characters; got ' . strlen($line1)
            );
        }
        if (strlen($line2) !== 69) {
            throw new InvalidTleException(
                'Line 2 must be 69 characters; got ' . strlen($line2)
            );
        }
        if ($line1[0] !== '1') {
            throw new InvalidTleException("Line 1 must start with '1', got '{$line1[0]}'");
        }
        if ($line2[0] !== '2') {
            throw new InvalidTleException("Line 2 must start with '2', got '{$line2[0]}'");
        }
    }

    private function validateChecksum(string $line): void
    {
        $expected = (int) $line[68];
        $sum = 0;
        for ($i = 0; $i < 68; $i++) {
            $c = $line[$i];
            if ($c >= '0' && $c <= '9') {
                $sum += (int) $c;
            } elseif ($c === '-') {
                $sum += 1;
            }
            // letters, spaces, '.', '+' all ignored per spec
        }
        $computed = $sum % 10;
        if ($computed !== $expected) {
            throw new InvalidTleException(
                "Checksum failure on line '{$line}': computed {$computed}, expected {$expected}"
            );
        }
    }

    /**
     * "98067A  " → "1998-067A" ; "21001AAA" → "2021-001AAA".
     * Year inference: 57-99 → 1957-1999, 00-56 → 2000-2056.
     */
    private function parseIntlDesignator(string $field): string
    {
        $field = trim($field);
        if ($field === '') {
            return '';
        }
        $yearTwo = (int) substr($field, 0, 2);
        $launchNum = substr($field, 2, 3);
        $piece = trim(substr($field, 5));
        $year = $yearTwo >= 57 ? 1900 + $yearTwo : 2000 + $yearTwo;
        return sprintf('%04d-%s%s', $year, $launchNum, $piece);
    }

    /**
     * "24015.52427789" → "2024-01-15T12:34:57.529Z".
     */
    private function parseEpoch(string $field): string
    {
        $field = trim($field);
        if (!preg_match('/^(\d{2})(\d{3}\.\d+)$/', $field, $m)) {
            throw new InvalidTleException("Cannot parse epoch field '{$field}'");
        }
        $yearTwo = (int) $m[1];
        $dayFraction = (float) $m[2];
        $year = $yearTwo >= 57 ? 1900 + $yearTwo : 2000 + $yearTwo;

        $dayOfYear = (int) floor($dayFraction); // 1-based
        $fractionOfDay = $dayFraction - $dayOfYear;
        $secondsIntoDay = $fractionOfDay * self::SECONDS_PER_DAY;

        $base = (new DateTimeImmutable("{$year}-01-01T00:00:00", new DateTimeZone('UTC')))
            ->modify('+' . ($dayOfYear - 1) . ' days');

        $wholeSeconds = (int) floor($secondsIntoDay);
        $microseconds = (int) round(($secondsIntoDay - $wholeSeconds) * 1_000_000);
        if ($microseconds === 1_000_000) {
            $wholeSeconds += 1;
            $microseconds = 0;
        }

        $base = $base->modify("+{$wholeSeconds} seconds");
        $iso = $base->format('Y-m-d\TH:i:s');
        return sprintf('%s.%06dZ', $iso, $microseconds);
    }

    /**
     * "12345-3" → 0.12345e-3 ; " 76250-3" → 0.76250e-3 ; "-12345-6" → -0.12345e-6.
     * Format: [sign][5-digit mantissa][exp sign][1-digit exponent].
     */
    private function parseAssumedExponent(string $field): float
    {
        $field = trim($field);
        if ($field === '' || $field === '00000-0' || $field === '00000+0') {
            return 0.0;
        }
        if (!preg_match('/^([+-]?)(\d{5})([+-])(\d)$/', $field, $m)) {
            // Some sources emit "+00000+0" which we want to treat as zero too
            return 0.0;
        }
        $mantissaSign = $m[1] === '-' ? -1.0 : 1.0;
        $mantissa = (float) ('0.' . $m[2]);
        $expSign = $m[3] === '-' ? -1 : 1;
        $exponent = $expSign * (int) $m[4];
        return $mantissaSign * $mantissa * (10 ** $exponent);
    }
}
