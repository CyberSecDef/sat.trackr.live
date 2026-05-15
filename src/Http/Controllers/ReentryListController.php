<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Support\Json;

/**
 * GET /api/v1/reentries/upcoming?within_hours=168
 *
 * Predicted reentries with `predicted_decay >= now`, ordered by
 * predicted_decay ASC. Optional `within_hours` cap restricts the
 * window (default 168h = 7 days, max 720h = 30 days).
 */
final class ReentryListController
{
    private const DEFAULT_WINDOW_HOURS = 168;   // 7 days
    private const MAX_WINDOW_HOURS     = 720;   // 30 days

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
        $hours  = min(self::MAX_WINDOW_HOURS, max(1, (int) ($params['within_hours'] ?? self::DEFAULT_WINDOW_HOURS)));

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $end = $now->modify("+{$hours} hours");
        $nowIso = $now->format('Y-m-d\TH:i:s\Z');
        $endIso = $end->format('Y-m-d\TH:i:s\Z');

        $rows = $this->db->capsule()->table('reentries as r')
            ->leftJoin('satellites as s', 's.norad_id', '=', 'r.norad_id')
            ->where('r.predicted_decay', '>=', $nowIso)
            ->where('r.predicted_decay', '<=', $endIso)
            ->orderBy('r.predicted_decay', 'asc')
            ->select(
                'r.*',
                's.name as satellite_name',
                's.object_type as object_type',
            )
            ->get();

        $data = [];
        foreach ($rows as $r) {
            $data[] = ReentrySerializer::summary($r);
        }

        $response->getBody()->write(Json::encode([
            'data' => $data,
            'meta' => [
                'count'        => count($data),
                'within_hours' => $hours,
                'now'          => $nowIso,
                'window_end'   => $endIso,
            ],
        ]));
        return $response->withHeader('Cache-Control', 'public, max-age=600, stale-while-revalidate=900');
    }
}
