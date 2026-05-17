<?php

declare(strict_types=1);

namespace SatTrackr\Http\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SatelliteSummary',
    description: 'Minimal satellite row returned by list endpoints',
    properties: [
        new OA\Property(property: 'norad_id',        type: 'integer', example: 25544),
        new OA\Property(property: 'intl_designator', type: 'string',  example: '1998-067A'),
        new OA\Property(property: 'name',            type: 'string',  example: 'ISS (ZARYA)'),
        new OA\Property(property: 'object_type',     type: 'string',  example: 'PAYLOAD'),
        new OA\Property(property: 'status',          type: 'string',  example: 'active'),
        new OA\Property(property: 'country',         type: 'string',  example: 'US', nullable: true),
        new OA\Property(property: 'launch_date',     type: 'string',  format: 'date', nullable: true),
        new OA\Property(property: 'orbit_class',     type: 'string',  example: 'LEO'),
    ],
    type: 'object',
)]
final class SatelliteSummary {}
