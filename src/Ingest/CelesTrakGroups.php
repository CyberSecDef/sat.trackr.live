<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

/**
 * The CelesTrak GP groups we ingest in Phase 1. Mirrors the table in
 * docs/phase1.md § IV. Many objects appear in multiple groups; the
 * upsert-by-norad_id logic dedupes naturally.
 */
final class CelesTrakGroups
{
    /** @return list<string> */
    public static function all(): array
    {
        return [
            // Special-Interest
            'active', 'stations', 'last-30-days', 'analyst',

            // Weather & Earth-Obs
            'weather', 'noaa', 'goes', 'resource', 'sarsat', 'dmc', 'planet', 'spire',

            // Communications
            'geo', 'intelsat', 'ses', 'iridium', 'iridium-NEXT', 'starlink', 'oneweb',
            'orbcomm', 'globalstar', 'swarm', 'amateur',

            // Navigation
            'gnss', 'gps-ops', 'glo-ops', 'galileo', 'beidou', 'sbas', 'musson',

            // Scientific
            'science', 'geodetic', 'engineering', 'education',

            // Miscellaneous
            'military', 'radar', 'cubesat', 'other',
        ];
    }
}
