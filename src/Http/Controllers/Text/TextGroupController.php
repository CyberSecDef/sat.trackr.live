<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers\Text;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Http\Controllers\GroupListController;
use SatTrackr\Ingest\CelesTrakGroups;
use SatTrackr\Services\TextRenderer;
use Slim\Exception\HttpNotFoundException;
use stdClass;

/**
 * GET /text/groups/{slug} — paginated list of satellites in a group.
 * Reuses list.php with the group filter applied as a JOIN.
 */
final class TextGroupController
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
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $slug = (string) ($args['slug'] ?? '');
        if ($slug === '' || !in_array($slug, CelesTrakGroups::all(), true)) {
            throw new HttpNotFoundException($request, "Unknown group '{$slug}'");
        }

        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(self::MAX_LIMIT, max(1, (int) ($params['limit'] ?? self::DEFAULT_LIMIT)));
        $offset = ($page - 1) * $limit;

        $base = $this->db->capsule()->table('satellites as s')
            ->join('group_membership as g', 'g.norad_id', '=', 's.norad_id')
            ->where('g.group_slug', $slug);

        $total = (int) (clone $base)->count('s.norad_id');
        $rows = $base->orderBy('s.norad_id')
            ->offset($offset)->limit($limit)
            ->select('s.*')
            ->get();
        $satellites = [];
        foreach ($rows as $r) {
            $satellites[] = self::serialize($r);
        }

        $name = GroupListController::displayName($slug);
        $body = $this->renderer->renderInner('list.php', [
            'satellites' => $satellites,
            'total'      => $total,
            'page'       => $page,
            'limit'      => $limit,
            'pages'      => max(1, (int) ceil($total / $limit)),
            'filters'    => [],
            'headline'   => "§ {$name}",
            'sublede'    => "Members of the CelesTrak \"{$slug}\" group.",
            'baseUrl'    => "/text/groups/{$slug}",
        ]);

        $html = $this->renderer->renderPage(
            title: $name,
            body: $body,
            activeNav: 'groups',
            description: "All {$total} satellites in CelesTrak's \"{$slug}\" group.",
        );
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
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
