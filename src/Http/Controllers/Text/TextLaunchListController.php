<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers\Text;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Http\Controllers\LaunchSerializer;
use SatTrackr\Services\TextRenderer;

/**
 * GET /text/launches            — upcoming launches list
 * GET /text/launches/recent     — last 90 days
 *
 * Server-rendered HTML mirroring the chunk-3 launch JSON endpoints.
 */
final class TextLaunchListController
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
        // Slim doesn't pass route metadata directly; use the URL path to disambiguate.
        $isRecent = str_ends_with($request->getUri()->getPath(), '/recent');
        $mode = $isRecent ? 'recent' : 'upcoming';
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        $q = $this->db->capsule()->table('launches as l')
            ->leftJoin('launch_sites as p', 'p.id', '=', 'l.pad_id')
            ->select(
                'l.*',
                'p.name as pad_name',
                'p.latitude as pad_latitude',
                'p.longitude as pad_longitude',
                'p.country as pad_country',
                'p.operator as pad_operator',
            );

        if ($isRecent) {
            $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('-90 days')
                ->format('Y-m-d\TH:i:s\Z');
            $rows = $q->where('l.net', '<', $now)
                ->where('l.net', '>=', $cutoff)
                ->orderBy('l.net', 'desc')
                ->limit(100)
                ->get();
        } else {
            $rows = $q->where('l.net', '>=', $now)
                ->orderBy('l.net', 'asc')
                ->limit(50)
                ->get();
        }

        $launches = [];
        foreach ($rows as $r) {
            $launches[] = LaunchSerializer::summary($r);
        }

        $body = $this->renderer->renderInner('launches.php', [
            'launches' => $launches,
            'mode'     => $mode,
            'count'    => count($launches),
            'total'    => count($launches),
            'now'      => $now,
        ]);

        $html = $this->renderer->renderPage(
            title: $isRecent ? 'Recent launches' : 'Upcoming launches',
            body: $body,
            activeNav: 'launches',
            description: $isRecent
                ? 'Last 90 days of orbital launches, sorted most-recent first.'
                : 'Next ' . count($launches) . ' upcoming orbital launches with countdowns to NET.',
        );
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
