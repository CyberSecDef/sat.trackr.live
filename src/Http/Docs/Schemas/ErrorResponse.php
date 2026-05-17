<?php

declare(strict_types=1);

namespace SatTrackr\Http\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ErrorResponse',
    properties: [
        new OA\Property(property: 'error', type: 'object', properties: [
            new OA\Property(property: 'code',    type: 'string',  example: 'not_found'),
            new OA\Property(property: 'message', type: 'string',  example: 'Satellite 99999 not found'),
            new OA\Property(property: 'status',  type: 'integer', example: 404),
        ]),
    ],
    type: 'object',
)]
final class ErrorResponse {}
