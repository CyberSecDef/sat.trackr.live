<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use RuntimeException;

/**
 * Phase 4 chunk 4A — fetcher for NOAA's OVATION-Prime aurora forecast.
 *
 * The endpoint returns a 1°×1° lat/lon grid with aurora probability
 * (0-100) per cell, refreshed every ~15 minutes:
 *
 *   https://services.swpc.noaa.gov/json/ovation_aurora_latest.json
 *
 * Payload shape:
 *   {
 *     "Observation Time": "...",
 *     "Forecast Time":    "...",
 *     "Data Format":      "[Longitude, Latitude, Aurora]",
 *     "coordinates":      [[lon, lat, prob], …]      // 65,160 cells
 *   }
 */
final class OvationClient
{
    public const URL = 'https://services.swpc.noaa.gov/json/ovation_aurora_latest.json';

    public function __construct(
        private readonly ClientInterface $http,
    ) {
    }

    /**
     * @return array{
     *   observation_time: string,
     *   forecast_time:    string,
     *   coordinates:      list<array{0: int, 1: int, 2: int}>
     * }
     */
    public function fetchLatest(): array
    {
        try {
            $response = $this->http->request('GET', self::URL, [
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (BadResponseException $e) {
            $code = $e->getResponse()->getStatusCode();
            $body = substr((string) $e->getResponse()->getBody(), 0, 200);
            throw new RuntimeException("OVATION fetch failed ({$code}): {$body}", 0, $e);
        }

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['coordinates']) || !is_array($decoded['coordinates'])) {
            throw new RuntimeException('OVATION returned malformed JSON');
        }

        /** @var list<array{0: int, 1: int, 2: int}> $coords */
        $coords = $decoded['coordinates'];

        return [
            'observation_time' => (string) ($decoded['Observation Time'] ?? ''),
            'forecast_time'    => (string) ($decoded['Forecast Time'] ?? ''),
            'coordinates'      => $coords,
        ];
    }
}
