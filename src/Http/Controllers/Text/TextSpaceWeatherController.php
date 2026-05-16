<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers\Text;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Http\Controllers\SpaceWeatherSerializer;
use SatTrackr\Services\TextRenderer;

/**
 * GET /text/space-weather
 *
 * Server-rendered mirror of /api/v1/space-weather/now + /24h.
 */
final class TextSpaceWeatherController
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
        $cap = $this->db->capsule()->table('space_weather_samples');

        $current = $cap->orderBy('sampled_at', 'desc')->first();
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-24 hours')
            ->format('Y-m-d\TH:i:s\Z');
        $trendRows = $this->db->capsule()->table('space_weather_samples')
            ->where('sampled_at', '>=', $cutoff)
            ->orderBy('sampled_at', 'desc')
            ->limit(100)
            ->get();

        $trend = [];
        foreach ($trendRows as $row) {
            $trend[] = SpaceWeatherSerializer::sample($row);
        }

        $body = $this->renderer->renderInner('space_weather.php', [
            'current' => $current !== null ? SpaceWeatherSerializer::sample($current) : null,
            'trend'   => $trend,
        ]);

        $html = $this->renderer->renderPage(
            title: 'Space weather',
            body:  $body,
            activeNav: 'weather',
            description: 'Current NOAA SWPC space-weather indicators + 24h sample history.',
        );

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
