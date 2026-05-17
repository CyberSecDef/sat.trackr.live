<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Support\Json;

/**
 * GET /api/v1/launches/recent?limit=100&days=90
 *
 * Launches with NET in the past, ordered by NET DESC. Default window
 * is 90 days but caller may extend.
 */
final class RecentLaunchesController
{
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT     = 500;
    private const DEFAULT_DAYS  = 90;

    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    #[OA\Get(
        path: '/api/v1/launches/recent',
        summary: 'Recent launches (NET in the past, ordered descending)',
        tags: ['Launches'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 100, maximum: 500)),
            new OA\Parameter(name: 'days',  in: 'query', schema: new OA\Schema(type: 'integer', default: 90)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Recent launches', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Launch')),
            ])),
        ],
    )]
    public function __invoke(Request $request, Response $response, array $args = []): Response
    {
        $params = $request->getQueryParams();
        $limit = min(self::MAX_LIMIT, max(1, (int) ($params['limit'] ?? self::DEFAULT_LIMIT)));
        $days  = max(1, (int) ($params['days'] ?? self::DEFAULT_DAYS));

        $now    = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $cutoff = $now->modify("-{$days} days")->format('Y-m-d\TH:i:s\Z');
        $nowStr = $now->format('Y-m-d\TH:i:s\Z');

        $rows = $this->db->capsule()->table('launches as l')
            ->leftJoin('launch_sites as p', 'p.id', '=', 'l.pad_id')
            ->where('l.net', '<', $nowStr)
            ->where('l.net', '>=', $cutoff)
            ->orderBy('l.net', 'desc')
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
            'meta' => ['count' => count($data), 'days' => $days, 'cutoff' => $cutoff, 'now' => $nowStr],
        ]));
        return $response->withHeader('Cache-Control', 'public, max-age=3600');
    }
}
