<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Support\Json;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /api/v1/launches/{id}
 *
 * Full launch detail including the joined pad row and the parsed JSON
 * array of associated NORAD IDs (populated lazily as TLEs match the
 * launch date — chunk 4+).
 */
final class LaunchDetailController
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
        $id = (string) ($args['id'] ?? '');
        if ($id === '') {
            throw new HttpNotFoundException($request, 'Missing launch id');
        }

        $row = $this->db->capsule()->table('launches as l')
            ->leftJoin('launch_sites as p', 'p.id', '=', 'l.pad_id')
            ->where('l.id', $id)
            ->select(
                'l.*',
                'p.name as pad_name',
                'p.latitude as pad_latitude',
                'p.longitude as pad_longitude',
                'p.country as pad_country',
                'p.operator as pad_operator',
                'p.url as pad_url',
            )
            ->first();
        if ($row === null) {
            throw new HttpNotFoundException($request, "Launch '{$id}' not found");
        }

        $response->getBody()->write(Json::encode([
            'data' => LaunchSerializer::detail($row),
        ]));
        return $response->withHeader('Cache-Control', 'public, max-age=300');
    }
}
