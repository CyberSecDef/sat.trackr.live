<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Ingest\CelesTrakGroups;
use SatTrackr\Support\Json;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /api/v1/groups/{slug}
 *
 * Group metadata + member NORAD IDs.
 */
final class GroupDetailController
{
    public function __construct(
        private readonly Connection $db,
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

        $rows = $this->db->capsule()->table('group_membership')
            ->where('group_slug', $slug)
            ->orderBy('norad_id')
            ->pluck('norad_id')
            ->all();
        $members = array_map(static fn ($v): int => (int) $v, $rows);

        $response->getBody()->write(Json::encode([
            'data' => [
                'slug'        => $slug,
                'name'        => GroupListController::displayName($slug),
                'count'       => count($members),
                'norad_ids'   => $members,
            ],
        ]));
        return $response->withHeader('Cache-Control', 'public, max-age=300, stale-while-revalidate=600');
    }
}
