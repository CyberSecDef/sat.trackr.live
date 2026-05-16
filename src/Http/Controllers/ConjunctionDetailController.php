<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Support\Json;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /api/v1/conjunctions/{primary}/{secondary}
 *
 * Returns every active conjunction prediction for a given object
 * pair, ordered by TCA ASC.  A single pair often has multiple
 * predicted close approaches over a 30-day window (one per orbital
 * intersection); the detail endpoint surfaces them all so the user
 * can see the encounter cadence.
 *
 * Pair is order-insensitive — `/123/456` and `/456/123` return the
 * same rows.
 */
final class ConjunctionDetailController
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string, string> $args
     */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $a = (int) ($args['primary'] ?? 0);
        $b = (int) ($args['secondary'] ?? 0);
        if ($a <= 0 || $b <= 0) {
            throw new HttpNotFoundException($request, 'Both NORAD IDs are required');
        }

        $rows = $this->db->capsule()->table('conjunctions as c')
            ->leftJoin('satellites as p', 'p.norad_id', '=', 'c.norad_id_primary')
            ->leftJoin('satellites as s', 's.norad_id', '=', 'c.norad_id_secondary')
            ->where(function ($q) use ($a, $b): void {
                $q->where(function ($qq) use ($a, $b): void {
                    $qq->where('c.norad_id_primary', $a)
                       ->where('c.norad_id_secondary', $b);
                })->orWhere(function ($qq) use ($a, $b): void {
                    $qq->where('c.norad_id_primary', $b)
                       ->where('c.norad_id_secondary', $a);
                });
            })
            ->orderBy('c.tca', 'asc')
            ->select(
                'c.*',
                'p.object_type as primary_object_type',
                'p.country     as primary_country',
                's.object_type as secondary_object_type',
                's.country     as secondary_country',
            )
            ->get();

        if ($rows->isEmpty()) {
            throw new HttpNotFoundException($request, "No conjunction predictions for pair {$a}×{$b}");
        }

        $data = [];
        foreach ($rows as $r) {
            $data[] = ConjunctionSerializer::detail($r);
        }

        $response->getBody()->write(Json::encode([
            'data' => $data,
            'meta' => [
                'count'              => count($data),
                'norad_id_primary'   => $a,
                'norad_id_secondary' => $b,
            ],
        ]));
        return $response->withHeader('Cache-Control', 'public, max-age=300, stale-while-revalidate=600');
    }
}
