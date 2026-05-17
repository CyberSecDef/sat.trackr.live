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
 * GET /api/v1/space-weather/now
 *
 * Returns the most recent ingested sample.  404 if the table is empty
 * (the operator hasn't run `make ingest-swpc` yet).
 */
final class SpaceWeatherNowController
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string, string> $args
     */
    #[OA\Get(
        path: '/api/v1/space-weather/now',
        summary: 'Most recent NOAA SWPC space-weather sample',
        tags: ['Space weather'],
        responses: [
            new OA\Response(response: 200, description: 'Latest sample', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'sampled_at',     type: 'string', format: 'date-time'),
                    new OA\Property(property: 'kp',             type: 'number', nullable: true),
                    new OA\Property(property: 'xray_class',     type: 'string', nullable: true),
                    new OA\Property(property: 'radio_blackout', type: 'string', nullable: true, description: 'R-scale storm level'),
                    new OA\Property(property: 'solar_radiation',type: 'string', nullable: true, description: 'S-scale storm level'),
                    new OA\Property(property: 'geomagnetic',    type: 'string', nullable: true, description: 'G-scale storm level'),
                ]),
            ])),
            new OA\Response(response: 404, description: 'No samples ingested yet', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function __invoke(Request $request, Response $response, array $args = []): Response
    {
        $row = $this->db->capsule()->table('space_weather_samples')
            ->orderBy('sampled_at', 'desc')
            ->first();
        if ($row === null) {
            throw new HttpNotFoundException($request, 'No space-weather samples ingested yet — run `make ingest-swpc`');
        }
        $response->getBody()->write(Json::encode(['data' => SpaceWeatherSerializer::sample($row)]));
        return $response->withHeader('Cache-Control', 'public, max-age=300, stale-while-revalidate=600');
    }
}
