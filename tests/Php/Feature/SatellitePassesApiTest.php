<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SatTrackr\Database\Connection;
use SatTrackr\Database\Migrator;
use SatTrackr\Http\Controllers\SatellitePassesController;
use SatTrackr\Services\PassCache;
use SatTrackr\Services\PassCalculatorInterface;
use SatTrackr\Support\Json;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Controller-level tests run against a hand-rolled FakePassCalculator
 * so we don't shell out to Node 5 times per test.  The actual Node
 * subprocess is exercised in PassCalculatorTest.
 */
final class SatellitePassesApiTest extends TestCase
{
    private string $tempDb = '';
    private Connection $db;

    protected function setUp(): void
    {
        $this->tempDb = tempnam(sys_get_temp_dir(), 'passes-api-') . '.db';
        if (file_exists($this->tempDb)) {
            unlink($this->tempDb);
        }
        $this->db = new Connection($this->tempDb);
        (new Migrator($this->db, dirname(__DIR__, 3) . '/migrations'))->migrate();

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
        $pdo = $this->db->pdo();
        $pdo->exec("INSERT INTO satellites (norad_id, intl_designator, name, created_at, updated_at)
                    VALUES (25544, '1998-067A', 'ISS (ZARYA)', '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO tle_current
                    (norad_id, epoch, line1, line2, mean_motion, eccentricity,
                     inclination_deg, raan_deg, arg_perigee_deg, mean_anomaly_deg,
                     bstar, rev_number, period_min, perigee_km, apogee_km,
                     semimajor_km, updated_at)
                    VALUES (25544, '{$now}',
                            '1 25544U 98067A   26134.20272636  .00027410  00000-0  47815-3 0  9994',
                            '2 25544  51.6358 207.8530 0001859  44.6849 315.4254 15.50907195502493',
                            15.5, 0.001, 51.6, 12.0, 56.0, 90.0, 0.00001,
                            47000, 92.7, 415, 422, 6790, '{$now}')");
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

    private function controller(FakePassCalculator $calc): SatellitePassesController
    {
        return new SatellitePassesController($this->db, $calc, new PassCache($this->db));
    }

    public function testFirstCallComputesAndCachesAndReturnsPasses(): void
    {
        $calc = new FakePassCalculator(['rise_at' => 'A', 'peak_at' => 'B', 'set_at' => 'C']);
        $body = $this->invoke($this->controller($calc), '/api/v1/satellites/25544/passes?lat=51.5072&lon=-0.1276', ['norad' => '25544']);

        $this->assertSame(1, $calc->callCount);
        $this->assertCount(1, $body['data']);
        $this->assertSame('A', $body['data'][0]['rise_at']);
        $this->assertFalse($body['meta']['from_cache']);
        $this->assertSame(25544, $body['meta']['norad_id']);
        $this->assertEqualsWithDelta(51.5072, $body['meta']['observer']['latitude'], 1e-4);
    }

    public function testSecondCallWithSameNoradAndObserverHitsCache(): void
    {
        $calc = new FakePassCalculator(['rise_at' => 'X', 'peak_at' => 'Y', 'set_at' => 'Z']);
        $controller = $this->controller($calc);

        $this->invoke($controller, '/api/v1/satellites/25544/passes?lat=51.5072&lon=-0.1276', ['norad' => '25544']);
        $body2 = $this->invoke($controller, '/api/v1/satellites/25544/passes?lat=51.50711&lon=-0.12764', ['norad' => '25544']);

        $this->assertSame(1, $calc->callCount);                    // not invoked twice
        $this->assertTrue($body2['meta']['from_cache']);
        $this->assertSame('X', $body2['data'][0]['rise_at']);
    }

    public function testMissingLatOrLonThrows400(): void
    {
        $controller = $this->controller(new FakePassCalculator([]));
        $this->expectException(HttpBadRequestException::class);
        $this->invoke($controller, '/api/v1/satellites/25544/passes?lat=10', ['norad' => '25544']);
    }

    public function testOutOfRangeLatLonThrows400(): void
    {
        $controller = $this->controller(new FakePassCalculator([]));
        $this->expectException(HttpBadRequestException::class);
        $this->invoke($controller, '/api/v1/satellites/25544/passes?lat=120&lon=0', ['norad' => '25544']);
    }

    public function testUnknownNoradThrows404(): void
    {
        $controller = $this->controller(new FakePassCalculator([]));
        $this->expectException(HttpNotFoundException::class);
        $this->invoke($controller, '/api/v1/satellites/99999/passes?lat=51&lon=0', ['norad' => '99999']);
    }

    public function testDaysAndMinElevationFlowThroughToCalculator(): void
    {
        $calc = new FakePassCalculator([]);
        $this->invoke(
            $this->controller($calc),
            '/api/v1/satellites/25544/passes?lat=51.5&lon=-0.1&days=3&min_elevation_deg=30',
            ['norad' => '25544']
        );
        $this->assertSame(3, $calc->lastCall['days']);
        $this->assertEqualsWithDelta(30, $calc->lastCall['minElevationDeg'], 1e-6);
    }

    /**
     * @param array<string, string> $args
     * @return array<string, mixed>
     */
    private function invoke(SatellitePassesController $controller, string $uri, array $args): array
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

/**
 * Test double for PassCalculator.  Returns a fixed pass list and
 * records call count + last argument set so the test can assert on
 * what flowed through.
 */
final class FakePassCalculator implements PassCalculatorInterface
{
    public int $callCount = 0;
    /** @var array<string, mixed> */
    public array $lastCall = [];

    /** @param array<string, mixed>|list<array<string, mixed>> $stubPass */
    public function __construct(private readonly array $stubPass)
    {
    }

    /**
     * @param array{line1: string, line2: string} $tle
     * @param array{latitude: float, longitude: float, altitudeMeters: float} $observer
     * @return array{computed_at: string, count: int, passes: list<array<string, mixed>>}
     */
    public function compute(
        array $tle, array $observer, int $startMs,
        int $days = 7, float $minElevationDeg = 10.0, int $stepSeconds = 60
    ): array {
        $this->callCount++;
        $this->lastCall = compact('tle', 'observer', 'startMs', 'days', 'minElevationDeg', 'stepSeconds');
        $passes = is_array($this->stubPass) && array_is_list($this->stubPass)
            ? $this->stubPass
            : (count($this->stubPass) > 0 ? [$this->stubPass] : []);
        return ['computed_at' => '2026-05-15T00:00:00Z', 'count' => count($passes), 'passes' => $passes];
    }
}
