<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SatTrackr\Database\Connection;
use SatTrackr\Database\Migrator;
use SatTrackr\Http\Controllers\Text\TextCatalogController;
use SatTrackr\Http\Controllers\Text\TextDecaysController;
use SatTrackr\Http\Controllers\Text\TextGroupController;
use SatTrackr\Http\Controllers\Text\TextGroupsController;
use SatTrackr\Http\Controllers\Text\TextLaunchDetailController;
use SatTrackr\Http\Controllers\Text\TextLaunchListController;
use SatTrackr\Http\Controllers\Text\TextSatelliteController;
use SatTrackr\Http\Controllers\Text\TextSearchController;
use SatTrackr\Services\TextRenderer;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Feature tests for the chunk-8 text-only catalog. Each test invokes a
 * controller directly with a PSR-7 request/response and asserts on the
 * rendered HTML body — exact byte equality would be brittle, so checks
 * focus on key strings (titles, satellite names, IDs, table rows, 404
 * exceptions) that the templates are obligated to surface.
 */
final class TextRoutesTest extends TestCase
{
    private string $tempDb = '';
    private Connection $db;
    private TextRenderer $renderer;

    protected function setUp(): void
    {
        $this->tempDb = tempnam(sys_get_temp_dir(), 'sat-text-test-') . '.db';
        if (file_exists($this->tempDb)) {
            unlink($this->tempDb);
        }
        $this->db = new Connection($this->tempDb);
        (new Migrator($this->db, dirname(__DIR__, 3) . '/migrations'))->migrate();
        $this->renderer = new TextRenderer(dirname(__DIR__, 3));
        $this->seed();
    }

    protected function tearDown(): void
    {
        unset($this->db);
        foreach ([$this->tempDb, $this->tempDb . '-wal', $this->tempDb . '-shm'] as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }
    }

