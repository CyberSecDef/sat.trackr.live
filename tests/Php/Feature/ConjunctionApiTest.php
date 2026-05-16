<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SatTrackr\Database\Connection;
use SatTrackr\Database\Migrator;
use SatTrackr\Http\Controllers\ConjunctionDetailController;
use SatTrackr\Http\Controllers\ConjunctionListController;
use SatTrackr\Support\Json;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class ConjunctionApiTest extends TestCase
{
    private string $tempDb = '';
    private Connection $db;

    protected function setUp(): void
    {
        $this->tempDb = tempnam(sys_get_temp_dir(), 'conj-api-') . '.db';
        if (file_exists($this->tempDb)) {
            unlink($this->tempDb);
        }
        $this->db = new Connection($this->tempDb);
        (new Migrator($this->db, dirname(__DIR__, 3) . '/migrations'))->migrate();

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
        $pdo = $this->db->pdo();

        $pdo->exec("INSERT INTO satellites
            (norad_id, intl_designator, name, object_type, country, created_at, updated_at)
            VALUES
              (25544, '1998-067A', 'ISS (ZARYA)',    'PAYLOAD',     'US', '{$now}', '{$now}'),
              (44713, '2019-074A', 'STARLINK-1007',  'PAYLOAD',     'US', '{$now}', '{$now}'),
              (54039, '2022-159B', 'CZ-2C R/B',      'ROCKET_BODY', 'CN', '{$now}', '{$now}')");

        // 4 conjunctions with TCAs at +6h / +12h / +5d / +20d to exercise the window filter
        $tca = static fn (string $offset): string =>
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify($offset)->format('Y-m-d\TH:i:s\Z');

        $pdo->exec("INSERT INTO conjunctions
            (norad_id_primary, name_primary, dse_primary,
             norad_id_secondary, name_secondary, dse_secondary,
             tca, tca_range_km, tca_relative_speed_km_s, max_probability, dilution,
             created_at, updated_at)
            VALUES
              (25544, 'ISS (ZARYA) [+]',   1.0, 44713, 'STARLINK-1007 [+]', 1.5, '{$tca('+6 hours')}',   0.500, 10.5, 0.0500, 0.20, '{$now}', '{$now}'),
              (25544, 'ISS (ZARYA) [+]',   1.5, 54039, 'CZ-2C R/B [-]',     2.0, '{$tca('+12 hours')}',  0.150, 12.0, 0.4500, 0.05, '{$now}', '{$now}'),
              (44713, 'STARLINK-1007 [+]', 2.0, 54039, 'CZ-2C R/B [-]',     2.0, '{$tca('+5 days')}',    0.025, 11.0, 0.0100, 0.10, '{$now}', '{$now}'),
              (25544, 'ISS (ZARYA) [+]',   2.5, 44713, 'STARLINK-1007 [+]', 2.5, '{$tca('+20 days')}',   1.000, 10.0, 0.0001, 0.30, '{$now}', '{$now}')");
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

    public function testUpcomingDefaultsTo24hWindowSortedByProbability(): void
    {
        $body = $this->invoke(new ConjunctionListController($this->db), '/api/v1/conjunctions/upcoming');

        $this->assertSame(2, $body['meta']['count']);                       // +6h and +12h fall in 24h
        $this->assertSame(24, $body['meta']['within_hours']);
        $this->assertSame('probability', $body['meta']['sort']);

        // Highest probability first: 0.45 (ISS×CZ-2C @ +12h) > 0.05 (ISS×STARLINK @ +6h)
        $this->assertEqualsWithDelta(0.45, $body['data'][0]['max_probability'], 1e-6);
        $this->assertSame(54039, $body['data'][0]['secondary']['norad_id']);
        $this->assertSame('ROCKET_BODY', $body['data'][0]['secondary']['object_type']);
        $this->assertSame('CN', $body['data'][0]['secondary']['country']);
    }

    public function testUpcomingExtendedWindowIncludesFutureRows(): void
    {
        $body = $this->invoke(new ConjunctionListController($this->db), '/api/v1/conjunctions/upcoming?within_hours=720');
        $this->assertSame(4, $body['meta']['count']);
    }

    public function testMinProbabilityDropsLowRiskRows(): void
    {
        $body = $this->invoke(new ConjunctionListController($this->db), '/api/v1/conjunctions/upcoming?within_hours=720&min_probability=0.04');
        // Drops the 0.0001 and 0.01 rows; keeps 0.05 and 0.45.
        $this->assertSame(2, $body['meta']['count']);
        foreach ($body['data'] as $row) {
            $this->assertGreaterThanOrEqual(0.04, $row['max_probability']);
        }
    }

    public function testSortByTcaOrdersChronologically(): void
    {
        $body = $this->invoke(new ConjunctionListController($this->db), '/api/v1/conjunctions/upcoming?within_hours=720&sort=tca');
        $tcas = array_map(static fn ($r) => $r['tca'], $body['data']);
        $sorted = $tcas;
        sort($sorted);
        $this->assertSame($sorted, $tcas);
    }

    public function testPaginationLimitTotalAndPage(): void
    {
        $body = $this->invoke(new ConjunctionListController($this->db), '/api/v1/conjunctions/upcoming?within_hours=720&limit=2&page=2');
        $this->assertSame(2, $body['meta']['count']);                       // page 2 of 4 = 2 rows
        $this->assertSame(4, $body['meta']['total']);
        $this->assertSame(2, $body['meta']['page']);
        $this->assertSame(2, $body['meta']['limit']);
    }

    public function testWithinHoursClampedToMaxCeiling(): void
    {
        $body = $this->invoke(new ConjunctionListController($this->db), '/api/v1/conjunctions/upcoming?within_hours=99999');
        $this->assertSame(720, $body['meta']['within_hours']);
    }

    public function testDetailReturnsAllPredictionsForPairOrderInsensitive(): void
    {
        // Two conjunctions exist for ISS (25544) × STARLINK-1007 (44713): +6h and +20d
        $body = $this->invoke(
            new ConjunctionDetailController($this->db),
            '/api/v1/conjunctions/25544/44713',
            ['primary' => '25544', 'secondary' => '44713']
        );
        $this->assertSame(2, $body['meta']['count']);

        // Swap order — same result
        $reverse = $this->invoke(
            new ConjunctionDetailController($this->db),
            '/api/v1/conjunctions/44713/25544',
            ['primary' => '44713', 'secondary' => '25544']
        );
        $this->assertSame(2, $reverse['meta']['count']);
        $this->assertSame(
            array_column($body['data'], 'id'),
            array_column($reverse['data'], 'id'),
        );

        // Detail includes source + updated_at
        $this->assertSame('CELESTRAK_SOCRATES', $body['data'][0]['source']);
        $this->assertArrayHasKey('updated_at', $body['data'][0]);
    }

    public function testDetailFourOhFoursOnUnknownPair(): void
    {
        $this->expectException(HttpNotFoundException::class);
        $this->invoke(
            new ConjunctionDetailController($this->db),
            '/api/v1/conjunctions/99999/88888',
            ['primary' => '99999', 'secondary' => '88888']
        );
    }

    /**
     * @param array<string, string> $args
     * @return array<string, mixed>
     */
    private function invoke(object $controller, string $uri, array $args = []): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $uri);
        if (str_contains($uri, '?')) {
            parse_str(parse_url($uri, PHP_URL_QUERY) ?: '', $query);
            $request = $request->withQueryParams($query);
        }
        $response = $controller($request, new Response(), $args);
        return Json::decode((string) $response->getBody());
    }
}
