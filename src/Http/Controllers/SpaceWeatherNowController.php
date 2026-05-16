<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

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
