<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers\Text;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Services\TextRenderer;
use stdClass;

/**
 * GET /text/search?q=... — server-rendered search results.
 *
 * Uses the same exact-then-FTS5 cascade as the JSON SearchController,
 * then renders results via list.php so the layout/pagination match the
 * catalog page.
 */
final class TextSearchController
{
    private const MAX_RESULTS = 50;

    public function __construct(
        private readonly Connection $db,
        private readonly TextRenderer $renderer,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(Request $request, Response $response, array $args = []): Response
    {
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));

        $satellites = $q !== '' ? $this->resolve($q) : [];

        $body = $this->renderer->renderInner('list.php', [
            'satellites' => $satellites,
            'total'      => count($satellites),
            'page'       => 1,
            'limit'      => self::MAX_RESULTS,
            'pages'      => 1,
            'filters'    => $q === '' ? [] : ['q' => $q],
            'headline'   => $q === '' ? '§ Search' : '§ Search results',
            'sublede'    => $q === ''
                ? 'Type a name, NORAD ID, or international designator into the form below.'
                : "Up to {$this->maxText()} matches for \"{$q}\".",
            'baseUrl'    => '/text/search',
        ]);

        $html = $this->renderer->renderPage(
            title: $q === '' ? 'Search' : "Search: {$q}",
            body: $body,
            activeNav: 'search',
            description: $q === ''
                ? 'Search the satellite catalog by name, NORAD ID, or international designator.'
                : "Search results for \"{$q}\" — exact NORAD/intl designator match before FTS5 fuzzy.",
        );
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolve(string $q): array
    {
        $matches = [];
        $seen = [];

        // 1. Exact NORAD
        if (ctype_digit($q)) {
            $row = $this->db->capsule()->table('satellites')->where('norad_id', (int) $q)->first();
            if ($row !== null) {
                $matches[] = self::serialize($row);
                $seen[(int) $row->norad_id] = true;
            }
        }

        // 2. Exact intl designator (case-insensitive)
        if (count($matches) < self::MAX_RESULTS) {
            $rows = $this->db->capsule()->table('satellites')
                ->whereRaw('UPPER(intl_designator) = ?', [strtoupper($q)])
                ->limit(self::MAX_RESULTS - count($matches))
                ->get();
            foreach ($rows as $row) {
                if (!isset($seen[(int) $row->norad_id])) {
                    $matches[] = self::serialize($row);
                    $seen[(int) $row->norad_id] = true;
                }
            }
        }

        // 3. FTS5
        if (count($matches) < self::MAX_RESULTS) {
            $remaining = self::MAX_RESULTS - count($matches);
            $escaped = '"' . str_replace('"', '""', $q) . '"';
            $ftsIds = $this->db->capsule()->table('satellites_fts')
                ->whereRaw('satellites_fts MATCH ?', [$escaped])
                ->limit($remaining * 2)
                ->pluck('rowid')
                ->all();
            $ftsIds = array_values(array_filter(array_map('intval', $ftsIds), static fn (int $id) => $id > 0));
            if ($ftsIds !== []) {
                $rows = $this->db->capsule()->table('satellites')->whereIn('norad_id', $ftsIds)->get();
                foreach ($rows as $row) {
                    if (!isset($seen[(int) $row->norad_id])) {
                        $matches[] = self::serialize($row);
                        $seen[(int) $row->norad_id] = true;
                        if (count($matches) >= self::MAX_RESULTS) {
                            break;
                        }
                    }
                }
            }
        }

        return $matches;
    }

    private function maxText(): string
    {
        return number_format(self::MAX_RESULTS);
    }

    /**
     * @return array<string, mixed>
     */
    private static function serialize(stdClass $row): array
    {
        return [
            'norad_id'        => (int) $row->norad_id,
            'intl_designator' => $row->intl_designator,
            'name'            => $row->name,
            'object_type'     => $row->object_type,
            'status'          => $row->status,
            'country'         => $row->country,
            'orbit_class'     => $row->orbit_class,
            'launch_date'     => $row->launch_date,
        ];
    }
}
