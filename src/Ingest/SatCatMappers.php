<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

/**
 * Mappers between CelesTrak SATCAT field codes and our schema's enum values.
 * SATCAT uses compact codes (PAY / R/B / DEB / TBA / UNK for object_type;
 * +/-/P/B/S/X/D/? for OPS_STATUS_CODE). Our schema uses the spelled-out
 * versions to match req_spec §5.
 */
final class SatCatMappers
{
    /**
     * SATCAT OBJECT_TYPE → satellites.object_type
     * Per CelesTrak docs: PAY=payload, R/B=rocket body, DEB=debris,
     * TBA=tracking-and-impact-prediction (transient), UNK=unknown.
     */
    public static function objectType(?string $code): string
    {
        return match (trim((string) $code)) {
            'PAY'           => 'PAYLOAD',
            'R/B', 'RB'     => 'ROCKET_BODY',
            'DEB'           => 'DEBRIS',
            'TBA'           => 'TBA',
            'UNK', '', null => 'UNKNOWN',
            default         => 'UNKNOWN',
        };
    }

    /**
     * SATCAT OPS_STATUS_CODE → satellites.status (per chunk-1 design decision 6).
     * - + = operational (ACTIVE)
     * - P = partially operational (ACTIVE)
     * - B = backup / standby (ACTIVE)
     * - S = spare (INACTIVE)
     * - X = extended mission (INACTIVE)
     * - - = decommissioned (INACTIVE)
     * - D = decayed (DECAYED)
     * - ? / null / blank = unknown
     */
    public static function status(?string $code): string
    {
        return match (trim((string) $code)) {
            '+', 'P', 'B'        => 'ACTIVE',
            'S', 'X', '-'        => 'INACTIVE',
            'D'                  => 'DECAYED',
            '', '?', null        => 'UNKNOWN',
            default              => 'UNKNOWN',
        };
    }

    /**
     * Map a satellite's group_membership rows to a deduplicated list of
     * purpose tags per Phase 2 chunk-1 design decision 7. A satellite in
     * multiple groups may produce multiple purposes (e.g. ISS is in
     * 'stations' → station, plus 'active' → unknown ignored).
     *
     * @param  list<string> $groupSlugs
     * @return list<string>
     */
    public static function purposesForGroups(array $groupSlugs): array
    {
        $purposes = [];
        foreach ($groupSlugs as $slug) {
            $p = self::purposeForGroup($slug);
            if ($p !== null) {
                $purposes[$p] = true;
            }
        }
        return array_keys($purposes);
    }

    /**
     * One group → one purpose tag (or null if the group doesn't strongly
     * imply a purpose, e.g. 'active' or 'last-30-days').
     */
    private static function purposeForGroup(string $slug): ?string
    {
        return match ($slug) {
            // Navigation
            'gps-ops', 'glo-ops', 'galileo', 'beidou', 'gnss', 'sbas', 'musson' => 'nav',

            // Earth observation / weather
            'weather', 'noaa', 'goes', 'dmc', 'planet', 'spire', 'sarsat', 'resource' => 'earth_obs',

            // Communications
            'intelsat', 'ses', 'iridium', 'iridium-NEXT', 'starlink', 'oneweb',
            'orbcomm', 'globalstar', 'swarm', 'amateur', 'geo' => 'comms',

            // Crewed stations
            'stations' => 'station',

            // Military / defense
            'military', 'radar' => 'military',

            // Scientific
            'science', 'geodetic' => 'science',

            // Tech demos / cubesats / engineering
            'cubesat', 'engineering', 'education' => 'tech_demo',

            // Aggregations and "other" groups don't add purpose info
            'active', 'last-30-days', 'analyst', 'other' => null,

            default => null,
        };
    }
}
