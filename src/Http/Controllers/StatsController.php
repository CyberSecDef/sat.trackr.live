<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Support\Json;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /api/v1/stats/{breakdown}
 *
 *   summary         single dashboard object — totals by status/type/orbit-class,
 *                   total tracked mass (when known), top-5 operators + countries
 *   operators       top-N by satellite count (default 50, max 200)
 *   countries       top-N by satellite count (default 50, max 200)
 *   types           PAYLOAD / ROCKET_BODY / DEBRIS / TBA / UNKNOWN (small fixed set)
 *   launch-years    per-year launch counts (filter by ?since=YYYY, default 1957)
 *
 * Pure aggregations over the existing `satellites` table — no new
 * ingest path.  Indexes cover the GROUP BY paths (country / operator /
 * object_type / status / launch_date).  Cache aggressively: stats only
 * change after `make ingest` or `make ingest-satcat` runs.
 */
final class StatsController
{
    private const VALID = ['summary', 'operators', 'countries', 'types', 'launch-years'];

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string, string> $args
     */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $breakdown = (string) ($args['breakdown'] ?? '');
        if (!in_array($breakdown, self::VALID, true)) {
            throw new HttpNotFoundException(
                $request,
                "Unknown stats breakdown '{$breakdown}' — valid: " . implode(', ', self::VALID)
            );
        }
        $params = $request->getQueryParams();

        $payload = match ($breakdown) {
            'summary'      => $this->summary(),
            'operators'    => $this->topGroup('operator', $params),
            'countries'    => $this->topGroup('country',  $params),
            'types'        => $this->byObjectType(),
            'launch-years' => $this->launchYears($params),
        };

        $response->getBody()->write(Json::encode($payload));
        return $response->withHeader('Cache-Control', 'public, max-age=900, stale-while-revalidate=3600');
    }

    // ─── breakdowns ───────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function summary(): array
    {
        $cap = $this->db->capsule();
        $total = (int) $cap->table('satellites')->count();

        $byType = $cap->table('satellites')
            ->select('object_type', $this->db->capsule()->getConnection()->raw('COUNT(*) as n'))
            ->groupBy('object_type')
            ->pluck('n', 'object_type')
            ->all();

        $byStatus = $cap->table('satellites')
            ->select('status', $this->db->capsule()->getConnection()->raw('COUNT(*) as n'))
            ->groupBy('status')
            ->pluck('n', 'status')
            ->all();

        $byOrbit = $cap->table('satellites')
            ->select('orbit_class', $this->db->capsule()->getConnection()->raw('COUNT(*) as n'))
            ->groupBy('orbit_class')
            ->pluck('n', 'orbit_class')
            ->all();

        $massStmt = $this->db->pdo()->query(
            'SELECT COUNT(mass_kg) AS known_count, SUM(mass_kg) AS total_kg, AVG(mass_kg) AS avg_kg '
            . 'FROM satellites WHERE mass_kg IS NOT NULL'
        );
        $massRow = $massStmt !== false ? $massStmt->fetch(\PDO::FETCH_ASSOC) : false;

        return [
            'data' => [
                'total'         => $total,
                'by_type'       => self::stringKeyedInts($byType),
                'by_status'     => self::stringKeyedInts($byStatus),
                'by_orbit_class' => self::stringKeyedInts($byOrbit),
                'mass'          => [
                    'known_count' => is_array($massRow) ? (int) ($massRow['known_count'] ?? 0) : 0,
                    'total_kg'    => is_array($massRow) && $massRow['total_kg'] !== null ? (float) $massRow['total_kg'] : null,
                    'avg_kg'      => is_array($massRow) && $massRow['avg_kg']   !== null ? (float) $massRow['avg_kg']   : null,
                ],
                'top_operators' => $this->topGroup('operator', ['limit' => '5'])['data'],
                'top_countries' => $this->topGroup('country',  ['limit' => '5'])['data'],
            ],
        ];
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function topGroup(string $column, array $params): array
    {
        $limit = min(200, max(1, (int) ($params['limit'] ?? 50)));
        $rows = $this->db->capsule()->getConnection()
            ->select(
                "SELECT {$column} AS key, COUNT(*) AS n "
                . 'FROM satellites '
                . "WHERE {$column} IS NOT NULL AND {$column} != '' "
                . "GROUP BY {$column} "
                . 'ORDER BY n DESC '
                . 'LIMIT ?',
                [$limit]
            );

        $data = [];
        foreach ($rows as $r) {
            $data[] = ['key' => (string) $r->key, 'count' => (int) $r->n];
        }
        return [
            'data' => $data,
            'meta' => ['column' => $column, 'limit' => $limit, 'count' => count($data)],
        ];
    }

    /** @return array<string, mixed> */
    private function byObjectType(): array
    {
        $cap = $this->db->capsule();
        $rows = $cap->table('satellites')
            ->select('object_type as key', $cap->getConnection()->raw('COUNT(*) as n'))
            ->groupBy('object_type')
            ->orderByRaw('COUNT(*) DESC')
            ->get()
            ->all();
        $data = [];
        $total = 0;
        foreach ($rows as $r) {
            $count = (int) $r->n;
            $total += $count;
            $data[] = ['key' => (string) $r->key, 'count' => $count];
        }
        $totalSafe = max(1, $total);
        foreach ($data as &$r) {
            $r['percent'] = round($r['count'] * 100.0 / $totalSafe, 2);
        }
        unset($r);
        return [
            'data' => $data,
            'meta' => ['total' => $total],
        ];
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function launchYears(array $params): array
    {
        $since = max(1957, (int) ($params['since'] ?? 1957));
        $rows = $this->db->pdo()->prepare(
            "SELECT CAST(strftime('%Y', launch_date) AS INTEGER) AS year, COUNT(*) AS n "
            . 'FROM satellites '
            . "WHERE launch_date IS NOT NULL AND launch_date >= :since "
            . 'GROUP BY year '
            . 'ORDER BY year ASC'
        );
        if ($rows === false) {
            return ['data' => [], 'meta' => ['since' => $since]];
        }
        $rows->execute(['since' => sprintf('%04d-01-01', $since)]);
        $out = [];
        foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[] = ['year' => (int) $r['year'], 'count' => (int) $r['n']];
        }
        return [
            'data' => $out,
            'meta' => ['since' => $since, 'count' => count($out)],
        ];
    }

    /**
     * @param iterable<int|string, mixed> $kv
     * @return array<string, int>
     */
    private static function stringKeyedInts(iterable $kv): array
    {
        $out = [];
        foreach ($kv as $k => $v) {
            if ($k === null) continue;
            $out[(string) $k] = (int) $v;
        }
        return $out;
    }
}
