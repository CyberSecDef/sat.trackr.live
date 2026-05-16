<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SatTrackr\Database\Connection;
use SatTrackr\Database\Migrator;
use SatTrackr\Http\Controllers\AutocompleteController;
use SatTrackr\Http\Controllers\GroupDetailController;
use SatTrackr\Http\Controllers\GroupListController;
use SatTrackr\Http\Controllers\GroupTlesController;
use SatTrackr\Http\Controllers\SatelliteDetailController;
use SatTrackr\Http\Controllers\SatelliteListController;
use SatTrackr\Http\Controllers\SatelliteTleController;
use SatTrackr\Http\Controllers\SearchController;
use SatTrackr\Services\FreshnessClassifier;
use SatTrackr\Support\Json;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Feature tests for the chunk-4 API. Each test invokes a controller
 * directly with a PSR-7 request/response pair; the middleware stack
 * is the SUT for separate tests if needed.
 *
 * Setup seeds a temp DB with three known satellites in two groups so
 * we can assert deterministic responses without depending on a live
 * CelesTrak feed.
 */
final class ApiTest extends TestCase
{
    private string $tempDb = '';
    private Connection $db;
    private FreshnessClassifier $freshness;

    protected function setUp(): void
    {
        $this->tempDb = tempnam(sys_get_temp_dir(), 'sat-api-test-') . '.db';
        if (file_exists($this->tempDb)) {
            unlink($this->tempDb);
        }
        $this->db = new Connection($this->tempDb);
        (new Migrator($this->db, dirname(__DIR__, 3) . '/migrations'))->migrate();
        $this->freshness = new FreshnessClassifier();

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
        // Anchor relative to the real clock so the FreshnessClassifier
        // (which defaults to `new DateTimeImmutable()`) still classifies
        // the fixture TLE as FRESH no matter what day the test runs.
        $nowDt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $now = $nowDt->format('Y-m-d\TH:i:s.u\Z');
        $epoch = $nowDt->modify('-15 hours')->format('Y-m-d\TH:i:s.u\Z'); // < 48h → FRESH
        $pdo = $this->db->pdo();

        // Three satellites: ISS in stations + active, STARLINK-1 in starlink + active,
        // CALSPHERE in active only.
        $sats = [
            [25544, '1998-067A', 'ISS (ZARYA)'],
            [44713, '2019-074A', 'STARLINK-1007'],
            [900,   '1964-063C', 'CALSPHERE 1'],
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

        // Group memberships
        $memberships = [
            [25544, 'stations'],
            [25544, 'active'],
            [44713, 'starlink'],
            [44713, 'active'],
            [900,   'active'],
        ];
        foreach ($memberships as [$norad, $slug]) {
            $pdo->exec("INSERT INTO group_membership (norad_id, group_slug, last_seen_at)
                        VALUES ({$norad}, '{$slug}', '{$now}')");
        }
    }

    // ─── /api/v1/satellites ─────────────────────────────────────────────

    public function testListReturnsAllSeededSatellitesWithPaginationMeta(): void
    {
        $body = $this->invoke(new SatelliteListController($this->db), 'GET', '/api/v1/satellites');
        $this->assertSame(3, $body['meta']['total']);
        $this->assertCount(3, $body['data']);
        $this->assertSame(['CALSPHERE 1', 'ISS (ZARYA)', 'STARLINK-1007'], array_column($body['data'], 'name'));
    }

    public function testListAppliesFtsFilter(): void
    {
        $body = $this->invoke(new SatelliteListController($this->db), 'GET', '/api/v1/satellites?q=ISS');
        $this->assertSame(1, $body['meta']['total']);
        $this->assertSame('ISS (ZARYA)', $body['data'][0]['name']);
    }

    public function testListPaginates(): void
    {
        $body = $this->invoke(new SatelliteListController($this->db), 'GET', '/api/v1/satellites?limit=2&page=2');
        $this->assertSame(3, $body['meta']['total']);
        $this->assertSame(2, $body['meta']['pages']);
        $this->assertCount(1, $body['data']);
        $this->assertNull($body['links']['next']);
    }

    // ─── /api/v1/satellites/{norad} ─────────────────────────────────────

    public function testDetailReturnsFullObjectWithInlineTle(): void
    {
        $body = $this->invoke(
            new SatelliteDetailController($this->db, $this->freshness),
            'GET', '/api/v1/satellites/25544', ['norad' => '25544']
        );
        $this->assertSame(25544, $body['data']['norad_id']);
        $this->assertSame('ISS (ZARYA)', $body['data']['name']);
        $this->assertSame('1998-067A', $body['data']['intl_designator']);
        $this->assertSame([], $body['data']['purposes']);

        $tle = $body['data']['tle_current'];
        $this->assertSame('FRESH', $tle['freshness']);
        $this->assertEqualsWithDelta(15.5, $tle['mean_motion'], 1e-6);
    }

    public function testDetail404OnUnknownSatellite(): void
    {
        $this->expectException(HttpNotFoundException::class);
        $this->invoke(
            new SatelliteDetailController($this->db, $this->freshness),
            'GET', '/api/v1/satellites/99999', ['norad' => '99999']
        );
    }

    // ─── /api/v1/satellites/{norad}/tle ─────────────────────────────────

    public function testTleEndpointReturnsCurrentTleOnly(): void
    {
        $body = $this->invoke(
            new SatelliteTleController($this->db, $this->freshness),
            'GET', '/api/v1/satellites/25544/tle', ['norad' => '25544']
        );
        $this->assertSame(25544, $body['data']['norad_id']);
        $this->assertSame('FRESH', $body['data']['freshness']);
        $this->assertArrayHasKey('line1', $body['data']);
        $this->assertArrayNotHasKey('name', $body['data']);
    }

    // ─── /api/v1/groups ─────────────────────────────────────────────────

    public function testGroupListReportsCountsForSeededGroups(): void
    {
        $body = $this->invoke(new GroupListController($this->db), 'GET', '/api/v1/groups');
        $bySlug = [];
        foreach ($body['data'] as $g) {
            $bySlug[$g['slug']] = $g['count'];
        }
        $this->assertSame(3, $bySlug['active']);
        $this->assertSame(1, $bySlug['stations']);
        $this->assertSame(1, $bySlug['starlink']);
        $this->assertSame(0, $bySlug['oneweb']);
        $this->assertGreaterThanOrEqual(38, $body['meta']['total_groups']);
    }

    // ─── /api/v1/groups/{slug} ──────────────────────────────────────────

    public function testGroupDetailListsMembers(): void
    {
        $body = $this->invoke(
            new GroupDetailController($this->db),
            'GET', '/api/v1/groups/active', ['slug' => 'active']
        );
        $this->assertSame('active', $body['data']['slug']);
        $this->assertSame(3, $body['data']['count']);
        $this->assertSame([900, 25544, 44713], $body['data']['norad_ids']);
    }

    public function testGroupDetail404OnUnknownSlug(): void
    {
        $this->expectException(HttpNotFoundException::class);
        $this->invoke(
            new GroupDetailController($this->db),
            'GET', '/api/v1/groups/no-such-slug', ['slug' => 'no-such-slug']
        );
    }

    // ─── /api/v1/groups/{slug}/tles ─────────────────────────────────────

    public function testGroupTlesReturnsBulkBlobWithFullKeys(): void
    {
        $body = $this->invoke(
            new GroupTlesController($this->db),
            'GET', '/api/v1/groups/stations/tles', ['slug' => 'stations']
        );
        $this->assertSame('stations', $body['group']);
        $this->assertSame(1, $body['count']);
        $this->assertSame(25544, $body['tles'][0]['norad_id']);
        $this->assertSame('ISS (ZARYA)', $body['tles'][0]['name']);
        $this->assertArrayHasKey('line1', $body['tles'][0]);
        $this->assertArrayHasKey('line2', $body['tles'][0]);
        $this->assertArrayHasKey('object_type', $body['tles'][0]);
    }

    // ─── /api/v1/search ─────────────────────────────────────────────────

    public function testSearchByExactNoradReturnsThatNoradFirst(): void
    {
        $body = $this->invoke(new SearchController($this->db), 'GET', '/api/v1/search?q=25544');
        $this->assertGreaterThanOrEqual(1, $body['meta']['count']);
        $this->assertSame(25544, $body['data'][0]['norad_id']);
        $this->assertSame('norad_id', $body['data'][0]['match_type']);
    }

    public function testSearchByIntlDesignator(): void
    {
        $body = $this->invoke(new SearchController($this->db), 'GET', '/api/v1/search?q=1998-067A');
        $this->assertSame(25544, $body['data'][0]['norad_id']);
        $this->assertSame('intl_designator', $body['data'][0]['match_type']);
    }

    public function testSearchByNameUsesFts(): void
    {
        $body = $this->invoke(new SearchController($this->db), 'GET', '/api/v1/search?q=Starlink');
        $this->assertSame(1, $body['meta']['count']);
        $this->assertSame(44713, $body['data'][0]['norad_id']);
        $this->assertSame('fts', $body['data'][0]['match_type']);
    }

    public function testSearchEmptyQueryReturnsNoResults(): void
    {
        $body = $this->invoke(new SearchController($this->db), 'GET', '/api/v1/search?q=');
        $this->assertSame(0, $body['meta']['count']);
        $this->assertSame([], $body['data']);
    }

    // ─── /api/v1/autocomplete ───────────────────────────────────────────

    public function testAutocompleteReturnsLimitedResults(): void
    {
        $body = $this->invoke(new AutocompleteController($this->db), 'GET', '/api/v1/autocomplete?q=ISS');
        $this->assertGreaterThanOrEqual(1, count($body['data']));
        $this->assertSame(25544, $body['data'][0]['norad_id']);
    }

    public function testAutocompleteEmptyQueryReturnsNoResults(): void
    {
        $body = $this->invoke(new AutocompleteController($this->db), 'GET', '/api/v1/autocomplete?q=');
        $this->assertSame([], $body['data']);
    }

    // ─── helper ─────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $args
     * @return array<string, mixed>
     */
    private function invoke(object $controller, string $method, string $uri, array $args = []): array
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);
        // Parse query string into params
        if (str_contains($uri, '?')) {
            parse_str(parse_url($uri, PHP_URL_QUERY) ?: '', $query);
            $request = $request->withQueryParams($query);
        }
        $response = (new Response());
        $response = $controller($request, $response, $args);

        return Json::decode((string) $response->getBody());
    }
}
