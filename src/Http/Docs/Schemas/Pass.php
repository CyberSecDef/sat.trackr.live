<?php

declare(strict_types=1);

namespace SatTrackr\Http\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Pass',
    properties: [
        new OA\Property(property: 'rise_at',           type: 'string', format: 'date-time'),
        new OA\Property(property: 'peak_at',           type: 'string', format: 'date-time'),
        new OA\Property(property: 'set_at',            type: 'string', format: 'date-time'),
        new OA\Property(property: 'max_elevation_deg', type: 'number'),
        new OA\Property(property: 'magnitude',         type: 'number', nullable: true),
    ],
    type: 'object',
)]
final class Pass {}
