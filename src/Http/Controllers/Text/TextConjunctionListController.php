<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers\Text;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Http\Controllers\ConjunctionSerializer;
use SatTrackr\Services\TextRenderer;

/**
 * GET /text/conjunctions?within_hours=24&min_probability=0&limit=50
 *
 * Server-rendered mirror of /api/v1/conjunctions/upcoming.  Defaults
 * match the JSON endpoint: 24h window, top-50 by probability.
 */
final class TextConjunctionListController
{
    private const DEFAULT_WINDOW_HOURS = 24;
    private const MAX_WINDOW_HOURS     = 720;
    private const DEFAULT_LIMIT        = 50;
    private const MAX_LIMIT            = 500;

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
        $params  = $request->getQueryParams();
        $hours   = min(self::MAX_WINDOW_HOURS, max(1, (int) ($params['within_hours'] ?? self::DEFAULT_WINDOW_HOURS)));
        $minProb = max(0.0, (float) ($params['min_probability'] ?? 0.0));
        $limit   = min(self::MAX_LIMIT, max(1, (int) ($params['limit'] ?? self::DEFAULT_LIMIT)));

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nowIso = $now->format('Y-m-d\TH:i:s\Z');
        $endIso = $now->modify("+{$hours} hours")->format('Y-m-d\TH:i:s\Z');

        $query = $this->db->capsule()->table('conjunctions as c')
            ->leftJoin('satellites as p', 'p.norad_id', '=', 'c.norad_id_primary')
            ->leftJoin('satellites as s', 's.norad_id', '=', 'c.norad_id_secondary')
            ->where('c.tca', '>=', $nowIso)
            ->where('c.tca', '<=', $endIso);
        if ($minProb > 0) {
            $query->where('c.max_probability', '>=', $minProb);
        }
        $total = (int) (clone $query)->count();
        $rows = $query
            ->orderByRaw('c.max_probability IS NULL, c.max_probability DESC')
            ->orderBy('c.tca', 'asc')
            ->select(
                'c.*',
                'p.object_type as primary_object_type',
                'p.country     as primary_country',
                's.object_type as secondary_object_type',
                's.country     as secondary_country',
            )
            ->limit($limit)
            ->get();

        $conjunctions = [];
        foreach ($rows as $r) {
            $conjunctions[] = ConjunctionSerializer::summary($r);
        }

        $body = $this->renderer->renderInner('conjunctions.php', [
            'conjunctions'   => $conjunctions,
            'count'          => count($conjunctions),
            'total'          => $total,
            'withinHours'    => $hours,
            'minProbability' => $minProb,
            'limit'          => $limit,
            'now'            => $nowIso,
        ]);

        $html = $this->renderer->renderPage(
            title: 'Predicted conjunctions',
            body:  $body,
            activeNav: 'conjunctions',
            description: "Top {$limit} of {$total} predicted close-approaches in the next {$hours} hours.",
            canonicalPath: '/text/conjunctions',
            // Phase 5 chunk 5 — schema.org CollectionPage for the listing.
            jsonLd: [
                '@context'    => 'https://schema.org',
                '@type'       => 'CollectionPage',
                'name'        => 'Predicted conjunctions — sat.trackr.live',
                'description' => "Top {$limit} of {$total} predicted close-approaches in the next {$hours} hours.",
                'url'         => '/text/conjunctions',
            ],
        );

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
