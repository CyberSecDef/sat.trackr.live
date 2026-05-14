<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

/**
 * Immutable value object holding everything we extract from a 3-line TLE
 * set, plus the derived orbital metrics we store alongside it.
 */
final readonly class ParsedTle
{
    public function __construct(
        public int $noradId,
        public string $name,
        public string $intlDesignator, // YYYY-NNNAAA, e.g. "1998-067A"
        public string $classification, // single char, usually "U"
        public string $epochIso,        // ISO-8601 UTC with fractional seconds
        public float $meanMotion,       // revs/day
        public float $meanMotionDot,
        public float $meanMotionDdot,
        public float $bstar,
        public float $eccentricity,
        public float $inclinationDeg,
        public float $raanDeg,
        public float $argPerigeeDeg,
        public float $meanAnomalyDeg,
        public int $revNumber,
        public string $line1,           // raw 69-char line
        public string $line2,           // raw 69-char line
        // Derived
        public float $periodMin,
        public float $perigeeKm,
        public float $apogeeKm,
        public float $semimajorKm,
    ) {
    }
}
