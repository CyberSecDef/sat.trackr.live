<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Services\ViteAssetResolver;

/**
 * Renders the SPA shell HTML for any route the SPA owns (/, /satellite/{norad}, /conjunction/...).
 * The actual UI is hydrated by Lit components on the client.
 */
final class SpaShellController
{
    public function __construct(
        private readonly ViteAssetResolver $vite,
        private readonly string $rootDir,
        private readonly string $appName,
        private readonly string $appUrl,
        private readonly string $cesiumIonToken,
        private readonly Connection $db,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $vite = $this->vite;
        $appName = $this->appName;
        $appUrl = $this->appUrl;
        $tagline = 'Space situational awareness, _legible_';
        $cesiumIonToken = $this->cesiumIonToken;
        $selectedNorad = $args['norad'] ?? null;

        // Phase 6 chunk 1 — when the route carries both NORADs, resolve the
        // soonest TCA for that pair so the client can enter replay mode
        // without a round-trip just to discover whether the pair has a row.
        // Order-insensitive (matches /api/v1/conjunctions/{p}/{s}).
        $replayContext = null;
        if (isset($args['primary'], $args['secondary'])) {
            $a = (int) $args['primary'];
            $b = (int) $args['secondary'];
            if ($a > 0 && $b > 0) {
                $replayContext = $this->resolveConjunctionContext($a, $b);
            }
        }

        ob_start();
        require $this->rootDir . '/resources/views/shell.php';
        $html = (string) ob_get_clean();

        $response->getBody()->write($html);
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            // Force the browser to revalidate the SPA shell on every load.
            // The bundled assets it references are already content-hashed, so
            // they cache aggressively — but the shell itself must always be
            // fresh so a new build's hashed URLs propagate immediately.
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0');
    }

    /**
     * Returns the soonest-TCA conjunction row for the given NORAD pair,
     * shaped for the SPA shell to embed.  Pair is order-insensitive.
     *
     * @return array{primary: int, secondary: int, primary_name: string, secondary_name: string, tca: string, miss_km: float, rel_speed_km_s: ?float, probability: ?float}|null
     */
    private function resolveConjunctionContext(int $a, int $b): ?array
    {
        $row = $this->db->capsule()->table('conjunctions')
            ->where(function ($q) use ($a, $b): void {
                $q->where(function ($q2) use ($a, $b): void {
                    $q2->where('norad_id_primary', $a)->where('norad_id_secondary', $b);
                })->orWhere(function ($q2) use ($a, $b): void {
                    $q2->where('norad_id_primary', $b)->where('norad_id_secondary', $a);
                });
            })
            ->orderBy('tca')
            ->first();
        if ($row === null) {
            return null;
        }
        return [
            'primary'         => (int) $row->norad_id_primary,
            'secondary'       => (int) $row->norad_id_secondary,
            'primary_name'    => (string) $row->name_primary,
            'secondary_name'  => (string) $row->name_secondary,
            'tca'             => (string) $row->tca,
            'miss_km'         => (float) $row->tca_range_km,
            'rel_speed_km_s'  => $row->tca_relative_speed_km_s !== null ? (float) $row->tca_relative_speed_km_s : null,
            'probability'     => $row->max_probability !== null ? (float) $row->max_probability : null,
        ];
    }
}
