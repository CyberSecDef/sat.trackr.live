<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SatTrackr\Database\Connection;
use SatTrackr\Database\Migrator;
use SatTrackr\Http\Controllers\SpaceWeather24hController;
use SatTrackr\Http\Controllers\SpaceWeatherNowController;
use SatTrackr\Support\Json;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class SpaceWeatherApiTest extends TestCase
{
    private string $tempDb = '';
    private Connection $db;

    protected function setUp(): void
    {
        $this->tempDb = tempnam(sys_get_temp_dir(), 'swpc-api-') . '.db';
        if (file_exists($this->tempDb)) {
            unlink($this->tempDb);
        }
        $this->db = new Connection($this->tempDb);
        (new Migrator($this->db, dirname(__DIR__, 3) . '/migrations'))->migrate();
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

    private function seed(string $sampledAt, float $kp, ?string $xrayClass = 'C', int $g = 0): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO space_weather_samples
              (sampled_at, kp, x_ray_flux, x_ray_class, r_level, s_level, g_level, raw_message, created_at)
             VALUES (:sampled_at, :kp, :xf, :xc, 0, 0, :g, NULL, :now)'
        )->execute([
            'sampled_at' => $sampledAt,
            'kp'         => $kp,
            'xf'         => 3.5e-6,
            'xc'         => $xrayClass,
            'g'          => $g,
            'now'        => $sampledAt,
        ]);
    }

    public function testNowReturnsLatestSample(): void
    {
        $this->seed('2026-05-16T15:00:00Z', 2.0, 'C', 0);
        $this->seed('2026-05-16T16:00:00Z', 4.5, 'M', 3);
        $body = $this->invoke(new SpaceWeatherNowController($this->db), '/api/v1/space-weather/now');
        $this->assertSame('2026-05-16T16:00:00Z', $body['data']['sampled_at']);
        $this->assertEqualsWithDelta(4.5, $body['data']['kp'], 1e-6);
        $this->assertSame('M', $body['data']['x_ray_class']);
        $this->assertSame(3, $body['data']['g_level']);
    }

    public function testNowFourOhFoursWhenTableEmpty(): void
    {
        $this->expectException(HttpNotFoundException::class);
        $this->invoke(new SpaceWeatherNowController($this->db), '/api/v1/space-weather/now');
    }

    public function testTrendReturnsLast24hAscending(): void
    {
        // Two in window, one outside.
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->seed($now->modify('-2 days')->format('Y-m-d\TH:i:s\Z'), 1.0);     // outside
        $this->seed($now->modify('-12 hours')->format('Y-m-d\TH:i:s\Z'), 2.5);   // in
        $this->seed($now->modify('-2 hours')->format('Y-m-d\TH:i:s\Z'),  3.5);   // in

        $body = $this->invoke(new SpaceWeather24hController($this->db), '/api/v1/space-weather/24h');
        $this->assertSame(2, $body['meta']['count']);
        // ASC ordering: older first
        $this->assertEqualsWithDelta(2.5, $body['data'][0]['kp'], 1e-6);
        $this->assertEqualsWithDelta(3.5, $body['data'][1]['kp'], 1e-6);
    }

    public function testTrendEmptyWhenNoSamplesInWindow(): void
    {
        $this->seed('2020-01-01T00:00:00Z', 1.0);
        $body = $this->invoke(new SpaceWeather24hController($this->db), '/api/v1/space-weather/24h');
        $this->assertSame(0, $body['meta']['count']);
        $this->assertSame([], $body['data']);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoke(object $controller, string $uri): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $uri);
        $response = $controller($request, new Response(), []);
        return Json::decode((string) $response->getBody());
    }
}
