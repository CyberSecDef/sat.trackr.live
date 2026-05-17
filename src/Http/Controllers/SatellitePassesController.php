<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Services\PassCache;
use SatTrackr\Services\PassCalculatorInterface;
use SatTrackr\Services\PassMagnitudeEnricher;
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
        private readonly ?PassMagnitudeEnricher $enricher = null,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    #[OA\Get(
        path: '/api/v1/satellites/{norad}/passes',
        summary: 'Observer-local pass predictions (SGP4 + optional N2YO magnitude)',
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'norad', in: 'path',  required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'lat',   in: 'query', required: true, schema: new OA\Schema(type: 'number'), description: 'Observer latitude in degrees'),
            new OA\Parameter(name: 'lon',   in: 'query', required: true, schema: new OA\Schema(type: 'number'), description: 'Observer longitude in degrees'),
            new OA\Parameter(name: 'alt',   in: 'query', schema: new OA\Schema(type: 'number', default: 0), description: 'Observer altitude in meters'),
            new OA\Parameter(name: 'days',  in: 'query', schema: new OA\Schema(type: 'integer', default: 7, maximum: 14)),
            new OA\Parameter(name: 'min_elevation_deg', in: 'query', schema: new OA\Schema(type: 'number', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Up to N upcoming passes, cached for ~6h per observer-day', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Pass')),
                new OA\Property(property: 'meta', type: 'object', properties: [
                    new OA\Property(property: 'norad_id', type: 'integer'),
                    new OA\Property(property: 'from_cache', type: 'boolean'),
                    new OA\Property(property: 'observer', type: 'object'),
                ]),
            ])),
            new OA\Response(response: 400, description: 'Missing/invalid observer params', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Unknown NORAD', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
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
            // Phase 4 chunk 7B — best-effort N2YO magnitude enrichment.
            // Service degrades silently on missing key / quota / error;
            // unenriched passes have `magnitude: null`.
            $passes = $this->enricher !== null
                ? $this->enricher->enrich($result['passes'], $norad, $lat, $lon, $alt, $days)
                : $result['passes'];
            $payload = ['count' => count($passes), 'passes' => $passes];
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
