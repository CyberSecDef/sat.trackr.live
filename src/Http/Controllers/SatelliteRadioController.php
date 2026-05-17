<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Support\Json;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /api/v1/satellites/{norad}/radio
 *
 * Phase 5 chunk 1B — return amateur-radio transmitters known for a
 * given NORAD, sourced from SatNOGS DB.  Sorted alive-first then by
 * description for deterministic UI ordering.
 *
 * 200 returns `{ data: [...] }` (possibly empty if no transmitters).
 * 404 only when the parent satellite isn't in our catalog at all.
 */
final class SatelliteRadioController
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /** @param array<string, string> $args */
    #[OA\Get(
        path: '/api/v1/satellites/{norad}/radio',
        summary: 'Amateur-radio transmitters from SatNOGS DB',
        tags: ['Radio'],
        parameters: [new OA\Parameter(name: 'norad', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Transmitter rows (alive-first, then by description). May be empty.',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/RadioTransmitter')),
                ])),
            new OA\Response(response: 404, description: 'Unknown satellite', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $norad = (int) ($args['norad'] ?? 0);
        if ($norad <= 0) {
            throw new HttpNotFoundException($request, 'Invalid NORAD ID');
        }

        $exists = $this->db->capsule()->table('satellites')
            ->where('norad_id', $norad)
            ->exists();
        if (!$exists) {
            throw new HttpNotFoundException($request, "Satellite {$norad} not found");
        }

        /** @var list<\stdClass> $rows */
        $rows = $this->db->capsule()->table('satellite_radio')
            ->where('norad_id', $norad)
            ->orderByDesc('alive')
            ->orderBy('description')
            ->get()
            ->all();

        $data = array_map([self::class, 'serialize'], $rows);

        $response->getBody()->write(Json::encode(['data' => $data]));
        return $response;
    }

    /** @return array<string, mixed> */
    private static function serialize(\stdClass $r): array
    {
        return [
            'uuid'             => $r->uuid,
            'description'      => $r->description,
            'type'             => $r->type,
            'alive'            => (bool) $r->alive,
            'mode'             => $r->mode,
            'baud'             => $r->baud !== null ? (float) $r->baud : null,
            'service'          => $r->service,
            'status'           => $r->status,
            'uplink_low_hz'    => $r->uplink_low_hz   !== null ? (int) $r->uplink_low_hz   : null,
            'uplink_high_hz'   => $r->uplink_high_hz  !== null ? (int) $r->uplink_high_hz  : null,
            'downlink_low_hz'  => $r->downlink_low_hz !== null ? (int) $r->downlink_low_hz : null,
            'downlink_high_hz' => $r->downlink_high_hz!== null ? (int) $r->downlink_high_hz: null,
            'updated_at'       => $r->updated_at,
        ];
    }
}
