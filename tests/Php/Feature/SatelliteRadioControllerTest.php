<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SatTrackr\Database\Connection;
use SatTrackr\Database\Migrator;
use SatTrackr\Http\Controllers\SatelliteRadioController;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class SatelliteRadioControllerTest extends TestCase
{
    private string $tempDb = '';
    private Connection $db;

    protected function setUp(): void
    {
        $this->tempDb = tempnam(sys_get_temp_dir(), 'satnogs-api-') . '.db';
        if (file_exists($this->tempDb)) unlink($this->tempDb);
        $this->db = new Connection($this->tempDb);
        (new Migrator($this->db, dirname(__DIR__, 3) . '/migrations'))->migrate();

        $now = '2026-05-16T00:00:00Z';
        $pdo = $this->db->pdo();
        $pdo->exec("INSERT INTO satellites (norad_id, intl_designator, name, created_at, updated_at) VALUES
            (25544, '1998-067A', 'ISS (ZARYA)', '{$now}', '{$now}'),
            (44713, '2019-074A', 'STARLINK-1007', '{$now}', '{$now}')");

        $pdo->exec("INSERT INTO satellite_radio
            (uuid, norad_id, description, type, alive, downlink_low_hz, mode, baud, service, status, updated_at) VALUES
            ('aaa', 25544, 'Mode V APRS',     'Transceiver', 1, 145825000, 'AFSK', 1200, 'Amateur', 'active',   '{$now}'),
            ('bbb', 25544, 'Voice repeater',  'Transceiver', 1, 145990000, 'FM',   NULL, 'Amateur', 'active',   '{$now}'),
            ('ccc', 25544, 'Old beacon',      'Transmitter', 0, 437800000, 'CW',   NULL, 'Amateur', 'inactive', '{$now}')");
    }

    protected function tearDown(): void
    {
        unset($this->db);
        foreach ([$this->tempDb, $this->tempDb . '-wal', $this->tempDb . '-shm'] as $f) {
            if (file_exists($f)) @unlink($f);
        }
    }

    /** @return array<string, mixed> */
    private function call(int $norad): array
    {
        $controller = new SatelliteRadioController($this->db);
        $req = (new ServerRequestFactory())->createServerRequest('GET', "/api/v1/satellites/{$norad}/radio");
        $resp = $controller($req, new Response(), ['norad' => (string) $norad]);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $resp->getBody(), true);
        return $decoded;
    }

    public function testReturnsTransmittersForKnownSatellite(): void
    {
        $body = $this->call(25544);
        $this->assertCount(3, $body['data']);
        $first = $body['data'][0];
        $this->assertTrue($first['alive']);
        $this->assertSame('Mode V APRS', $first['description']);
        $this->assertSame(145825000, $first['downlink_low_hz']);
        $this->assertSame('AFSK', $first['mode']);
        $this->assertEqualsWithDelta(1200.0, $first['baud'], 1e-6);
        // alive=false sorted last
        $this->assertFalse($body['data'][2]['alive']);
        $this->assertSame('Old beacon', $body['data'][2]['description']);
    }

    public function testReturnsEmptyArrayWhenNoTransmitters(): void
    {
        $body = $this->call(44713);
        $this->assertSame([], $body['data']);
    }

    public function testThrows404WhenSatelliteUnknown(): void
    {
        $this->expectException(HttpNotFoundException::class);
        $this->call(99999);
    }
}
