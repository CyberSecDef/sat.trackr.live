<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Support\Json;

/**
 * GET /api/v1/autocomplete?q=...
 *
 * Typeahead-friendly: up to 10 results, low-latency. Combines exact
 * NORAD ID + FTS5 prefix match. Aggressively cacheable since the same
 * partial queries repeat.
 */
final class AutocompleteController
{
    private const MAX_RESULTS = 10;

    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    #[OA\Get(
        path: '/api/v1/autocomplete',
        summary: 'Low-latency typeahead (up to 10 results)',
        tags: ['Search'],
        parameters: [new OA\Parameter(name: 'q', in: 'query', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Up to 10 autocomplete suggestions', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', properties: [
                    new OA\Property(property: 'norad_id', type: 'integer'),
                    new OA\Property(property: 'name',     type: 'string'),
                ])),
            ])),
        ],
    )]
    public function __invoke(Request $request, Response $response, array $args = []): Response
    {
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        if ($q === '' || strlen($q) < 1) {
            $response->getBody()->write(Json::encode(['data' => []]));
            return $response->withHeader('Cache-Control', 'public, max-age=300');
        }

        $matches = [];
        $seen = [];

        // 1. Exact NORAD ID prefix
        if (ctype_digit($q)) {
            $rows = $this->db->capsule()->table('satellites')
                ->where('norad_id', 'LIKE', $q . '%')
                ->limit(self::MAX_RESULTS)
                ->get();
            foreach ($rows as $row) {
                $matches[] = $this->serialize($row);
                $seen[(int) $row->norad_id] = true;
            }
        }

        // 2. FTS5 prefix match (append * for prefix in fts5 syntax)
        if (count($matches) < self::MAX_RESULTS) {
            $remaining = self::MAX_RESULTS - count($matches);
            $escaped = '"' . str_replace('"', '""', $q) . '" *';
            $ftsIds = $this->db->capsule()->table('satellites_fts')
                ->whereRaw('satellites_fts MATCH ?', [$escaped])
                ->limit($remaining * 2)
                ->pluck('rowid')
                ->all();
            $ftsIds = array_values(array_filter(
                array_map('intval', $ftsIds),
                static fn (int $id): bool => $id > 0
            ));
            if ($ftsIds !== []) {
                $rows = $this->db->capsule()->table('satellites')
                    ->whereIn('norad_id', $ftsIds)
                    ->limit($remaining)
                    ->get();
                foreach ($rows as $row) {
                    if (!isset($seen[(int) $row->norad_id])) {
                        $matches[] = $this->serialize($row);
                        $seen[(int) $row->norad_id] = true;
                        if (count($matches) >= self::MAX_RESULTS) {
                            break;
                        }
                    }
                }
            }
        }

        $response->getBody()->write(Json::encode(['data' => $matches]));
        return $response->withHeader('Cache-Control', 'public, max-age=300');
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(\stdClass $row): array
    {
        return [
            'norad_id'    => (int) $row->norad_id,
            'name'        => $row->name,
            'object_type' => $row->object_type,
            'country'     => $row->country,
        ];
    }
}
