<?php

declare(strict_types=1);

namespace SatTrackr\Http\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SatelliteDetail',
    description: 'Full satellite metadata, with tle_current inlined to save a round trip',
    properties: [
        new OA\Property(property: 'norad_id',         type: 'integer'),
        new OA\Property(property: 'intl_designator',  type: 'string', nullable: true),
        new OA\Property(property: 'name',             type: 'string'),
        new OA\Property(property: 'alt_names',        type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'object_type',      type: 'string'),
        new OA\Property(property: 'status',           type: 'string'),
        new OA\Property(property: 'operator',         type: 'string', nullable: true),
        new OA\Property(property: 'country',          type: 'string', nullable: true),
        new OA\Property(property: 'launch_date',      type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'launch_site_code', type: 'string', nullable: true),
        new OA\Property(property: 'launch_vehicle',   type: 'string', nullable: true),
        new OA\Property(property: 'mission',          type: 'string', nullable: true),
        new OA\Property(property: 'orbit_class',      type: 'string'),
        new OA\Property(property: 'rcs_meters',       type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'size_class',       type: 'string', nullable: true),
        new OA\Property(property: 'mass_kg',          type: 'integer', nullable: true),
        new OA\Property(property: 'dimensions',       type: 'string', nullable: true),
        new OA\Property(property: 'purposes',         type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'wikipedia_slug',   type: 'string', nullable: true),
        new OA\Property(property: 'has_3d_model',     type: 'boolean'),
        new OA\Property(property: 'image_url',        type: 'string', nullable: true),
        new OA\Property(property: 'decayed_at',       type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'tle_current',      ref:  '#/components/schemas/TleCurrent', nullable: true),
    ],
    type: 'object',
)]
final class SatelliteDetail {}
