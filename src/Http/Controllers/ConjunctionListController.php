<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Support\Json;

/**
 * GET /api/v1/conjunctions/upcoming
 *
 * Query params:
 *   within_hours      default 24, max 720           — TCA window from now
 *   min_probability   default 0                     — drops rows with prob < this
 *   limit             default 50, max 500
 *   page              default 1                     — 1-based
 *   sort              default 'probability'         — 'probability' | 'tca' | 'range'
 *
 * Default sort is probability DESC then TCA ASC — most-likely close-
 * approaches first.  The chunk-1 ingester pulls ~145k rows over a
 * ~30-day horizon; without pagination + a strong default this would
 * be a 30MB JSON blob.
 */
final class ConjunctionListController
{
    private const DEFAULT_WINDOW_HOURS = 24;
    private const MAX_WINDOW_HOURS     = 720;     // 30 days — the SOCRATES horizon
    private const DEFAULT_LIMIT        = 50;
    private const MAX_LIMIT            = 500;

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string, string> $args
     */
    public function __invoke(Request $request, Response $response, array $args = []): Response
    {
        $params = $request->getQueryParams();
        $hours  = min(self::MAX_WINDOW_HOURS, max(1, (int) ($params['within_hours'] ?? self::DEFAULT_WINDOW_HOURS)));
        $minProb = max(0.0, (float) ($params['min_probability'] ?? 0.0));
        $limit  = min(self::MAX_LIMIT, max(1, (int) ($params['limit'] ?? self::DEFAULT_LIMIT)));
        $page   = max(1, (int) ($params['page'] ?? 1));
        $sortRaw = strtolower((string) ($params['sort'] ?? 'probability'));
        $sort   = in_array($sortRaw, ['probability', 'tca', 'range'], true) ? $sortRaw : 'probability';

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nowIso = $now->format('Y-m-d\TH:i:s\Z');
        $endIso = $now->modify("+{$hours} hours")->format('Y-m-d\TH:i:s\Z');

        $query = $this->db->capsule()->table('conjunctions as c')
            ->leftJoin('satellites as p', 'p.norad_id', '=', 'c.norad_id_primary')
            ->leftJoin('satellites as s', 's.norad_id', '=', 'c.norad_id_secondary')
            ->where('c.tca', '>=', $nowIso)
            ->where('c.tca', '<=', $endIso);

        if ($minProb > 0) {
            $query->where('c.max_probability', '>=', $minProb);
        }

        // Order
        if ($sort === 'tca') {
            $query->orderBy('c.tca', 'asc');
        } elseif ($sort === 'range') {
            $query->orderBy('c.tca_range_km', 'asc');
        } else {
            // probability — DESC, NULLs last; SQLite sorts NULL last with DESC by default
            $query->orderByRaw('c.max_probability IS NULL, c.max_probability DESC')
                  ->orderBy('c.tca', 'asc');
        }

        $total = (int) (clone $query)->count();
        $rows = $query->select(
                'c.*',
                'p.object_type as primary_object_type',
                'p.country     as primary_country',
                's.object_type as secondary_object_type',
                's.country     as secondary_country',
            )
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get();

        $data = [];
        foreach ($rows as $r) {
            $data[] = ConjunctionSerializer::summary($r);
        }

        $response->getBody()->write(Json::encode([
            'data' => $data,
            'meta' => [
                'count'           => count($data),
                'total'           => $total,
                'page'            => $page,
                'limit'           => $limit,
                'within_hours'    => $hours,
                'min_probability' => $minProb,
                'sort'            => $sort,
                'now'             => $nowIso,
                'window_end'      => $endIso,
            ],
        ]));
        return $response->withHeader('Cache-Control', 'public, max-age=600, stale-while-revalidate=900');
    }
}
