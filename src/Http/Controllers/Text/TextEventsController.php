<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers\Text;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Services\EventsAggregator;
use SatTrackr\Services\TextRenderer;

/**
 * GET /text/events
 *
 * Server-rendered chronological event feed sourced from
 * {@see EventsAggregator}.  Default window 7d past + 7d future,
 * configurable via ?past=N and ?future=N.
 */
final class TextEventsController
{
    private const MAX_DAYS = 30;

    public function __construct(
        private readonly EventsAggregator $aggregator,
        private readonly TextRenderer $renderer,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(Request $request, Response $response, array $args = []): Response
    {
        $params = $request->getQueryParams();
        $past   = min(self::MAX_DAYS, max(0, (int) ($params['past']   ?? 7)));
        $future = min(self::MAX_DAYS, max(0, (int) ($params['future'] ?? 7)));

        $events = $this->aggregator->recent($past, $future);

        // Reverse so the newest events render first — feels more like a feed.
        $events = array_reverse($events);

        $body = $this->renderer->renderInner('events.php', [
            'events' => $events,
            'past'   => $past,
            'future' => $future,
            'now'    => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ]);
        $html = $this->renderer->renderPage(
            title: 'Events feed',
            body:  $body,
            activeNav: 'events',
            description: count($events) . " events in the last {$past}d and next {$future}d.",
        );
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
