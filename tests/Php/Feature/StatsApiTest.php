<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SatTrackr\Database\Connection;
use SatTrackr\Database\Migrator;
use SatTrackr\Http\Controllers\StatsController;
use SatTrackr\Support\Json;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class StatsApiTest extends TestCase
{
    private string $tempDb = '';
    private Connection $db;

    protected function setUp(): void
    {
        $this->tempDb = tempnam(sys_get_temp_dir(), 'stats-api-') . '.db';
        if (file_exists($this->tempDb)) {
            unlink($this->tempDb);
        }
        $this->db = new Connection($this->tempDb);
        (new Migrator($this->db, dirname(__DIR__, 3) . '/migrations'))->migrate();

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
        $pdo = $this->db->pdo();

        // 6 satellites covering several countries/operators/types/years/masses
        // so the aggregations have meaningful shape.
        $pdo->exec("INSERT INTO satellites
            (norad_id, intl_designator, name, object_type, status, country, operator, launch_date, mass_kg, created_at, updated_at)
            VALUES
              (1, '1998-067A', 'ISS',          'PAYLOAD',     'ACTIVE',  'US',  'NASA',     '1998-11-20', 419725, '{$now}', '{$now}'),
              (2, '2019-074A', 'STARLINK-A',   'PAYLOAD',     'ACTIVE',  'US',  'SpaceX',   '2019-11-11', 260,    '{$now}', '{$now}'),
              (3, '2020-001B', 'STARLINK-B',   'PAYLOAD',     'ACTIVE',  'US',  'SpaceX',   '2020-01-15', 260,    '{$now}', '{$now}'),
              (4, '2025-099C', 'STARLINK-C',   'PAYLOAD',     'ACTIVE',  'US',  'SpaceX',   '2025-03-01', 800,    '{$now}', '{$now}'),
              (5, '2018-001Z', 'CZ-2C R/B',    'ROCKET_BODY', 'INACTIVE','PRC', 'CASC',     '2018-01-12', 4000,   '{$now}', '{$now}'),
              (6, '2010-001D', 'DEBRIS',       'DEBRIS',      'DECAYED', 'CIS', NULL,       '2010-02-02', NULL,   '{$now}', '{$now}')");
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

    public function testSummaryAggregatesAllBuckets(): void
    {
        $body = $this->invoke('summary');
        $d = $body['data'];
        $this->assertSame(6, $d['total']);

        $this->assertSame(4, $d['by_type']['PAYLOAD']);
        $this->assertSame(1, $d['by_type']['ROCKET_BODY']);
        $this->assertSame(1, $d['by_type']['DEBRIS']);

        $this->assertSame(4, $d['by_status']['ACTIVE']);
        $this->assertSame(1, $d['by_status']['INACTIVE']);
        $this->assertSame(1, $d['by_status']['DECAYED']);

        // Mass: 5 of 6 rows have known mass (the debris row is null).
        $this->assertSame(5, $d['mass']['known_count']);
        $this->assertEqualsWithDelta(419725 + 260 + 260 + 800 + 4000, $d['mass']['total_kg'], 1.0);

        // SpaceX is the top operator (3 of 4 known); top_operators caps at 5.
        $this->assertSame('SpaceX', $d['top_operators'][0]['key']);
        $this->assertSame(3,        $d['top_operators'][0]['count']);

        // US dominates country count.
        $this->assertSame('US', $d['top_countries'][0]['key']);
        $this->assertSame(4,    $d['top_countries'][0]['count']);
    }

    public function testOperatorsReturnsCountsDescending(): void
    {
        $body = $this->invoke('operators', ['limit' => '10']);
        $this->assertSame('operator', $body['meta']['column']);
        // SpaceX: 3, NASA: 1, CASC: 1 — null operator dropped.
        $counts = array_column($body['data'], 'count');
        $this->assertSame([3, 1, 1], $counts);
        $this->assertSame('SpaceX', $body['data'][0]['key']);
    }

    public function testCountriesRespectsLimit(): void
    {
        $body = $this->invoke('countries', ['limit' => '2']);
        $this->assertCount(2, $body['data']);
        $this->assertSame(2, $body['meta']['limit']);
    }

    public function testTypesIncludesPercentages(): void
    {
        $body = $this->invoke('types');
        $this->assertSame(6, $body['meta']['total']);
        // PAYLOAD: 4/6 = 66.67%
        $payload = current(array_filter($body['data'], static fn ($r) => $r['key'] === 'PAYLOAD'));
        $this->assertEqualsWithDelta(66.67, $payload['percent'], 0.01);
    }

    public function testLaunchYearsRespectsSinceFilter(): void
    {
        $body = $this->invoke('launch-years', ['since' => '2020']);
        $years = array_column($body['data'], 'year');
        // 2010 and 2018 + 1998 launches filtered out.
        $this->assertNotContains(1998, $years);
        $this->assertNotContains(2018, $years);
        $this->assertContains(2020, $years);
        $this->assertContains(2025, $years);
    }

    public function testLaunchYearsClampsSinceTo1957(): void
    {
        $body = $this->invoke('launch-years', ['since' => '1900']);
        $this->assertSame(1957, $body['meta']['since']);
    }

    public function testUnknownBreakdownFourOhFours(): void
    {
        $this->expectException(HttpNotFoundException::class);
        $this->invoke('whatever');
    }

    /**
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    private function invoke(string $breakdown, array $query = []): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', "/api/v1/stats/{$breakdown}")
            ->withQueryParams($query);
        $response = (new StatsController($this->db))($request, new Response(), ['breakdown' => $breakdown]);
        return Json::decode((string) $response->getBody());
    }
}
