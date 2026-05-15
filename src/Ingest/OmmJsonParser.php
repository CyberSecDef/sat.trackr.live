<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

/**
 * Parses one CelesTrak OMM JSON record into a {@see ParsedTle}.
 *
 * OMM is the modern CCSDS replacement for the legacy 3-line TLE
 * format.  CelesTrak's GP endpoint serves it via `FORMAT=JSON`; once
 * NORAD IDs cross 6 digits (~mid-2026) the legacy TLE format will
 * also need Alpha-5 encoding in the same response, so most clients
 * will end up parsing JSON.
 *
 * The output ParsedTle includes synthesized line1/line2 strings via
 * {@see TleEmitter}, so downstream code (satellite.js in the browser,
 * the /text/satellite raw-data block, copy-to-clipboard) keeps working
 * unchanged.
 *
 * Sample record:
 *   {
 *     "OBJECT_NAME": "ISS (ZARYA)",
 *     "OBJECT_ID":   "1998-067A",
 *     "EPOCH":       "2026-05-14T04:45:57.957408",
 *     "MEAN_MOTION": 15.49211692,
 *     "ECCENTRICITY": 0.00075358,
 *     "INCLINATION": 51.6312,
 *     "RA_OF_ASC_NODE": 108.3512,
 *     "ARG_OF_PERICENTER": 56.9254,
 *     "MEAN_ANOMALY": 303.2457,
 *     "EPHEMERIS_TYPE": 0,
 *     "CLASSIFICATION_TYPE": "U",
 *     "NORAD_CAT_ID": 25544,
 *     "ELEMENT_SET_NO": 999,
 *     "REV_AT_EPOCH": 56648,
 *     "BSTAR": 0.00010032304,
 *     "MEAN_MOTION_DOT": 5.122e-05,
 *     "MEAN_MOTION_DDOT": 0
 *   }
 */
final class OmmJsonParser
{
    /** Earth gravitational parameter (km^3/s^2) — same constants as TleParser. */
    private const MU = 398600.4418;
    private const R_EARTH_KM = 6378.137;
    private const SECONDS_PER_DAY = 86400.0;

    /**
     * @param array<string, mixed> $record
     */
    public function parse(array $record): ParsedTle
    {
        $norad = self::requireInt($record, 'NORAD_CAT_ID');
        $name  = self::requireString($record, 'OBJECT_NAME');
        $intl  = self::requireString($record, 'OBJECT_ID');
        $cls   = trim((string) ($record['CLASSIFICATION_TYPE'] ?? 'U'));
        if ($cls === '') {
            $cls = 'U';
        }
        $epochIso = self::normalizeEpoch(self::requireString($record, 'EPOCH'));

        $meanMotion     = self::requireFloat($record, 'MEAN_MOTION');
        $eccentricity   = self::requireFloat($record, 'ECCENTRICITY');
        $inclinationDeg = self::requireFloat($record, 'INCLINATION');
        $raanDeg        = self::requireFloat($record, 'RA_OF_ASC_NODE');
        $argPerigeeDeg  = self::requireFloat($record, 'ARG_OF_PERICENTER');
        $meanAnomalyDeg = self::requireFloat($record, 'MEAN_ANOMALY');

        $meanMotionDot  = self::optionalFloat($record, 'MEAN_MOTION_DOT', 0.0);
        $meanMotionDdot = self::optionalFloat($record, 'MEAN_MOTION_DDOT', 0.0);
        $bstar          = self::optionalFloat($record, 'BSTAR', 0.0);
        $revNumber      = (int) ($record['REV_AT_EPOCH'] ?? 0);
        $elementSetNo   = (int) ($record['ELEMENT_SET_NO'] ?? 999);

        if ($meanMotion <= 0.0) {
            throw new InvalidTleException("Non-positive mean motion: {$meanMotion}");
        }
        if ($eccentricity < 0.0 || $eccentricity >= 1.0) {
            throw new InvalidTleException("Eccentricity out of range [0, 1): {$eccentricity}");
        }

        [$line1, $line2] = TleEmitter::format(
            norad:           $norad,
            intlDesignator:  $intl,
            classification:  $cls,
            epochIso:        $epochIso,
            meanMotion:      $meanMotion,
            meanMotionDot:   $meanMotionDot,
            meanMotionDdot:  $meanMotionDdot,
            bstar:           $bstar,
            eccentricity:    $eccentricity,
            inclinationDeg:  $inclinationDeg,
            raanDeg:         $raanDeg,
            argPerigeeDeg:   $argPerigeeDeg,
            meanAnomalyDeg:  $meanAnomalyDeg,
            revNumber:       $revNumber,
            elementSetNo:    $elementSetNo,
        );

        // Derived elements — same formulas as TleParser.
        $angVel      = $meanMotion * 2.0 * M_PI / self::SECONDS_PER_DAY;
        $semimajorKm = (self::MU / ($angVel ** 2)) ** (1.0 / 3.0);
        $periodMin   = 1440.0 / $meanMotion;
        $perigeeKm   = $semimajorKm * (1.0 - $eccentricity) - self::R_EARTH_KM;
        $apogeeKm    = $semimajorKm * (1.0 + $eccentricity) - self::R_EARTH_KM;

        return new ParsedTle(
            noradId:        $norad,
            name:           $name,
            intlDesignator: $intl,
            classification: $cls,
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

    /**
     * CelesTrak emits "2026-05-14T04:45:57.957408" (no Z, fractional seconds).
     * Normalize to the same shape TleParser produces ("...Z" suffix).
     */
    private static function normalizeEpoch(string $epoch): string
    {
        $epoch = trim($epoch);
        if ($epoch === '') {
            throw new InvalidTleException('Empty EPOCH');
        }
        if (str_ends_with($epoch, 'Z')) {
            return $epoch;
        }
        // Pad to microsecond precision for parity with TleParser output.
        if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(\.\d+)?$/', $epoch, $m) === 1) {
            $frac = $m[2] ?? '';
            $frac = $frac === '' ? '.000000' : str_pad($frac, 7, '0');
            return $m[1] . $frac . 'Z';
        }
        return $epoch . 'Z';
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function requireString(array $record, string $key): string
    {
        if (!isset($record[$key])) {
            throw new InvalidTleException("OMM record missing '{$key}'");
        }
        $v = $record[$key];
        if (!is_string($v) && !is_int($v)) {
            throw new InvalidTleException("OMM '{$key}' is not a string");
        }
        $v = trim((string) $v);
        if ($v === '') {
            throw new InvalidTleException("OMM '{$key}' is blank");
        }
        return $v;
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function requireInt(array $record, string $key): int
    {
        if (!isset($record[$key])) {
            throw new InvalidTleException("OMM record missing '{$key}'");
        }
        $v = $record[$key];
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && preg_match('/^-?\d+$/', trim($v)) === 1) {
            return (int) trim($v);
        }
        throw new InvalidTleException("OMM '{$key}' is not an int (got " . gettype($v) . ')');
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function requireFloat(array $record, string $key): float
    {
        if (!isset($record[$key])) {
            throw new InvalidTleException("OMM record missing '{$key}'");
        }
        $v = $record[$key];
        if (is_float($v) || is_int($v)) {
            return (float) $v;
        }
        if (is_string($v) && is_numeric(trim($v))) {
            return (float) trim($v);
        }
        throw new InvalidTleException("OMM '{$key}' is not numeric (got " . gettype($v) . ')');
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function optionalFloat(array $record, string $key, float $default): float
    {
        if (!isset($record[$key]) || $record[$key] === null) {
            return $default;
        }
        return self::requireFloat($record, $key);
    }
}
