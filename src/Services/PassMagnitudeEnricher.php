<?php

declare(strict_types=1);

namespace SatTrackr\Services;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Phase 4 chunk 7B — best-effort visual-magnitude enrichment.
 *
 * After {@see PassCalculator} computes the geometric pass list via the
 * Node SGP4 subprocess, the controller hands the result to this service
 * along with the satellite + observer.  We call N2YO's visualpasses
 * endpoint once per (NORAD, observer, day) and merge `mag` per pass by
 * closest peak-time match.
 *
 * N2YO sometimes returns a smaller subset (passes below minimum
 * visibility are dropped); unmatched passes keep `magnitude: null`.
 * Anything that fails — missing key, quota exhausted, network hiccup —
 * degrades silently with the passes' magnitudes left null, the way the
 * UI already renders.
 */
final class PassMagnitudeEnricher
{
    /** Two passes within this many seconds of each other are considered the same. */
    private const PEAK_MATCH_SECONDS = 60;

    public function __construct(
        private readonly N2YOClient $n2yo,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param list<array<string, mixed>> $passes
     * @return list<array<string, mixed>>
     */
    public function enrich(
        array $passes,
        int $norad,
        float $lat,
        float $lon,
        float $altMeters,
        int $days = 7,
    ): array {
        // Default every pass to magnitude: null so the UI shape is
        // stable even when enrichment is impossible (no key / quota).
        foreach ($passes as &$p) {
            if (!array_key_exists('magnitude', $p)) {
                $p['magnitude'] = null;
            }
        }
        unset($p);

        if ($passes === []) {
            return $passes;
        }

        $n2yoDays = max(1, min($days, 10));         // N2YO accepts 1-10
        $result = $this->n2yo->fetchVisualPasses($norad, $lat, $lon, $altMeters, $n2yoDays);
        if ($result === null) {
            return $passes;
        }

        $n2yoPasses = $result['passes'];
        if ($n2yoPasses === []) {
            return $passes;
        }

        $matched = 0;
        foreach ($passes as $i => $pass) {
            $peakAt = $pass['peak_at'] ?? null;
            if (!is_string($peakAt)) continue;

            $peakUtc = strtotime($peakAt);
            if ($peakUtc === false) continue;

            $bestDelta = PHP_INT_MAX;
            $bestMag   = null;
            foreach ($n2yoPasses as $np) {
                $npPeak = (int) ($np['maxUTC'] ?? 0);
                $mag    = $np['mag'] ?? null;
                if ($npPeak === 0 || $mag === null) continue;
                $delta = abs($npPeak - $peakUtc);
                if ($delta < $bestDelta) {
                    $bestDelta = $delta;
                    $bestMag   = (float) $mag;
                }
            }
            if ($bestDelta <= self::PEAK_MATCH_SECONDS && $bestMag !== null) {
                $passes[$i]['magnitude'] = $bestMag;
                $matched++;
            }
        }

        $this->logger->info('N2YO enrichment merged', [
            'sgp4_passes' => count($passes),
            'n2yo_passes' => count($n2yoPasses),
            'matched'     => $matched,
            'transactions_today' => $result['transactions_today'],
        ]);

        return $passes;
    }
}
