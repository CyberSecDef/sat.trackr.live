<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Services\FreshnessClassifier;
use SatTrackr\Support\Json;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /api/v1/satellites/{norad}/tle
 *
 * Just the current TLE. Useful for clients that already have catalog
 * metadata and only need refreshed orbital elements.
 */
final class SatelliteTleController
{
    public function __construct(
        private readonly Connection $db,
        private readonly FreshnessClassifier $freshness,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    #[OA\Get(
        path: '/api/v1/satellites/{norad}/tle',
        summary: 'Current TLE only (skip the metadata round-trip)',
        tags: ['Catalog'],
        parameters: [new OA\Parameter(name: 'norad', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'TLE current', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/TleCurrent'),
            ])),
            new OA\Response(response: 404, description: 'Unknown NORAD or no TLE on file', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $norad = (int) ($args['norad'] ?? 0);
        if ($norad <= 0) {
            throw new HttpNotFoundException($request, 'Invalid NORAD ID');
        }

        $tle = $this->db->capsule()->table('tle_current')
            ->where('norad_id', $norad)
            ->first();
        if ($tle === null) {
            throw new HttpNotFoundException($request, "TLE for satellite {$norad} not found");
        }

        $data = [
            'norad_id'          => (int) $tle->norad_id,
            'epoch'             => $tle->epoch,
            'epoch_age_seconds' => $this->freshness->ageSeconds($tle->epoch),
            'freshness'         => $this->freshness->classify($tle->epoch),
            'line1'             => $tle->line1,
            'line2'             => $tle->line2,
            'mean_motion'       => (float) $tle->mean_motion,
            'eccentricity'      => (float) $tle->eccentricity,
            'inclination_deg'   => (float) $tle->inclination_deg,
            'raan_deg'          => (float) $tle->raan_deg,
            'arg_perigee_deg'   => (float) $tle->arg_perigee_deg,
            'mean_anomaly_deg'  => (float) $tle->mean_anomaly_deg,
            'bstar'             => (float) $tle->bstar,
            'rev_number'        => (int)   $tle->rev_number,
            'period_min'        => (float) $tle->period_min,
            'perigee_km'        => (float) $tle->perigee_km,
            'apogee_km'         => (float) $tle->apogee_km,
            'semimajor_km'      => (float) $tle->semimajor_km,
            'source'            => $tle->source,
            'updated_at'        => $tle->updated_at,
        ];

        $response->getBody()->write(Json::encode(['data' => $data]));
        return $response;
    }
}
