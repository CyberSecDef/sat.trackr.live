<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Services\PassCache;
use SatTrackr\Services\PassCalculatorInterface;
use SatTrackr\Support\Json;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /api/v1/satellites/{norad}/passes?lat&lon&alt&days&min_elevation_deg
 *
 * Looks up the current TLE for the NORAD, builds a pass-cache key from
 * (NORAD, lat-3dp, lon-3dp, today), and returns either the cached
 * predictions or freshly-computed ones via the Node subprocess.
 *
 * `days` is currently used by the calculator only; the cache key is
 * day-bucketed so the first request of any day populates a single row
 * that subsequent requests within ±~110m + 6h reuse.
 */
final class SatellitePassesController
{
    private const DEFAULT_DAYS = 7;
    private const MAX_DAYS = 14;
    private const DEFAULT_MIN_EL = 10.0;

    public function __construct(
        private readonly Connection $db,
        private readonly PassCalculatorInterface $calculator,
        private readonly PassCache $cache,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $norad = (int) ($args['norad'] ?? 0);
        if ($norad <= 0) {
            throw new HttpNotFoundException($request, 'Invalid NORAD ID');
        }

        $params = $request->getQueryParams();
        if (!isset($params['lat'], $params['lon'])) {
            throw new HttpBadRequestException($request, 'lat and lon query params are required');
        }

        $lat = (float) $params['lat'];
        $lon = (float) $params['lon'];
        $alt = isset($params['alt']) ? (float) $params['alt'] : 0.0;
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            throw new HttpBadRequestException($request, 'lat must be in [-90, 90] and lon in [-180, 180]');
        }

        $days = min(self::MAX_DAYS, max(1, (int) ($params['days'] ?? self::DEFAULT_DAYS)));
        $minEl = max(0.0, (float) ($params['min_elevation_deg'] ?? self::DEFAULT_MIN_EL));

        $tle = $this->db->capsule()->table('tle_current')->where('norad_id', $norad)->first();
        if ($tle === null) {
            throw new HttpNotFoundException($request, "TLE for satellite {$norad} not found");
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $day = $now->format('Y-m-d');

        $cached = $this->cache->get($norad, $lat, $lon, $day);
        $fromCache = $cached !== null;
        if ($cached !== null) {
            $payload = $cached;
        } else {
            $result = $this->calculator->compute(
                tle: ['line1' => (string) $tle->line1, 'line2' => (string) $tle->line2],
                observer: ['latitude' => $lat, 'longitude' => $lon, 'altitudeMeters' => $alt],
                startMs: (int) ($now->format('U') . sprintf('%03d', (int) $now->format('v'))),
                days: $days,
                minElevationDeg: $minEl,
            );
            $payload = ['count' => (int) $result['count'], 'passes' => $result['passes']];
            $this->cache->put($norad, $lat, $lon, $alt, $day, $payload);
        }

        $response->getBody()->write(Json::encode([
            'data' => $payload['passes'],
            'meta' => [
                'norad_id'          => $norad,
                'count'             => $payload['count'] ?? count($payload['passes'] ?? []),
                'observer'          => ['latitude' => $lat, 'longitude' => $lon, 'altitudeMeters' => $alt],
                'days'              => $days,
                'min_elevation_deg' => $minEl,
                'from_cache'        => $fromCache,
                'computed_at'       => $now->format('Y-m-d\TH:i:s\Z'),
            ],
        ]));
        return $response->withHeader('Cache-Control', 'public, max-age=300, stale-while-revalidate=600');
    }
}
