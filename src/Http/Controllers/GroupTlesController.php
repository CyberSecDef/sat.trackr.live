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
 * GET /api/v1/groups/{slug}/tles
 *
 * The hot SPA endpoint — returns one big JSON blob with every current
 * TLE in the group. Designed for client-side bulk propagation. Apache /
 * the CDN should gzip; the same TLE strings repeat in payload-friendly ways.
 */
final class GroupTlesController
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

        // Hide DECAYED objects (per docs/phase2.md decision 9). They have
        // stale TLEs that propagate to incorrect positions; rendering them
        // on the globe is misleading. ~thousands of objects per ingest.
        $rows = $this->db->capsule()->table('tle_current as t')
            ->join('group_membership as g', 'g.norad_id', '=', 't.norad_id')
            ->join('satellites as s', 's.norad_id', '=', 't.norad_id')
            ->where('g.group_slug', $slug)
            ->where('s.status', '!=', 'DECAYED')
            ->orderBy('t.norad_id')
            ->select(
                't.norad_id',
                's.name',
                't.line1',
                't.line2',
                's.object_type',
            )
            ->get();

        $tles = [];
        foreach ($rows as $r) {
            $tles[] = [
                'norad_id'    => (int) $r->norad_id,
                'name'        => $r->name,
                'line1'       => $r->line1,
                'line2'       => $r->line2,
                'object_type' => $r->object_type,
            ];
        }

        $response->getBody()->write(Json::encode([
            'group'        => $slug,
            'name'         => GroupListController::displayName($slug),
            'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'count'        => count($tles),
            'tles'         => $tles,
        ]));
        return $response
            ->withHeader('Cache-Control', 'public, max-age=300, stale-while-revalidate=600');
    }
}
