<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SatTrackr\Database\Connection;
use SatTrackr\Database\Migrator;
use SatTrackr\Http\Controllers\ReentryDetailController;
use SatTrackr\Http\Controllers\ReentryListController;
use SatTrackr\Support\Json;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class ReentryApiTest extends TestCase
{
    private string $tempDb = '';
    private Connection $db;

    protected function setUp(): void
    {
        $this->tempDb = tempnam(sys_get_temp_dir(), 'reentry-api-') . '.db';
        if (file_exists($this->tempDb)) {
            unlink($this->tempDb);
        }
        $this->db = new Connection($this->tempDb);
        (new Migrator($this->db, dirname(__DIR__, 3) . '/migrations'))->migrate();

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
        $pdo = $this->db->pdo();

        $pdo->exec("INSERT INTO satellites
            (norad_id, intl_designator, name, object_type, country, status, created_at, updated_at)
            VALUES
              (25544, '1998-067A', 'ISS (ZARYA)',    'PAYLOAD',     'US', 'ACTIVE', '{$now}', '{$now}'),
              (44713, '2019-074A', 'STARLINK-1007',  'PAYLOAD',     'US', 'ACTIVE', '{$now}', '{$now}'),
              (50123, '2021-099C', 'ROCKET BODY X',  'ROCKET_BODY', 'CN', 'ACTIVE', '{$now}', '{$now}')");

        $soon   = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d\TH:i:s\Z');
        $later  = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+10 days')->format('Y-m-d\TH:i:s\Z');
        $past   = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-1 days')->format('Y-m-d\TH:i:s\Z');

        $pdo->exec("INSERT INTO reentries
            (norad_id, predicted_decay, confidence_window_hours, source, risk_score, raw_message, created_at, updated_at)
            VALUES
              (25544, '{$soon}',  6.0,  'SPACE_TRACK_TIP',  3.5, '{\"NORAD_CAT_ID\":\"25544\"}', '{$now}', '{$now}'),
              (44713, '{$later}', 24.0, 'SPACE_TRACK_TIP',  null, '{\"NORAD_CAT_ID\":\"44713\"}','{$now}', '{$now}'),
              (50123, '{$past}',  12.0, 'CELESTRAK_SATCAT', null, null,                          '{$now}', '{$now}')");
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

    public function testUpcomingDefaultsTo7DayWindowAndOrdersByDecay(): void
    {
        $body = $this->invoke(new ReentryListController($this->db), '/api/v1/reentries/upcoming');
        $this->assertSame(1, $body['meta']['count']);
        $this->assertSame(168, $body['meta']['within_hours']);
        $this->assertSame(25544, $body['data'][0]['norad_id']);
        $this->assertSame('ISS (ZARYA)', $body['data'][0]['name']);
        $this->assertSame(3.5, $body['data'][0]['risk_score']);
    }

    public function testUpcomingExtendedWindowReturnsBothFutureRows(): void
    {
        $body = $this->invoke(new ReentryListController($this->db), '/api/v1/reentries/upcoming?within_hours=480');
        $this->assertSame(2, $body['meta']['count']);
        $this->assertSame(480, $body['meta']['within_hours']);
        $this->assertSame([25544, 44713], array_column($body['data'], 'norad_id'));
    }

    public function testUpcomingClampsWithinHoursToCeiling(): void
    {
        $body = $this->invoke(new ReentryListController($this->db), '/api/v1/reentries/upcoming?within_hours=99999');
        $this->assertSame(720, $body['meta']['within_hours']);
    }

    public function testDetailReturnsParsedRawMessageAndSatelliteBlock(): void
    {
        $body = $this->invoke(
            new ReentryDetailController($this->db),
            '/api/v1/reentries/25544',
            ['norad' => '25544']
        );
        $this->assertSame(25544, $body['data']['norad_id']);
        $this->assertSame('SPACE_TRACK_TIP', $body['data']['source']);
        $this->assertIsArray($body['data']['raw_message']);
        $this->assertSame('25544', $body['data']['raw_message']['NORAD_CAT_ID']);
        $this->assertSame('ISS (ZARYA)', $body['data']['satellite']['name']);
        $this->assertSame('PAYLOAD', $body['data']['satellite']['object_type']);
    }

    public function testDetailFourOhFoursOnUnknownNorad(): void
    {
        $this->expectException(HttpNotFoundException::class);
        $this->invoke(
            new ReentryDetailController($this->db),
            '/api/v1/reentries/99999',
            ['norad' => '99999']
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
