<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

/**
 * Map LL2's status.abbrev string to our launches.status enum
 * (per docs/phase2.md §III migration 9).
 */
final class LL2StatusMapper
{
    public static function status(?string $abbrev): string
    {
        return match (trim((string) $abbrev)) {
            'Go'              => 'GO',
            'TBD'             => 'TBD',
            'Hold'            => 'HOLD',
            'Success'         => 'SUCCESS',
            'Failure'         => 'FAILURE',
            'Partial Failure' => 'PARTIAL_FAILURE',
            'In Flight'       => 'GO',  // closest existing enum value; chunk-7 may add 'IN_FLIGHT'
            'TBC'             => 'TBD', // "to be confirmed"
            ''                => 'UNKNOWN',
            default           => 'UNKNOWN',
        };
    }
}
