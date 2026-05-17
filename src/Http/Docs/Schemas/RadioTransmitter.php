<?php

declare(strict_types=1);

namespace SatTrackr\Http\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RadioTransmitter',
    description: 'Amateur-radio transmitter from SatNOGS DB (Phase 5 chunk 1)',
    properties: [
        new OA\Property(property: 'uuid',             type: 'string'),
        new OA\Property(property: 'description',      type: 'string', nullable: true),
        new OA\Property(property: 'type',             type: 'string', nullable: true,
            enum: ['Transmitter', 'Receiver', 'Transceiver', 'Transponder']),
        new OA\Property(property: 'alive',            type: 'boolean'),
        new OA\Property(property: 'mode',             type: 'string', nullable: true),
        new OA\Property(property: 'baud',             type: 'number', nullable: true),
        new OA\Property(property: 'service',          type: 'string', nullable: true),
        new OA\Property(property: 'status',           type: 'string', nullable: true,
            enum: ['active', 'inactive', 'invalid']),
        new OA\Property(property: 'uplink_low_hz',    type: 'integer', nullable: true),
        new OA\Property(property: 'uplink_high_hz',   type: 'integer', nullable: true),
        new OA\Property(property: 'downlink_low_hz',  type: 'integer', nullable: true),
        new OA\Property(property: 'downlink_high_hz', type: 'integer', nullable: true),
        new OA\Property(property: 'updated_at',       type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
final class RadioTransmitter {}
