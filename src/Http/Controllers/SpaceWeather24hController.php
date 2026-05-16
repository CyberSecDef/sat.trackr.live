<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Support\Json;

/**
 * GET /api/v1/space-weather/24h
 *
 * All samples in the trailing 24 hours, ASC by sampled_at — the shape
 * the chunk-3C SVG trend chart consumes.
 */
final class SpaceWeather24hController
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string, string> $args
     */
    public function __invoke(Request $request, Response $response, array $args = []): Response
    {
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-24 hours')
            ->format('Y-m-d\TH:i:s\Z');

        $rows = $this->db->capsule()->table('space_weather_samples')
            ->where('sampled_at', '>=', $cutoff)
            ->orderBy('sampled_at', 'asc')
            ->get();

        $data = [];
        foreach ($rows as $row) {
            $data[] = SpaceWeatherSerializer::sample($row);
        }
        $response->getBody()->write(Json::encode([
            'data' => $data,
            'meta' => [
                'count'    => count($data),
                'since'    => $cutoff,
            ],
        ]));
        return $response->withHeader('Cache-Control', 'public, max-age=300, stale-while-revalidate=600');
    }
}
