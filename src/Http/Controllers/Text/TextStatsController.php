<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers\Text;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Services\TextRenderer;

/**
 * GET /text/stats
 *
 * Server-rendered dashboard mirroring the chunk-5A JSON endpoints
 * in one page.  Pulls the data directly from the DB rather than
 * fanning out to the JSON controllers — same SQL, simpler call
 * stack, easier to test against a seeded fixture.
 */
final class TextStatsController
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
        $cap = $this->db->capsule();
        $pdo = $this->db->pdo();

        $total    = (int) $cap->table('satellites')->count();
        $byType   = $this->groupBy('object_type', 10);
        $byStatus = $this->groupBy('status', 10);
        $countries = $this->groupBy('country', 20);
        $operators = $this->groupBy('operator', 20);

        $massStmt = $pdo->query('SELECT COUNT(mass_kg) AS n, SUM(mass_kg) AS s FROM satellites WHERE mass_kg IS NOT NULL');
        $massRow = $massStmt !== false ? $massStmt->fetch(\PDO::FETCH_ASSOC) : false;
        $massKnown = is_array($massRow) ? (int) ($massRow['n'] ?? 0) : 0;
        $massTotal = is_array($massRow) && $massRow['s'] !== null ? (float) $massRow['s'] : null;

        $yearsStmt = $pdo->query(
            "SELECT CAST(strftime('%Y', launch_date) AS INTEGER) AS year, COUNT(*) AS n "
            . 'FROM satellites WHERE launch_date IS NOT NULL '
            . 'GROUP BY year ORDER BY year ASC'
        );
        $years = $yearsStmt !== false ? $yearsStmt->fetchAll(\PDO::FETCH_ASSOC) : [];

        $body = $this->renderer->renderInner('stats.php', [
            'total'      => $total,
            'byType'     => $byType,
            'byStatus'   => $byStatus,
            'countries'  => $countries,
            'operators'  => $operators,
            'massKnown'  => $massKnown,
            'massTotal'  => $massTotal,
            'years'      => $years,
        ]);

        $html = $this->renderer->renderPage(
            title: 'Catalog stats',
            body:  $body,
            activeNav: 'stats',
            description: "Live aggregations over the {$total}-satellite catalog: country / operator / type / launch year / mass-in-orbit breakdowns.",
        );

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * @return list<array{key: string, n: int}>
     */
    private function groupBy(string $column, int $limit): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT {$column} AS key, COUNT(*) AS n "
            . 'FROM satellites '
            . "WHERE {$column} IS NOT NULL AND {$column} != '' "
            . "GROUP BY {$column} "
            . 'ORDER BY n DESC '
            . 'LIMIT :limit'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[] = ['key' => (string) $row['key'], 'n' => (int) $row['n']];
        }
        return $out;
    }
}
