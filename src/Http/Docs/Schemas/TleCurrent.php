<?php

declare(strict_types=1);

namespace SatTrackr\Http\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'TleCurrent',
    description: 'Most-recent two-line element set for a satellite',
    properties: [
        new OA\Property(property: 'epoch',             type: 'string', format: 'date-time'),
        new OA\Property(property: 'epoch_age_seconds', type: 'integer'),
        new OA\Property(property: 'freshness',         type: 'string', enum: ['FRESH', 'STALE', 'OLD']),
        new OA\Property(property: 'line1',             type: 'string'),
        new OA\Property(property: 'line2',             type: 'string'),
        new OA\Property(property: 'mean_motion',       type: 'number', format: 'float'),
        new OA\Property(property: 'eccentricity',      type: 'number', format: 'float'),
        new OA\Property(property: 'inclination_deg',   type: 'number', format: 'float'),
        new OA\Property(property: 'raan_deg',          type: 'number', format: 'float'),
        new OA\Property(property: 'arg_perigee_deg',   type: 'number', format: 'float'),
        new OA\Property(property: 'mean_anomaly_deg',  type: 'number', format: 'float'),
        new OA\Property(property: 'bstar',             type: 'number', format: 'float'),
        new OA\Property(property: 'rev_number',        type: 'integer'),
        new OA\Property(property: 'period_min',        type: 'number', format: 'float'),
        new OA\Property(property: 'perigee_km',        type: 'number', format: 'float'),
        new OA\Property(property: 'apogee_km',         type: 'number', format: 'float'),
        new OA\Property(property: 'semimajor_km',      type: 'number', format: 'float'),
        new OA\Property(property: 'source',            type: 'string'),
        new OA\Property(property: 'updated_at',        type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
final class TleCurrent {}
