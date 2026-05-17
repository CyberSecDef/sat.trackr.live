<?php

declare(strict_types=1);

namespace SatTrackr\Http\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Conjunction',
    properties: [
        new OA\Property(property: 'tca',                     type: 'string', format: 'date-time'),
        new OA\Property(property: 'primary',                 type: 'integer'),
        new OA\Property(property: 'secondary',               type: 'integer'),
        new OA\Property(property: 'miss_distance_km',        type: 'number'),
        new OA\Property(property: 'relative_velocity_km_s',  type: 'number'),
        new OA\Property(property: 'max_probability',         type: 'number'),
    ],
    type: 'object',
)]
final class Conjunction {}
