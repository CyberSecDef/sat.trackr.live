<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers\Text;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Http\Controllers\ReentrySerializer;
use SatTrackr\Services\TextRenderer;

/**
 * GET /text/decays?within_hours=168
 *
 * Server-rendered HTML mirror of /api/v1/reentries/upcoming.
 */
final class TextDecaysController
{
    private const DEFAULT_WINDOW_HOURS = 168;
    private const MAX_WINDOW_HOURS     = 720;

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
        $params = $request->getQueryParams();
        $hours  = min(self::MAX_WINDOW_HOURS, max(1, (int) ($params['within_hours'] ?? self::DEFAULT_WINDOW_HOURS)));

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $end = $now->modify("+{$hours} hours");
        $nowIso = $now->format('Y-m-d\TH:i:s\Z');
        $endIso = $end->format('Y-m-d\TH:i:s\Z');

        $rows = $this->db->capsule()->table('reentries as r')
            ->leftJoin('satellites as s', 's.norad_id', '=', 'r.norad_id')
            ->where('r.predicted_decay', '>=', $nowIso)
            ->where('r.predicted_decay', '<=', $endIso)
            ->orderBy('r.predicted_decay', 'asc')
            ->select(
                'r.*',
                's.name as satellite_name',
                's.object_type as object_type',
            )
            ->get();

        $reentries = [];
        foreach ($rows as $r) {
            $reentries[] = ReentrySerializer::summary($r);
        }

        $body = $this->renderer->renderInner('decays.php', [
            'reentries'   => $reentries,
            'count'       => count($reentries),
            'withinHours' => $hours,
            'now'         => $nowIso,
        ]);

        $html = $this->renderer->renderPage(
            title: 'Predicted reentries',
            body:  $body,
            activeNav: 'decays',
            description: count($reentries) . ' predicted reentries in the next ' . ($hours / 24) . ' days.',
        );

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
