<?php

declare(strict_types=1);

namespace SatTrackr\Http\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Launch',
    properties: [
        new OA\Property(property: 'id',                       type: 'string'),
        new OA\Property(property: 'name',                     type: 'string'),
        new OA\Property(property: 'net',                      type: 'string', format: 'date-time'),
        new OA\Property(property: 'status',                   type: 'string'),
        new OA\Property(property: 'mission',                  type: 'string', nullable: true),
        new OA\Property(property: 'launch_service_provider',  type: 'string', nullable: true),
        new OA\Property(property: 'rocket',                   type: 'string', nullable: true),
        new OA\Property(property: 'pad_name',                 type: 'string', nullable: true),
        new OA\Property(property: 'pad_location',             type: 'string', nullable: true),
        new OA\Property(property: 'webcast_url',              type: 'string', nullable: true),
    ],
    type: 'object',
)]
final class Launch {}