    private function seed(): void
    {
        $now = '2026-05-14T20:00:00.000000Z';
        $epoch = '2026-05-14T04:45:57.957408Z';
        $pdo = $this->db->pdo();
        $sats = [
            [25544, '1998-067A', 'ISS (ZARYA)'],
            [44713, '2019-074A', 'STARLINK-1007'],
        ];
        foreach ($sats as [$norad, $intl, $name]) {
            $pdo->exec("INSERT INTO satellites (norad_id, intl_designator, name, created_at, updated_at)
                        VALUES ({$norad}, '{$intl}', '{$name}', '{$now}', '{$now}')");
            $pdo->exec("INSERT INTO tle_current
                        (norad_id, epoch, line1, line2, mean_motion, eccentricity,
                         inclination_deg, raan_deg, arg_perigee_deg, mean_anomaly_deg,
                         bstar, rev_number, period_min, perigee_km, apogee_km,
                         semimajor_km, updated_at)
                        VALUES ({$norad}, '{$epoch}', '1 {$norad} L1', '2 {$norad} L2',
                                15.5, 0.001, 51.6, 12.0, 56.0, 90.0, 0.00001,
                                47000, 92.7, 415, 422, 6790, '{$now}')");
        }
        $pdo->exec("INSERT INTO group_membership (norad_id, group_slug, last_seen_at)
                    VALUES (25544, 'stations', '{$now}'),
                           (25544, 'active',   '{$now}'),
                           (44713, 'starlink', '{$now}'),
                           (44713, 'active',   '{$now}')");

        // Phase 2 chunk 3D fixtures: a pad and 2 launches (one upcoming, one recent).
        $future  = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+5 days')->format('Y-m-d\TH:i:s\Z');
        $past20d = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-20 days')->format('Y-m-d\TH:i:s\Z');
        $pdo->exec("INSERT INTO launch_sites (id, name, latitude, longitude, country, operator, url, updated_at)
                    VALUES (80, 'Space Launch Complex 40', 28.5618, -80.5772, 'USA', 'Cape Canaveral SFS, FL, USA', NULL, '{$now}')");
        $pdo->exec("INSERT INTO launches
                    (id, name, net, status, provider, vehicle, pad_id, mission_name, mission_type, orbit_target, customer, webcast_url, image_url, description, associated_norad_ids, updated_at)
                    VALUES
                      ('uuid-up', 'Falcon 9 | Starlink Future', '{$future}',  'GO',      'SpaceX', 'Falcon 9', 80, 'Starlink Future', 'Communications', 'LEO', 'SpaceX', NULL, NULL, NULL, '[]',           '{$now}'),
                      ('uuid-rec','Atlas V | Recent USSF',      '{$past20d}', 'SUCCESS', 'ULA',    'Atlas V',  80, 'USSF Mission',    'Government',     'GTO', 'USSF',   NULL, NULL, NULL, '[25544,44713]', '{$now}')");

        // Phase 2 chunk 4D fixtures: a single TIP-sourced reentry for ISS
        // (within the 7-day default window).
        $soon = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+3 days')->format('Y-m-d\TH:i:s\Z');
        $pdo->exec("INSERT INTO reentries
                    (norad_id, predicted_decay, confidence_window_hours, source, risk_score, raw_message, created_at, updated_at)
                    VALUES
                      (25544, '{$soon}', 6.0, 'SPACE_TRACK_TIP', 4.2, '{\"NORAD_CAT_ID\":\"25544\"}', '{$now}', '{$now}')");
    }

    public function testCatalogListReturns200WithBothSatellites(): void
    {
        $body = $this->invoke(new TextCatalogController($this->db, $this->renderer), '/text');
        $this->assertStringContainsString('Satellite catalog', $body);
        $this->assertStringContainsString('ISS (ZARYA)', $body);
        $this->assertStringContainsString('STARLINK-1007', $body);
        $this->assertStringContainsString('2 satellites', $body); // pagination meta
    }

    public function testCatalogListAppliesQuery(): void
    {
        $body = $this->invoke(
            new TextCatalogController($this->db, $this->renderer),
            '/text?q=ISS'
        );
        $this->assertStringContainsString('ISS (ZARYA)', $body);
        $this->assertStringNotContainsString('STARLINK-1007', $body);
        $this->assertStringContainsString('1 satellites', $body);
    }

    public function testSatelliteDetailRendersFullPage(): void
    {
        $body = $this->invoke(
            new TextSatelliteController($this->db, $this->renderer),
            '/text/satellite/25544',
            ['norad' => '25544']
        );
        $this->assertStringContainsString('ISS (ZARYA)', $body);
        $this->assertStringContainsString('NORAD 25544', $body);
        $this->assertStringContainsString('1998-067A', $body);
        $this->assertStringContainsString('§ Identity', $body);
        $this->assertStringContainsString('§ Orbital elements', $body);
        $this->assertStringContainsString('§ Raw data', $body);
        $this->assertStringContainsString('92.70 min', $body);
        $this->assertStringContainsString('51.6000°', $body);
    }

    public function testSatelliteDetailThrowsHttpNotFoundForUnknownNorad(): void
    {
        $this->expectException(HttpNotFoundException::class);
        $this->invoke(
            new TextSatelliteController($this->db, $this->renderer),
            '/text/satellite/99999',
            ['norad' => '99999']
        );
    }

    public function testGroupsIndexListsAllConfiguredGroupsWithCounts(): void
    {
        $body = $this->invoke(new TextGroupsController($this->db, $this->renderer), '/text/groups');
        $this->assertStringContainsString('Groups', $body);
        $this->assertStringContainsString('stations', $body);
        $this->assertStringContainsString('starlink', $body);
        $this->assertStringContainsString('active', $body);
    }

    public function testGroupDetailListsMembersWithLinks(): void
    {
        $body = $this->invoke(
            new TextGroupController($this->db, $this->renderer),
            '/text/groups/active',
            ['slug' => 'active']
        );
        $this->assertStringContainsString('ISS (ZARYA)', $body);
        $this->assertStringContainsString('STARLINK-1007', $body);
        $this->assertStringContainsString('§ Active satellites', $body);
        $this->assertStringContainsString('2 satellites', $body);
    }

    public function testGroupDetailThrowsHttpNotFoundForUnknownSlug(): void
    {
        $this->expectException(HttpNotFoundException::class);
        $this->invoke(
            new TextGroupController($this->db, $this->renderer),
            '/text/groups/no-such-slug',
            ['slug' => 'no-such-slug']
        );
    }

    public function testSearchEmptyQueryShowsForm(): void
    {
        $body = $this->invoke(new TextSearchController($this->db, $this->renderer), '/text/search');
        $this->assertStringContainsString('§ Search', $body);
        $this->assertStringContainsString('Type a name', $body);
        $this->assertStringNotContainsString('matches for', $body);
    }

    public function testSearchByExactNoradId(): void
    {
        $body = $this->invoke(
            new TextSearchController($this->db, $this->renderer),
            '/text/search?q=25544'
        );
        $this->assertStringContainsString('ISS (ZARYA)', $body);
        $this->assertStringContainsString('1 satellites', $body);
    }

    public function testLaunchesUpcomingShowsFutureOnly(): void
    {
        $body = $this->invoke(
            new TextLaunchListController($this->db, $this->renderer),
            '/text/launches'
        );
        $this->assertStringContainsString('Upcoming launches', $body);
        $this->assertStringContainsString('Starlink Future', $body);
        $this->assertStringContainsString('Space Launch Complex 40', $body);
        $this->assertStringNotContainsString('USSF Mission', $body);
        $this->assertStringContainsString('T-', $body); // countdown rendered
    }

    public function testLaunchesRecentShowsPastWindow(): void
    {
        $body = $this->invoke(
            new TextLaunchListController($this->db, $this->renderer),
            '/text/launches/recent'
        );
        $this->assertStringContainsString('Recent launches', $body);
        $this->assertStringContainsString('USSF Mission', $body);
        $this->assertStringNotContainsString('Starlink Future', $body);
    }

    public function testLaunchDetailRendersPadAndCatalogedObjects(): void
    {
        $body = $this->invoke(
            new TextLaunchDetailController($this->db, $this->renderer),
            '/text/launches/uuid-rec',
            ['id' => 'uuid-rec']
        );
        $this->assertStringContainsString('Atlas V | Recent USSF', $body);
        $this->assertStringContainsString('§ Pad', $body);
        $this->assertStringContainsString('Space Launch Complex 40', $body);
        $this->assertStringContainsString('§ Cataloged objects', $body);
        $this->assertStringContainsString('NORAD 25544', $body);
        $this->assertStringContainsString('NORAD 44713', $body);
    }

    public function testLaunchDetailThrowsHttpNotFoundForUnknownId(): void
    {
        $this->expectException(HttpNotFoundException::class);
        $this->invoke(
            new TextLaunchDetailController($this->db, $this->renderer),
            '/text/launches/no-such-id',
            ['id' => 'no-such-id']
        );
    }

    public function testDecaysListsUpcomingReentryWithCountdownAndRiskBadge(): void
    {
        $body = $this->invoke(
            new TextDecaysController($this->db, $this->renderer),
            '/text/decays'
        );
        $this->assertStringContainsString('Predicted reentries', $body);
        $this->assertStringContainsString('ISS (ZARYA)', $body);
        $this->assertStringContainsString('NORAD 25544', $body);
        $this->assertStringContainsString('±6.0h', $body);
        $this->assertStringContainsString('badge--high', $body); // 4.2 >= 4 → high
        $this->assertStringContainsString('T-', $body);          // countdown rendered
    }

    public function testDecaysShowsEmptyMessageOutsideWindow(): void
    {
        // Narrow the window to 1 hour: the +3-day fixture should be excluded.
        $body = $this->invoke(
            new TextDecaysController($this->db, $this->renderer),
            '/text/decays?within_hours=1'
        );
        $this->assertStringContainsString('No reentries predicted', $body);
        $this->assertStringNotContainsString('ISS (ZARYA)', $body);
    }

    public function testSearchByFtsNameMatch(): void
    {
        $body = $this->invoke(
            new TextSearchController($this->db, $this->renderer),
            '/text/search?q=ZARYA'
        );
        $this->assertStringContainsString('ISS (ZARYA)', $body);
    }

    /**
     * @param array<string, string> $args
     */
    private function invoke(object $controller, string $uri, array $args = []): string
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $uri);
        if (str_contains($uri, '?')) {
            parse_str(parse_url($uri, PHP_URL_QUERY) ?: '', $query);
            $request = $request->withQueryParams($query);
        }
        $response = $controller($request, new Response(), $args);
        return (string) $response->getBody();
    }
}
