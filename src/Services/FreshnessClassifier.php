<?php

declare(strict_types=1);

namespace SatTrackr\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Maps a TLE epoch age to a freshness label per req_spec §11 + visual
 * identity. Used by the API to surface freshness alongside epochs and
 * by the SPA's <sat-freshness-badge>.
 */
final class FreshnessClassifier
{
    public function ageSeconds(string $epochIso, ?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $epoch = new DateTimeImmutable($epochIso);
        return $now->getTimestamp() - $epoch->getTimestamp();
    }

    /**
     * @return 'FRESH'|'STALE'|'AGED'|'OLD'
     */
    public function classify(string $epochIso, ?DateTimeImmutable $now = null): string
    {
        $age = $this->ageSeconds($epochIso, $now);
        return match (true) {
            $age <  48 * 3600 => 'FRESH',  // < 48h
            $age <   7 * 86400 => 'STALE', // 48h – 7d
            $age <  14 * 86400 => 'AGED',  // 7d  – 14d
            default            => 'OLD',
        };
    }
}
