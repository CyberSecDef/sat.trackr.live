<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\App\EnvLoader;
use SatTrackr\Services\AtomGenerator;
use SatTrackr\Services\EventsAggregator;

/**
 * GET /events.atom
 *
 * Atom 1.0 feed merging launches/reentries/conjunctions/storm-warnings
 * over the last 7 days (per the locked plan in docs/phase4.md §II row 7).
 * Cache 10 min — events update on every ingest run, but readers poll
 * once/day at most so freshness isn't load-bearing.
 */
final class AtomEventsController
{
    private const PAST_DAYS = 7;
    private const FUTURE_DAYS = 7;

    public function __construct(
        private readonly EventsAggregator $aggregator,
        private readonly AtomGenerator $atom,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(Request $request, Response $response, array $args = []): Response
    {
        $events = $this->aggregator->recent(self::PAST_DAYS, self::FUTURE_DAYS);
        // Newest first for feed readers.
        $events = array_reverse($events);

        $baseUrl = rtrim(EnvLoader::get('APP_URL', 'http://localhost:8000') ?? 'http://localhost:8000', '/');
        $selfUrl = $baseUrl . '/events.atom';
        $now     = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        $xml = $this->atom->build(
            events:    $events,
            feedTitle: 'sat.trackr.live — events',
            baseUrl:   $baseUrl,
            selfUrl:   $selfUrl,
            updated:   $now,
        );

        $response->getBody()->write($xml);
        return $response
            ->withHeader('Content-Type', 'application/atom+xml; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=600, stale-while-revalidate=1800');
    }
}
