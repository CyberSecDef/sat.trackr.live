<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Support\Json;

/**
 * GET /api/v1/search?q=...
 *
 * Universal search with match-type tagging:
 *   1. exact NORAD ID match (numeric q)
 *   2. exact intl_designator match (case-insensitive)
 *   3. FTS5 fuzzy match on name + intl_designator + operator
 *
 * Returns up to 50 deduplicated results, ordered by match strength.
 */
final class SearchController
{
    private const MAX_RESULTS = 50;

    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    #[OA\Get(
        path: '/api/v1/search',
        summary: 'Universal search (NORAD / intl-designator / FTS5 name fuzzy)',
        tags: ['Search'],
        parameters: [new OA\Parameter(name: 'q', in: 'query', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Up to 50 results ranked by match strength', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', properties: [
                    new OA\Property(property: 'norad_id',   type: 'integer'),
                    new OA\Property(property: 'name',       type: 'string'),
                    new OA\Property(property: 'match_type', type: 'string', enum: ['norad', 'intl_designator', 'fts']),
                ])),
            ])),
        ],
    )]
    public function __invoke(Request $request, Response $response, array $args = []): Response
    {
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        if ($q === '') {
            $response->getBody()->write(Json::encode([
                'data' => [],
                'meta' => ['query' => '', 'count' => 0],
            ]));
            return $response;
        }

        $matches = [];
        $seen = [];

        // 1. Exact NORAD ID
        if (ctype_digit($q)) {
            $row = $this->db->capsule()->table('satellites')
                ->where('norad_id', (int) $q)
                ->first();
            if ($row !== null) {
                $matches[] = $this->serialize($row, 'norad_id');
                $seen[(int) $row->norad_id] = true;
            }
        }

        // 2. Exact intl_designator (case-insensitive)
        if (count($matches) < self::MAX_RESULTS) {
            $rows = $this->db->capsule()->table('satellites')
                ->whereRaw('UPPER(intl_designator) = ?', [strtoupper($q)])
                ->limit(self::MAX_RESULTS - count($matches))
                ->get();
            foreach ($rows as $row) {
                if (!isset($seen[(int) $row->norad_id])) {
                    $matches[] = $this->serialize($row, 'intl_designator');
                    $seen[(int) $row->norad_id] = true;
                }
            }
        }

        // 3. FTS5 fuzzy
        if (count($matches) < self::MAX_RESULTS) {
            $remaining = self::MAX_RESULTS - count($matches);
            $escaped = '"' . str_replace('"', '""', $q) . '"';
            $ftsIds = $this->db->capsule()->table('satellites_fts')
                ->whereRaw('satellites_fts MATCH ?', [$escaped])
                ->limit($remaining * 2) // overshoot since some may already be matched
                ->pluck('rowid')
                ->all();
            $ftsIds = array_values(array_filter(
                array_map('intval', $ftsIds),
                static fn (int $id): bool => $id > 0
            ));
            if ($ftsIds !== []) {
                $rows = $this->db->capsule()->table('satellites')
                    ->whereIn('norad_id', $ftsIds)
                    ->get();
                foreach ($rows as $row) {
                    if (!isset($seen[(int) $row->norad_id])) {
                        $matches[] = $this->serialize($row, 'fts');
                        $seen[(int) $row->norad_id] = true;
                        if (count($matches) >= self::MAX_RESULTS) {
                            break;
                        }
                    }
                }
            }
        }

        $response->getBody()->write(Json::encode([
            'data' => $matches,
            'meta' => ['query' => $q, 'count' => count($matches)],
        ]));
        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(\stdClass $row, string $matchType): array
    {
        return [
            'norad_id'        => (int) $row->norad_id,
            'intl_designator' => $row->intl_designator,
            'name'            => $row->name,
            'object_type'     => $row->object_type,
            'status'          => $row->status,
            'country'         => $row->country,
            'match_type'      => $matchType,
        ];
    }
}
