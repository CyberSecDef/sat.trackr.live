<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Support\Json;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /api/v1/reentries/{norad}
 *
 * Returns the active reentry prediction for a NORAD ID. If multiple
 * sources have reported (TIP + SATCAT both), the most-recently-updated
 * row wins so the UI surfaces the freshest prediction.
 */
final class ReentryDetailController
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
        $norad = (int) ($args['norad'] ?? 0);
        if ($norad <= 0) {
            throw new HttpNotFoundException($request, 'Missing or invalid NORAD id');
        }

        $row = $this->db->capsule()->table('reentries as r')
            ->leftJoin('satellites as s', 's.norad_id', '=', 'r.norad_id')
            ->where('r.norad_id', $norad)
            ->orderBy('r.updated_at', 'desc')
            ->select(
                'r.*',
                's.name as satellite_name',
                's.intl_designator as intl_designator',
                's.object_type as object_type',
                's.country as country',
                's.operator as operator',
                's.launch_date as launch_date',
                's.mass_kg as mass_kg',
                's.rcs_meters as rcs_meters',
                's.status as satellite_status',
            )
            ->first();

        if ($row === null) {
            throw new HttpNotFoundException($request, "No reentry prediction for NORAD {$norad}");
        }

        $response->getBody()->write(Json::encode([
            'data' => ReentrySerializer::detail($row),
        ]));
        return $response->withHeader('Cache-Control', 'public, max-age=300, stale-while-revalidate=600');
    }
}
