<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Support\Json;
use stdClass;

/**
 * GET /api/v1/launches/upcoming?limit=50
 *
 * Launches with NET in the future, ordered by NET ASC. JOINs the pad
 * row so the SPA's countdown card has everything it needs in one
 * round trip.
 */
final class UpcomingLaunchesController
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT     = 200;

    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(Request $request, Response $response, array $args = []): Response
    {
        $params = $request->getQueryParams();
        $limit = min(self::MAX_LIMIT, max(1, (int) ($params['limit'] ?? self::DEFAULT_LIMIT)));

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        $rows = $this->db->capsule()->table('launches as l')
            ->leftJoin('launch_sites as p', 'p.id', '=', 'l.pad_id')
            ->where('l.net', '>=', $now)
            ->orderBy('l.net', 'asc')
            ->limit($limit)
            ->select(
                'l.*',
                'p.name as pad_name',
                'p.latitude as pad_latitude',
                'p.longitude as pad_longitude',
                'p.country as pad_country',
                'p.operator as pad_operator',
            )
            ->get();

        $data = [];
        foreach ($rows as $r) {
            $data[] = LaunchSerializer::summary($r);
        }

        $response->getBody()->write(Json::encode([
            'data' => $data,
            'meta' => ['count' => count($data), 'now' => $now],
        ]));
        return $response->withHeader('Cache-Control', 'public, max-age=300, stale-while-revalidate=600');
    }
}
