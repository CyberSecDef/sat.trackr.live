<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers\Text;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Services\TextRenderer;
use stdClass;

/**
 * GET /text  — server-rendered paginated catalog with filter form.
 * Mirrors the JSON /api/v1/satellites endpoint but emits HTML.
 */
final class TextCatalogController
{
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT     = 500;

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
        $params = $request->getQueryParams();

        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(self::MAX_LIMIT, max(1, (int) ($params['limit'] ?? self::DEFAULT_LIMIT)));
        $offset = ($page - 1) * $limit;

        $filters = [
            'q'           => trim((string) ($params['q'] ?? '')),
            'country'     => trim((string) ($params['country'] ?? '')),
            'type'        => trim((string) ($params['type'] ?? '')),
            'status'      => trim((string) ($params['status'] ?? '')),
            'orbit_class' => trim((string) ($params['orbit_class'] ?? '')),
        ];

        $q = $this->db->capsule()->table('satellites');
        if ($filters['country'] !== '') {
            $q->whereIn('country', self::splitMulti($filters['country']));
        }
        if ($filters['type'] !== '') {
            $q->where('object_type', $filters['type']);
        }
        if ($filters['status'] !== '') {
            $q->where('status', $filters['status']);
        }
        if ($filters['orbit_class'] !== '') {
            $q->where('orbit_class', $filters['orbit_class']);
        }
        if ($filters['q'] !== '') {
            $matchingIds = $this->ftsMatchingIds($filters['q']);
            $q->whereIn('norad_id', $matchingIds === [] ? [-1] : $matchingIds);
        }

        $total = (int) (clone $q)->count();
        $rows = $q->orderBy('norad_id')->offset($offset)->limit($limit)->get();
        $satellites = [];
        foreach ($rows as $r) {
            $satellites[] = self::serialize($r);
        }

        $body = $this->renderer->renderInner('list.php', [
            'satellites' => $satellites,
            'total'      => $total,
            'page'       => $page,
            'limit'      => $limit,
            'pages'      => max(1, (int) ceil($total / $limit)),
            'filters'    => array_filter($filters, static fn (string $v): bool => $v !== ''),
            'headline'   => 'Satellite catalog',
            'sublede'    => self::sublede($filters),
            'baseUrl'    => '/text',
        ]);

        $html = $this->renderer->renderPage(
            title: 'Satellite catalog',
            body: $body,
            activeNav: 'catalog',
            description: 'Browse every tracked satellite, rocket body, and debris object in Earth orbit. ' . number_format($total) . ' objects in the catalog.',
        );
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
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

    /**
     * @param array<string, string> $filters
     */
    private static function sublede(array $filters): string
    {
        $active = array_filter($filters, static fn (string $v): bool => $v !== '');
        if (empty($active)) {
            return 'All ingested satellites. Use the filters to narrow down.';
        }
        $bits = [];
        foreach ($active as $k => $v) {
            $bits[] = "{$k}={$v}";
        }
        return 'Filtered: ' . implode(' · ', $bits);
    }
}
