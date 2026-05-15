<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Support\Json;

/**
 * GET /api/v1/launch-sites
 *
 * All launch pads currently in the catalog, with their location info.
 * Cached aggressively (24h) — pad list barely changes.
 */
final class LaunchSiteListController
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(Request $request, Response $response, array $args = []): Response
    {
        $rows = $this->db->capsule()->table('launch_sites')
            ->orderBy('name')
            ->get();

        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                'id'        => (int) $r->id,
                'name'      => $r->name,
                'latitude'  => $r->latitude  !== null ? (float) $r->latitude  : null,
                'longitude' => $r->longitude !== null ? (float) $r->longitude : null,
                'country'   => $r->country,
                'operator'  => $r->operator,
                'url'       => $r->url,
            ];
        }

        $response->getBody()->write(Json::encode([
            'data' => $data,
            'meta' => ['count' => count($data)],
        ]));
        return $response->withHeader('Cache-Control', 'public, max-age=86400');
    }
}
