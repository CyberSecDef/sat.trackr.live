<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers\Text;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Http\Controllers\GroupListController;
use SatTrackr\Ingest\CelesTrakGroups;
use SatTrackr\Services\TextRenderer;

/**
 * GET /text/groups — list of all CelesTrak GP groups + member counts.
 */
final class TextGroupsController
{
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
        $counts = [];
        $rows = $this->db->capsule()->table('group_membership')
            ->select('group_slug')
            ->selectRaw('COUNT(*) AS member_count')
            ->groupBy('group_slug')
            ->get();
        foreach ($rows as $r) {
            $counts[(string) $r->group_slug] = (int) $r->member_count;
        }

        $data = [];
        foreach (CelesTrakGroups::all() as $slug) {
            $data[] = [
                'slug'  => $slug,
                'name'  => GroupListController::displayName($slug),
                'count' => $counts[$slug] ?? 0,
            ];
        }

        $body = $this->renderer->renderInner('groups.php', ['groups' => $data]);
        $html = $this->renderer->renderPage(
            title: 'Groups',
            body: $body,
            activeNav: 'groups',
            description: 'CelesTrak GP groups in the catalog: active, starlink, GPS, weather, and ' . (count($data) - 4) . ' more.',
        );
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
