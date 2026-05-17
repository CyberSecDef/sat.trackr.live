<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Ingest\CelesTrakGroups;
use SatTrackr\Support\Json;

/**
 * GET /api/v1/groups
 *
 * Lists all configured CelesTrak groups with their current member counts.
 * Counts come from group_membership (populated by the ingester).
 */
final class GroupListController
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    #[OA\Get(
        path: '/api/v1/groups',
        summary: 'CelesTrak constellation groups with current member counts',
        tags: ['Groups'],
        responses: [
            new OA\Response(response: 200, description: 'All configured groups', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GroupSummary')),
                new OA\Property(property: 'meta', type: 'object', properties: [
                    new OA\Property(property: 'total_groups', type: 'integer'),
                    new OA\Property(property: 'source',       type: 'string'),
                ]),
            ])),
        ],
    )]
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
                'name'  => self::displayName($slug),
                'count' => $counts[$slug] ?? 0,
            ];
        }

        $response->getBody()->write(Json::encode([
            'data' => $data,
            'meta' => [
                'total_groups' => count($data),
                'source'       => 'celestrak',
            ],
        ]));
        return $response->withHeader('Cache-Control', 'public, max-age=3600, stale-while-revalidate=7200');
    }

    public static function displayName(string $slug): string
    {
        // A small set of display-name overrides; everything else is title-cased
        // from the slug with hyphens turned to spaces.
        return match ($slug) {
            'active'        => 'Active satellites',
            'stations'      => 'Space stations',
            'last-30-days'  => 'Launched in the last 30 days',
            'analyst'       => 'Analyst objects',
            'noaa'          => 'NOAA',
            'goes'          => 'GOES',
            'sarsat'        => 'Search & rescue',
            'dmc'           => 'Disaster monitoring',
            'geo'           => 'Geostationary',
            'ses'           => 'SES',
            'iridium-NEXT'  => 'Iridium NEXT',
            'starlink'      => 'Starlink',
            'oneweb'        => 'OneWeb',
            'orbcomm'       => 'Orbcomm',
            'globalstar'    => 'Globalstar',
            'amateur'       => 'Amateur radio',
            'gnss'          => 'GNSS (all)',
            'gps-ops'       => 'GPS operational',
            'glo-ops'       => 'GLONASS operational',
            'galileo'       => 'Galileo',
            'beidou'        => 'BeiDou',
            'sbas'          => 'Satellite-based augmentation',
            'musson'        => 'Russian LEO navigation',
            'gps'           => 'GPS',
            'cubesat'       => 'CubeSats',
            'military'      => 'Military',
            'radar'         => 'Radar calibration',
            default         => ucwords(str_replace('-', ' ', $slug)),
        };
    }
}
