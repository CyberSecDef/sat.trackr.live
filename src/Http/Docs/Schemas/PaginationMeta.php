<?php

declare(strict_types=1);

namespace SatTrackr\Http\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PaginationMeta',
    properties: [
        new OA\Property(property: 'page',        type: 'integer', example: 1),
        new OA\Property(property: 'per_page',    type: 'integer', example: 100),
        new OA\Property(property: 'total',       type: 'integer', example: 15665),
        new OA\Property(property: 'total_pages', type: 'integer', example: 157),
    ],
    type: 'object',
)]
final class PaginationMeta {}
