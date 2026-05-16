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
use SatTrackr\Ingest\SatnogsClient;
use SatTrackr\Ingest\SatnogsIngester;

final class SatnogsIngesterTest extends TestCase
{
    private string $tempDb = '';
    private Connection $db;

    protected function setUp(): void
    {
        $this->tempDb = tempnam(sys_get_temp_dir(), 'satnogs-') . '.db';
        if (file_exists($this->tempDb)) unlink($this->tempDb);
        $this->db = new Connection($this->tempDb);
        (new Migrator($this->db, dirname(__DIR__, 3) . '/migrations'))->migrate();

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
        $this->db->pdo()->exec("INSERT INTO satellites (norad_id, intl_designator, name, created_at, updated_at)
                                VALUES (25544, '1998-067A', 'ISS', '{$now}', '{$now}'),
                                       (44713, '2019-074A', 'STARLINK-1007', '{$now}', '{$now}')");
    }

    protected function tearDown(): void
    {
        unset($this->db);
        foreach ([$this->tempDb, $this->tempDb . '-wal', $this->tempDb . '-shm'] as $f) {
            if (file_exists($f)) @unlink($f);
        }
    }

    /** @param list<Response> $responses */
    private function ingester(array $responses): SatnogsIngester
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $guzzle = new GuzzleClient(['handler' => $stack]);
        return new SatnogsIngester(new SatnogsClient($guzzle), $this->db);
    }

    private function sampleBody(): string
    {
        return json_encode([
            // ISS APRS — matches our NORAD
            ['uuid' => 'aaa', 'norad_cat_id' => 25544, 'description' => 'Mode V APRS',
             'type' => 'Transceiver', 'alive' => true,
             'uplink_low' => 145825000, 'uplink_high' => null,
             'downlink_low' => 145825000, 'downlink_high' => null,
             'mode' => 'AFSK', 'baud' => 1200.0,
             'service' => 'Amateur', 'status' => 'active'],
            // ISS voice repeater
            ['uuid' => 'bbb', 'norad_cat_id' => 25544, 'description' => 'Voice repeater',
             'type' => 'Transceiver', 'alive' => true,
             'uplink_low' => 437800000, 'downlink_low' => 145990000,
             'mode' => 'FM', 'baud' => null,
             'service' => 'Amateur', 'status' => 'active'],
            // Orphan NORAD — not in our satellites table
            ['uuid' => 'ccc', 'norad_cat_id' => 99999, 'description' => 'Some random sat',
             'type' => 'Transmitter', 'alive' => false,
             'downlink_low' => 137100000,
             'mode' => 'LRPT', 'service' => 'Operational', 'status' => 'inactive'],
            // Missing UUID — malformed
            ['norad_cat_id' => 44713, 'description' => 'broken row'],
        ]) ?: '[]';
    }

    public function testRunUpsertsKnownNoradsAndSkipsOrphans(): void
    {
        $report = $this->ingester([new Response(200, [], $this->sampleBody())])->run();

        $this->assertSame(4, $report['fetched']);
        $this->assertSame(2, $report['upserted']);            // ISS rows
        $this->assertSame(1, $report['skipped_orphan']);      // norad 99999
        $this->assertSame(1, $report['skipped_malformed']);   // missing uuid

        $count = (int) $this->db->pdo()->query('SELECT COUNT(*) FROM satellite_radio')->fetchColumn();
        $this->assertSame(2, $count);
    }

    public function testRowFieldsMapCorrectly(): void
    {
        $this->ingester([new Response(200, [], $this->sampleBody())])->run();
        $row = $this->db->pdo()->query("SELECT * FROM satellite_radio WHERE uuid='aaa'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('Mode V APRS',  $row['description']);
        $this->assertSame('Transceiver',  $row['type']);
        $this->assertSame(1,              (int) $row['alive']);
        $this->assertSame(145825000,      (int) $row['uplink_low_hz']);
        $this->assertSame(145825000,      (int) $row['downlink_low_hz']);
        $this->assertSame('AFSK',         $row['mode']);
        $this->assertEqualsWithDelta(1200.0, (float) $row['baud'], 1e-6);
        $this->assertSame('Amateur',      $row['service']);
    }

    public function testReRunIsIdempotent(): void
    {
        $this->ingester([new Response(200, [], $this->sampleBody())])->run();
        $report2 = $this->ingester([new Response(200, [], $this->sampleBody())])->run();
        $this->assertSame(2, $report2['upserted']);
        $count = (int) $this->db->pdo()->query('SELECT COUNT(*) FROM satellite_radio')->fetchColumn();
        $this->assertSame(2, $count);
    }

    public function testReRunRefreshesChangedRow(): void
    {
        $this->ingester([new Response(200, [], $this->sampleBody())])->run();

        // Mutate the second pass: ISS APRS goes dead, mode changes.
        $second = json_decode($this->sampleBody(), true);
        $second[0]['alive'] = false;
        $second[0]['mode'] = 'PSK';
        $this->ingester([new Response(200, [], json_encode($second) ?: '[]')])->run();

        $row = $this->db->pdo()->query("SELECT alive, mode FROM satellite_radio WHERE uuid='aaa'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(0,    (int) $row['alive']);
        $this->assertSame('PSK', $row['mode']);
    }

    public function testFetchFailurePropagatesAndKeepsTableUntouched(): void
    {
        $this->expectException(\RuntimeException::class);
        try {
            $this->ingester([new Response(500, [], 'satnogs down')])->run();
        } finally {
            $count = (int) $this->db->pdo()->query('SELECT COUNT(*) FROM satellite_radio')->fetchColumn();
            $this->assertSame(0, $count);
        }
    }
}
