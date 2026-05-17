<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Services\OgImageGenerator;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /og/{type}/{id?}.png — serves a cached or freshly-generated OG card.
 *
 * Cache: `storage/cache/og/{type}-{id-or-hash}.png`, regenerated every
 * 6 h.  Responses send Cache-Control: public, max-age=21600 so the CDN
 * + browser keep their copies for the same window.
 *
 * Three routes share this controller via the `{type}` path arg:
 *   - satellite / {norad}   -> SatelliteDetail card
 *   - launch    / {id}      -> Launch card
 *   - events    / (none)    -> Top-conjunctions card (daily-bucketed cache key)
 *
 * 404 only when the underlying row is missing.  Generation never fails
 * silently — exceptions bubble to the global error handler.
 */
final class OgImageController
{
    private const CACHE_TTL_SECONDS = 6 * 3600;

    public function __construct(
        private readonly OgImageGenerator $generator,
        private readonly Connection $db,
        private readonly string $cacheDir,
    ) {
    }

    /** @param array<string, string> $args */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $type = (string) ($args['type'] ?? '');
        $id   = (string) ($args['id'] ?? '');

        $cacheFile = $this->cacheFile($type, $id);
        if ($this->cacheHit($cacheFile)) {
            return $this->emit($response, (string) file_get_contents($cacheFile));
        }

        $png = match ($type) {
            'satellite' => $this->renderSatellite($request, (int) $id),
            'launch'    => $this->renderLaunch($request, $id),
            'events'    => $this->renderEvents(),
            default     => throw new HttpNotFoundException($request, "Unknown OG card type '{$type}'"),
        };

        $this->writeCache($cacheFile, $png);
        return $this->emit($response, $png);
    }

    private function renderSatellite(Request $request, int $norad): string
    {
        if ($norad <= 0) {
            throw new HttpNotFoundException($request, 'Invalid NORAD ID');
        }
        $row = $this->db->capsule()->table('satellites')->where('norad_id', $norad)->first();
        if ($row === null) {
            throw new HttpNotFoundException($request, "Satellite {$norad} not found");
        }
        return $this->generator->renderSatellite(
            name:           (string) $row->name,
            norad:          (int) $row->norad_id,
            intlDesignator: $row->intl_designator !== null ? (string) $row->intl_designator : null,
            objectType:     $row->object_type     !== null ? (string) $row->object_type     : null,
            orbitClass:     $row->orbit_class     !== null ? (string) $row->orbit_class     : null,
            country:        $row->country         !== null ? (string) $row->country         : null,
        );
    }

    private function renderLaunch(Request $request, string $id): string
    {
        if ($id === '') {
            throw new HttpNotFoundException($request, 'Missing launch id');
        }
        $row = $this->db->capsule()->table('launches')
            ->leftJoin('launch_sites', 'launches.pad_id', '=', 'launch_sites.id')
            ->where('launches.id', $id)
            ->select(
                'launches.name     as name',
                'launches.net      as net',
                'launches.provider as provider',
                'launches.vehicle  as vehicle',
                'launch_sites.name as pad_name',
            )
            ->first();
        if ($row === null) {
            throw new HttpNotFoundException($request, "Launch '{$id}' not found");
        }
        return $this->generator->renderLaunch(
            name:     (string) $row->name,
            provider: $row->provider !== null ? (string) $row->provider : null,
            rocket:   $row->vehicle  !== null ? (string) $row->vehicle  : null,
            padName:  $row->pad_name !== null ? (string) $row->pad_name : null,
            net:      $row->net      !== null ? (string) $row->net      : null,
        );
    }

    private function renderEvents(): string
    {
        /** @var list<\stdClass> $rows */
        $rows = $this->db->capsule()->table('conjunctions')
            ->orderByDesc('max_probability')
            ->orderBy('tca')
            ->limit(5)
            ->get(['tca', 'norad_id_primary', 'norad_id_secondary', 'tca_range_km'])
            ->all();
        $mapped = array_map(static fn (\stdClass $r): array => [
            'primary'   => (int) $r->norad_id_primary,
            'secondary' => (int) $r->norad_id_secondary,
            'miss_km'   => (float) $r->tca_range_km,
            'tca'       => (string) $r->tca,
        ], $rows);

        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        return $this->generator->renderEvents(
            title:    'Top conjunctions',
            subtitle: "Most-likely close approaches · {$today} UTC",
            rows:     $mapped,
        );
    }

    private function cacheFile(string $type, string $id): string
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
            throw new \RuntimeException("Could not create OG cache dir: {$this->cacheDir}");
        }
        $key = match ($type) {
            // Events bucket per UTC day so the cache file ages on its own.
            'events' => 'events-' . (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d'),
            default  => "{$type}-" . preg_replace('/[^A-Za-z0-9_-]/', '_', $id),
        };
        return "{$this->cacheDir}/{$key}.png";
    }

    private function cacheHit(string $file): bool
    {
        return is_file($file) && (time() - filemtime($file)) < self::CACHE_TTL_SECONDS;
    }

    private function writeCache(string $file, string $png): void
    {
        @file_put_contents($file, $png, LOCK_EX);
    }

    private function emit(Response $response, string $png): Response
    {
        $response->getBody()->write($png);
        return $response
            ->withHeader('Content-Type', 'image/png')
            ->withHeader('Cache-Control', 'public, max-age=' . self::CACHE_TTL_SECONDS . ', stale-while-revalidate=86400');
    }
}
