<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SatTrackr\Database\Connection;
use SatTrackr\Database\Migrator;
use SatTrackr\Http\Controllers\LaunchDetailController;
use SatTrackr\Http\Controllers\LaunchSiteListController;
use SatTrackr\Http\Controllers\RecentLaunchesController;
use SatTrackr\Http\Controllers\UpcomingLaunchesController;
use SatTrackr\Support\Json;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class LaunchApiTest extends TestCase
{
    private string $tempDb = '';
    private Connection $db;

    protected function setUp(): void
    {
        $this->tempDb = tempnam(sys_get_temp_dir(), 'launchapi-test-') . '.db';
        if (file_exists($this->tempDb)) {
            unlink($this->tempDb);
        }
        $this->db = new Connection($this->tempDb);
        (new Migrator($this->db, dirname(__DIR__, 3) . '/migrations'))->migrate();

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        $pdo = $this->db->pdo();
        $pdo->exec("INSERT INTO launch_sites (id, name, latitude, longitude, country, operator, url, updated_at)
            VALUES
              (80, 'Space Launch Complex 40', 28.5618, -80.5772, 'USA', 'Cape Canaveral SFS, FL, USA', 'https://example/pad/80', '{$now}'),
              (12, 'Tyuratam', 45.92,    63.34,    'KAZ', 'Baikonur Cosmodrome',          'https://example/pad/12', '{$now}')");

        $future   = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+5 days')->format('Y-m-d\TH:i:s\Z');
        $past30d  = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-30 days')->format('Y-m-d\TH:i:s\Z');
        $past120d = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-120 days')->format('Y-m-d\TH:i:s\Z');

        $pdo->exec("INSERT INTO launches
            (id, name, net, status, provider, vehicle, pad_id, mission_name, mission_type, orbit_target, customer, webcast_url, image_url, description, associated_norad_ids, updated_at)
            VALUES
              ('uuid-future', 'Falcon 9 | Starlink Future',  '{$future}',   'GO',      'SpaceX',  'Falcon 9', 80, 'Starlink Future', 'Communications', 'LEO', 'SpaceX', NULL, NULL, NULL, '[]', '{$now}'),
              ('uuid-recent', 'Atlas V | Recent USSF',       '{$past30d}',  'SUCCESS', 'ULA',     'Atlas V',  80, 'USSF Mission',    'Government',     'GTO', 'USSF',   NULL, NULL, NULL, '[25544,44713]', '{$now}'),
              ('uuid-old',    'Soyuz | Older Mission',       '{$past120d}', 'SUCCESS', 'Roscosmos', 'Soyuz',  12, 'Older Mission',   'Cargo',          'LEO', NULL,     NULL, NULL, NULL, '[]', '{$now}')");
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

    public function testUpcomingReturnsOnlyFutureLaunches(): void
    {
        $body = $this->invoke(new UpcomingLaunchesController($this->db), 'GET', '/api/v1/launches/upcoming');
        $this->assertSame(1, $body['meta']['count']);
        $this->assertSame('uuid-future', $body['data'][0]['id']);
        $this->assertSame('GO', $body['data'][0]['status']);
        $this->assertSame(80, $body['data'][0]['pad']['id']);
        $this->assertSame('Space Launch Complex 40', $body['data'][0]['pad']['name']);
    }

    public function testRecentDefaults90DayWindow(): void
    {
        $body = $this->invoke(new RecentLaunchesController($this->db), 'GET', '/api/v1/launches/recent');
        $this->assertSame(1, $body['meta']['count']);
        $this->assertSame('uuid-recent', $body['data'][0]['id']);
        $this->assertSame(90, $body['meta']['days']);
    }

    public function testRecentRespectsExtendedDaysWindow(): void
    {
        $body = $this->invoke(new RecentLaunchesController($this->db), 'GET', '/api/v1/launches/recent?days=200');
        $this->assertSame(2, $body['meta']['count']);
        $this->assertSame(200, $body['meta']['days']);
    }

    public function testDetailReturnsParsedAssociatedNoradIds(): void
    {
        $body = $this->invoke(
            new LaunchDetailController($this->db),
            'GET', '/api/v1/launches/uuid-recent', ['id' => 'uuid-recent']
        );
        $this->assertSame('uuid-recent', $body['data']['id']);
        $this->assertSame('Atlas V | Recent USSF', $body['data']['name']);
        $this->assertSame([25544, 44713], $body['data']['associated_norad_ids']);
        $this->assertSame('USSF', $body['data']['customer']);
    }

    public function testDetailFourOhFoursOnUnknownId(): void
    {
        $this->expectException(HttpNotFoundException::class);
        $this->invoke(
            new LaunchDetailController($this->db),
            'GET', '/api/v1/launches/no-such-id', ['id' => 'no-such-id']
        );
    }

    public function testLaunchSiteListReturnsAllPadsAlphabetically(): void
    {
        $body = $this->invoke(new LaunchSiteListController($this->db), 'GET', '/api/v1/launch-sites');
        $this->assertSame(2, $body['meta']['count']);
        $this->assertSame('Space Launch Complex 40', $body['data'][0]['name']);
        $this->assertSame('Tyuratam', $body['data'][1]['name']);
        $this->assertEqualsWithDelta(28.5618, $body['data'][0]['latitude'], 1e-4);
    }

    /**
     * @param array<string, string> $args
     * @return array<string, mixed>
     */
    private function invoke(object $controller, string $method, string $uri, array $args = []): array
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);
        if (str_contains($uri, '?')) {
            parse_str(parse_url($uri, PHP_URL_QUERY) ?: '', $query);
            $request = $request->withQueryParams($query);
        }
        $response = $controller($request, new Response(), $args);
        return Json::decode((string) $response->getBody());
    }
}
