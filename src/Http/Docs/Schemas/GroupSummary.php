<?php

declare(strict_types=1);

namespace SatTrackr\Http\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'GroupSummary',
    properties: [
        new OA\Property(property: 'slug',  type: 'string',  example: 'starlink'),
        new OA\Property(property: 'name',  type: 'string',  example: 'Starlink'),
        new OA\Property(property: 'count', type: 'integer', example: 6500),
    ],
    type: 'object',
)]
final class GroupSummary {}
