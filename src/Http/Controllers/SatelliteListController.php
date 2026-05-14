<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Support\Json;
use stdClass;

/**
 * GET /api/v1/satellites
 *
 * Paginated catalog list with the §VI filter set:
 *   country, operator, type, status, orbit_class (multi via comma)
 *   launched_after, launched_before (ISO date)
 *   q (FTS5 fuzzy search over name + intl_designator + operator)
 *   page (default 1), limit (default 100, max 500)
 */
final class SatelliteListController
{
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT     = 500;

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

        $page  = max(1, (int) ($params['page'] ?? 1));
        $limit = min(self::MAX_LIMIT, max(1, (int) ($params['limit'] ?? self::DEFAULT_LIMIT)));
        $offset = ($page - 1) * $limit;

        $q = $this->db->capsule()->table('satellites');

        if (isset($params['country'])) {
            $q->whereIn('country', self::splitMulti((string) $params['country']));
        }
        if (isset($params['operator']) && $params['operator'] !== '') {
            $q->where('operator', 'LIKE', '%' . $params['operator'] . '%');
        }
        if (isset($params['type'])) {
            $q->whereIn('object_type', self::splitMulti((string) $params['type']));
        }
        if (isset($params['status'])) {
            $q->whereIn('status', self::splitMulti((string) $params['status']));
        }
        if (isset($params['orbit_class'])) {
            $q->whereIn('orbit_class', self::splitMulti((string) $params['orbit_class']));
        }
        if (isset($params['launched_after']) && $params['launched_after'] !== '') {
            $q->where('launch_date', '>=', (string) $params['launched_after']);
        }
        if (isset($params['launched_before']) && $params['launched_before'] !== '') {
            $q->where('launch_date', '<=', (string) $params['launched_before']);
        }
        if (isset($params['q']) && $params['q'] !== '') {
            $matchingIds = $this->ftsMatchingIds((string) $params['q']);
            if ($matchingIds === []) {
                $matchingIds = [-1]; // forces empty result
            }
            $q->whereIn('norad_id', $matchingIds);
        }

        $total = (int) (clone $q)->count();
        $rows = $q->orderBy('norad_id')->offset($offset)->limit($limit)->get();

        $data = [];
        foreach ($rows as $r) {
            $data[] = self::serializeSummary($r);
        }

        $pages = max(1, (int) ceil($total / $limit));
        $baseQuery = $params;
        unset($baseQuery['page']);
        $base = '/api/v1/satellites' . ($baseQuery === [] ? '' : '?' . http_build_query($baseQuery) . '&');
        if (!str_contains($base, '?')) {
            $base .= '?';
        }

        $response->getBody()->write(Json::encode([
            'data' => $data,
            'meta' => [
                'page'  => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => $pages,
            ],
            'links' => [
                'self' => $base . 'page=' . $page,
                'next' => $page < $pages ? $base . 'page=' . ($page + 1) : null,
                'prev' => $page > 1     ? $base . 'page=' . ($page - 1) : null,
            ],
        ]));
        return $response;
    }

    /**
     * @return list<int>
     */
    private function ftsMatchingIds(string $term): array
    {
        $escaped = '"' . str_replace('"', '""', $term) . '"';
        $rows = $this->db->capsule()->table('satellites_fts')
            ->whereRaw('satellites_fts MATCH ?', [$escaped])
            ->select('rowid')
            ->get();
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) $r->rowid;
        }
        return $ids;
    }

    /**
     * @return list<string>
     */
    private static function splitMulti(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $v): bool => $v !== ''
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeSummary(stdClass $row): array
    {
        return [
            'norad_id'        => (int) $row->norad_id,
            'intl_designator' => $row->intl_designator,
            'name'            => $row->name,
            'object_type'     => $row->object_type,
            'status'          => $row->status,
            'operator'        => $row->operator,
            'country'         => $row->country,
            'orbit_class'     => $row->orbit_class,
            'launch_date'     => $row->launch_date,
        ];
    }
}
