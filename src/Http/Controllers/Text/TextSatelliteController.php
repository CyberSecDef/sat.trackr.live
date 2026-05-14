<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers\Text;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Services\TextRenderer;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /text/satellite/{norad} — server-rendered satellite detail page.
 * Mirrors /api/v1/satellites/{norad} but emits HTML.
 */
final class TextSatelliteController
{
    public function __construct(
        private readonly Connection $db,
        private readonly TextRenderer $renderer,
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

        $satRow = $this->db->capsule()->table('satellites')
            ->where('norad_id', $norad)
            ->first();
        if ($satRow === null) {
            throw new HttpNotFoundException($request, "Satellite {$norad} not found");
        }

        $tleRow = $this->db->capsule()->table('tle_current')
            ->where('norad_id', $norad)
            ->first();

        $purposes = $this->db->capsule()->table('satellite_purposes')
            ->where('norad_id', $norad)
            ->pluck('purpose')
            ->all();

        $sat = (array) $satRow;
        $tle = $tleRow !== null ? (array) $tleRow : null;

        $body = $this->renderer->renderInner('satellite.php', [
            'sat'      => $sat,
            'tle'      => $tle,
            'purposes' => array_map('strval', $purposes),
        ]);

        $name = (string) $sat['name'];
        $description = "Catalog entry for {$name} (NORAD {$norad}). "
            . ($tle !== null ? "Period {$this->fmt($tle['period_min'])}min, inclination {$this->fmt($tle['inclination_deg'])}°." : 'No current TLE on file.');

        $html = $this->renderer->renderPage(
            title: $name,
            body: $body,
            activeNav: 'catalog',
            description: $description,
        );
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function fmt(mixed $v): string
    {
        if (!is_numeric($v)) {
            return '?';
        }
        return number_format((float) $v, 2);
    }
}
