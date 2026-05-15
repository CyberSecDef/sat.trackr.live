<?php

declare(strict_types=1);

namespace SatTrackr\Services;

/**
 * Tiny seam for swapping the Node-shelling PassCalculator for a fake
 * in tests.  Production wiring binds this to {@see PassCalculator}.
 */
interface PassCalculatorInterface
{
    /**
     * @param array{line1: string, line2: string} $tle
     * @param array{latitude: float, longitude: float, altitudeMeters: float} $observer
     * @return array{computed_at: string, count: int, passes: list<array<string, mixed>>}
     */
    public function compute(
        array $tle,
        array $observer,
        int $startMs,
        int $days = 7,
        float $minElevationDeg = 10.0,
        int $stepSeconds = 60,
    ): array;
}
