<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SatTrackr\Database\Connection;
use SatTrackr\Database\Migrator;
use SatTrackr\Http\Controllers\OgImageController;
use SatTrackr\Services\OgImageGenerator;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class OgImageControllerTest extends TestCase
{
    private string $tempDb = '';
    private string $tempCache = '';
    private Connection $db;

    protected function setUp(): void
    {
        $this->tempDb = tempnam(sys_get_temp_dir(), 'og-') . '.db';
        if (file_exists($this->tempDb)) unlink($this->tempDb);
        $this->tempCache = sys_get_temp_dir() . '/og-cache-' . uniqid();
        $this->db = new Connection($this->tempDb);
        (new Migrator($this->db, dirname(__DIR__, 3) . '/migrations'))->migrate();

        $now = '2026-05-16T00:00:00Z';
        $this->db->pdo()->exec("INSERT INTO satellites (norad_id, intl_designator, name, object_type, orbit_class, country, created_at, updated_at)
            VALUES (25544, '1998-067A', 'ISS (ZARYA)', 'PAYLOAD', 'LEO', 'US', '{$now}', '{$now}')");
    }

    protected function tearDown(): void
    {
        unset($this->db);
        foreach ([$this->tempDb, $this->tempDb . '-wal', $this->tempDb . '-shm'] as $f) {
            if (file_exists($f)) @unlink($f);
        }
        if (is_dir($this->tempCache)) {
            foreach (glob("{$this->tempCache}/*") ?: [] as $f) @unlink($f);
            @rmdir($this->tempCache);
        }
    }

    private function controller(): OgImageController
    {
        return new OgImageController(new OgImageGenerator(), $this->db, $this->tempCache);
    }

    /** @param array<string,string> $args */
    private function call(string $type, string $id): \Slim\Psr7\Response
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', "/og/{$type}/{$id}.png");
        $res = ($this->controller())($req, new Response(), ['type' => $type, 'id' => $id]);
        return $res;
    }

    public function testSatelliteCardReturnsPngWith200(): void
    {
        $res = $this->call('satellite', '25544');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('image/png', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('public, max-age=21600', $res->getHeaderLine('Cache-Control'));
        $body = (string) $res->getBody();
        $this->assertGreaterThan(2000, strlen($body));
        $info = getimagesizefromstring($body);
        $this->assertSame(OgImageGenerator::WIDTH,  $info[0]);
        $this->assertSame(OgImageGenerator::HEIGHT, $info[1]);
    }

    public function testUnknownNorad404s(): void
    {
        $this->expectException(HttpNotFoundException::class);
        $this->call('satellite', '99999999');
    }

    public function testUnknownTypeArgs404s(): void
    {
        $this->expectException(HttpNotFoundException::class);
        $this->call('not_a_real_type', 'whatever');
    }

    public function testEventsCardServesEvenWithEmptyConjunctionsTable(): void
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/og/events.png');
        $res = ($this->controller())($req, new Response(), ['type' => 'events', 'id' => '']);
        $this->assertSame(200, $res->getStatusCode());
        $info = getimagesizefromstring((string) $res->getBody());
        $this->assertSame(OgImageGenerator::WIDTH, $info[0]);
    }

    public function testSecondRequestServesFromDiskCache(): void
    {
        $first  = $this->call('satellite', '25544');
        $cacheFile = $this->tempCache . '/satellite-25544.png';
        $this->assertFileExists($cacheFile);
        $mtime1 = filemtime($cacheFile);
        clearstatcache();

        // Sleep less than the TTL window — the controller should NOT regenerate.
        usleep(50_000);
        $second = $this->call('satellite', '25544');
        $this->assertSame(200, $second->getStatusCode());
        $mtime2 = filemtime($cacheFile);
        $this->assertSame($mtime1, $mtime2, 'Cache file should not have been rewritten');

        // Bytes returned should match what's on disk.
        $this->assertSame((string) file_get_contents($cacheFile), (string) $second->getBody());
    }
}
