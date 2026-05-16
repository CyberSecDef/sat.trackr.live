<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Feature;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SatTrackr\Database\Connection;
use SatTrackr\Database\Migrator;
use SatTrackr\Ingest\SwpcClient;
use SatTrackr\Ingest\SwpcIngester;

final class SwpcIngesterTest extends TestCase
{
    private string $tempDb = '';
    private Connection $db;

    protected function setUp(): void
    {
        $this->tempDb = tempnam(sys_get_temp_dir(), 'swpc-') . '.db';
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

    /** @param list<Response> $responses */
    private function ingester(array $responses): SwpcIngester
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        return new SwpcIngester(
            client: new SwpcClient(new GuzzleClient(['handler' => $stack])),
            db:     $this->db,
        );
    }

    private function kpJson(): string
    {
        return json_encode([
            ['time_tag' => '2026-05-16T15:00:00', 'kp_index' => 2, 'estimated_kp' => 2.33, 'kp' => '2'],
            ['time_tag' => '2026-05-16T16:00:00', 'kp_index' => 3, 'estimated_kp' => 3.67, 'kp' => '3+'],
        ]) ?: '[]';
    }

    private function xrayJson(): string
    {
        return json_encode([
            ['time_tag' => '2026-05-16T15:59:00Z', 'flux' => 5.5e-7,  'energy' => '0.05-0.4nm'],
            ['time_tag' => '2026-05-16T16:00:00Z', 'flux' => 1.5e-5,  'energy' => '0.1-0.8nm'],
            ['time_tag' => '2026-05-16T16:01:00Z', 'flux' => 9.9e-7,  'energy' => '0.05-0.4nm'],
        ]) ?: '[]';
    }

    private function scalesJson(): string
    {
        return json_encode([
            '0' => [
                'DateStamp' => '2026-05-16', 'TimeStamp' => '16:00:00',
                'R' => ['Scale' => '1', 'Text' => 'minor'],
                'S' => ['Scale' => '0', 'Text' => 'none'],
                'G' => ['Scale' => '2', 'Text' => 'moderate'],
            ],
        ]) ?: '{}';
    }

    public function testRunInsertsRowWithDerivedFields(): void
    {
        $ingester = $this->ingester([
            new Response(200, [], $this->kpJson()),
            new Response(200, [], $this->xrayJson()),
            new Response(200, [], $this->scalesJson()),
        ]);
        $sample = $ingester->run();

        // estimated_kp picked over kp_index when both present.
        $this->assertEqualsWithDelta(3.67, $sample['kp'], 1e-6);
        // X-ray latest 0.1-0.8nm sample → 1.5e-5 → 'M'
        $this->assertEqualsWithDelta(1.5e-5, $sample['x_ray_flux'], 1e-9);
        $this->assertSame('M', $sample['x_ray_class']);
        // Scale values parsed as ints
        $this->assertSame(1, $sample['r_level']);
        $this->assertSame(0, $sample['s_level']);
        $this->assertSame(2, $sample['g_level']);

        // One row in DB
        $count = (int) $this->db->pdo()->query('SELECT COUNT(*) FROM space_weather_samples')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testRunSkipsLongBandXraySamples(): void
    {
        // Only the 0.1-0.8nm samples matter; the latest of those wins.
        $ingester = $this->ingester([
            new Response(200, [], $this->kpJson()),
            new Response(200, [], $this->xrayJson()),    // last 0.1-0.8nm row is the +1min sample with 1.5e-5
            new Response(200, [], $this->scalesJson()),
        ]);
        $sample = $ingester->run();
        $this->assertEqualsWithDelta(1.5e-5, $sample['x_ray_flux'], 1e-9);
    }

    public function testRunSurvivesIndividualEndpointFailure(): void
    {
        // X-ray endpoint 500s — should still insert a row with X-ray = null.
        $ingester = $this->ingester([
            new Response(200, [], $this->kpJson()),
            new Response(500, [], 'goes down'),
            new Response(200, [], $this->scalesJson()),
        ]);
        $sample = $ingester->run();
        $this->assertNull($sample['x_ray_flux']);
        $this->assertNull($sample['x_ray_class']);
        $this->assertEqualsWithDelta(3.67, $sample['kp'], 1e-6);
    }

    public function testXrayClassThresholdsMapCorrectly(): void
    {
        $this->assertSame('A', SwpcIngester::xrayClass(9e-8));
        $this->assertSame('B', SwpcIngester::xrayClass(5e-7));
        $this->assertSame('C', SwpcIngester::xrayClass(5e-6));
        $this->assertSame('M', SwpcIngester::xrayClass(5e-5));
        $this->assertSame('X', SwpcIngester::xrayClass(1.5e-4));
    }
}
