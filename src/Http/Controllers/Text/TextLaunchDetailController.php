<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers\Text;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Database\Connection;
use SatTrackr\Http\Controllers\LaunchSerializer;
use SatTrackr\Services\TextRenderer;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /text/launches/{id}
 *
 * Server-rendered detail page for a single launch (UUID).
 */
final class TextLaunchDetailController
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
        $id = (string) ($args['id'] ?? '');
        if ($id === '') {
            throw new HttpNotFoundException($request, 'Missing launch id');
        }

        $row = $this->db->capsule()->table('launches as l')
            ->leftJoin('launch_sites as p', 'p.id', '=', 'l.pad_id')
            ->where('l.id', $id)
            ->select(
                'l.*',
                'p.name as pad_name',
                'p.latitude as pad_latitude',
                'p.longitude as pad_longitude',
                'p.country as pad_country',
                'p.operator as pad_operator',
                'p.url as pad_url',
            )
            ->first();
        if ($row === null) {
            throw new HttpNotFoundException($request, "Launch '{$id}' not found");
        }

        $launch = LaunchSerializer::detail($row);

        $body = $this->renderer->renderInner('launch_detail.php', ['launch' => $launch]);
        // Phase 5 chunk 5 — schema.org Event card for launches.
        $jsonLd = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Event',
            'name'        => (string) $launch['name'],
            'startDate'   => (string) ($launch['net'] ?? ''),
            'eventStatus' => match ((string) ($launch['status'] ?? '')) {
                'GO', 'TBD'    => 'https://schema.org/EventScheduled',
                'SUCCESS', 'FAILURE', 'PARTIAL_FAILURE' => 'https://schema.org/EventScheduled',
                'HOLD'         => 'https://schema.org/EventPostponed',
                default        => 'https://schema.org/EventScheduled',
            },
            'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
            'url'         => '/text/launches/' . rawurlencode($id),
            'image'       => '/og/launch/' . rawurlencode($id) . '.png',
            'description' => (string) ($launch['mission_name'] ?? $launch['name']),
        ];
        if (!empty($launch['provider'])) {
            $jsonLd['organizer'] = ['@type' => 'Organization', 'name' => (string) $launch['provider']];
        }

        $html = $this->renderer->renderPage(
            title: (string) $launch['name'],
            body: $body,
            activeNav: 'launches',
            description: ($launch['mission_name'] ?? $launch['name']) . ' — '
                . ($launch['provider'] ?? 'unknown provider')
                . ' · NET ' . ($launch['net'] ?? '?'),
            ogImage: '/og/launch/' . rawurlencode($id) . '.png',
            canonicalPath: '/text/launches/' . rawurlencode($id),
            jsonLd: $jsonLd,
        );
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
