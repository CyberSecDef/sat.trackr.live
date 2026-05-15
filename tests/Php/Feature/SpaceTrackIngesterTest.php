<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Feature;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SatTrackr\Database\Connection;
use SatTrackr\Database\Migrator;
use SatTrackr\Ingest\SpaceTrackClient;
use SatTrackr\Ingest\SpaceTrackIngester;

/**
 * End-to-end TIP ingest: mock Space-Track responses → real SQLite DB.
 */
final class SpaceTrackIngesterTest extends TestCase
{
    private string $tempDb = '';
    private Connection $db;

    private const TIP_RESPONSE = <<<'JSON'
        [
          {"NORAD_CAT_ID":"25544","INSERT_EPOCH":"2026-05-12T08:00:00","MSG_EPOCH":"2026-05-12T07:55:00","DECAY_EPOCH":"2026-05-15T14:23:00","WINDOW":"720","REV":"38491","DIRECTION":"ascending","LAT":"-31.0","LON":"144.0","INCL":"51.6","NEXT_REPORT":"6"},
          {"NORAD_CAT_ID":"44713","INSERT_EPOCH":"2026-05-12T07:00:00","MSG_EPOCH":"2026-05-12T06:55:00","DECAY_EPOCH":"2026-05-18T02:00:00","WINDOW":"360","REV":"21477","DIRECTION":"descending","LAT":"45.0","LON":"-100.0","INCL":"53.0","NEXT_REPORT":"6"},
          {"NORAD_CAT_ID":"99999","INSERT_EPOCH":"2026-05-12T05:00:00","MSG_EPOCH":"2026-05-12T04:55:00","DECAY_EPOCH":"2026-05-20T08:00:00","WINDOW":"1440","REV":"112","DIRECTION":"ascending","LAT":"0.0","LON":"0.0","INCL":"15.0","NEXT_REPORT":"12"}
        ]
        JSON;

    protected function setUp(): void
    {
        $this->tempDb = tempnam(sys_get_temp_dir(), 'spacetrack-ing-') . '.db';
        if (file_exists($this->tempDb)) {
            unlink($this->tempDb);
        }
        $this->db = new Connection($this->tempDb);
        (new Migrator($this->db, dirname(__DIR__, 3) . '/migrations'))->migrate();

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
        $pdo = $this->db->pdo();
        $pdo->exec("INSERT INTO satellites (norad_id, intl_designator, name, created_at, updated_at)
                    VALUES (25544, '1998-067A', 'ISS (ZARYA)',  '{$now}', '{$now}'),
                           (44713, '2019-074A', 'STARLINK-1007','{$now}', '{$now}')");
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
    private function ingester(array $responses): SpaceTrackIngester
    {
        $stack  = HandlerStack::create(new MockHandler($responses));
        $guzzle = new GuzzleClient(['handler' => $stack]);
        $client = new SpaceTrackClient($guzzle, 'u@example.com', 'pw', new CookieJar());
        return new SpaceTrackIngester($client, $this->db);
    }

    public function testRunUpsertsKnownNoradAndSkipsUnknown(): void
    {
        $ingester = $this->ingester([
            new Response(200, [], ''),                     // login
            new Response(200, [], self::TIP_RESPONSE),     // tip query
        ]);

        $report = $ingester->run(100);

        $this->assertSame(3, $report->tipsFetched);
        $this->assertSame(2, $report->reentriesUpserted);
        $this->assertSame(1, $report->skippedUnknownNorad);
        $this->assertSame(0, $report->skippedMalformed);

        $rows = $this->db->pdo()->query('SELECT norad_id, predicted_decay, confidence_window_hours, source FROM reentries ORDER BY norad_id')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(2, $rows);
        $this->assertSame(25544, (int) $rows[0]['norad_id']);
        $this->assertSame('2026-05-15T14:23:00', $rows[0]['predicted_decay']);
        $this->assertEqualsWithDelta(12.0, (float) $rows[0]['confidence_window_hours'], 1e-3);  // 720 / 60
        $this->assertSame('SPACE_TRACK_TIP', $rows[0]['source']);
    }

    public function testReRunIsIdempotentAndRefreshesPrediction(): void
    {
        // First run with a 720-min window
        $this->ingester([
            new Response(200, [], ''),
            new Response(200, [], self::TIP_RESPONSE),
        ])->run(50);

        // Second run with a tightened window for the same NORAD
        $tightened = json_encode([[
            'NORAD_CAT_ID' => '25544',
            'INSERT_EPOCH' => '2026-05-12T20:00:00',
            'MSG_EPOCH'    => '2026-05-12T19:55:00',
            'DECAY_EPOCH'  => '2026-05-15T14:30:00',
            'WINDOW'       => '120',
            'REV'          => '38493',
            'DIRECTION'    => 'ascending',
            'LAT'          => '-31.0',
            'LON'          => '144.0',
            'INCL'         => '51.6',
            'NEXT_REPORT'  => '3',
        ]]);
        $report = $this->ingester([
            new Response(200, [], ''),
            new Response(200, [], (string) $tightened),
        ])->run(50);

        $this->assertSame(1, $report->reentriesUpserted);
        $rows = $this->db->pdo()->query('SELECT COUNT(*) FROM reentries')->fetchColumn();
        $this->assertSame(2, (int) $rows); // still 2 rows total (idempotent)

        $row = $this->db->pdo()->query("SELECT predicted_decay, confidence_window_hours FROM reentries WHERE norad_id = 25544")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('2026-05-15T14:30:00', $row['predicted_decay']);
        $this->assertEqualsWithDelta(2.0, (float) $row['confidence_window_hours'], 1e-3); // 120/60
    }

    public function testRawMessageStoredAsJson(): void
    {
        $this->ingester([
            new Response(200, [], ''),
            new Response(200, [], self::TIP_RESPONSE),
        ])->run(50);

        $raw = $this->db->pdo()->query("SELECT raw_message FROM reentries WHERE norad_id = 25544")->fetchColumn();
        $decoded = json_decode((string) $raw, true);
        $this->assertIsArray($decoded);
        $this->assertSame('25544', $decoded['NORAD_CAT_ID']);
        $this->assertSame('38491', $decoded['REV']);
    }

    public function testFetchFailureIsCapturedInReport(): void
    {
        $report = $this->ingester([
            new Response(200, [], ''),
            new Response(500, [], 'maintenance window'),
        ])->run(50);

        $this->assertSame(0, $report->reentriesUpserted);
        $this->assertCount(1, $report->errors);
        $this->assertSame('fetch', $report->errors[0]['stage']);
    }

    public function testMalformedTipIsSkipped(): void
    {
        $payload = json_encode([
            ['INSERT_EPOCH' => '2026-05-12T08:00:00'],          // no NORAD
            ['NORAD_CAT_ID' => '25544'],                         // no DECAY_EPOCH
            ['NORAD_CAT_ID' => '44713', 'DECAY_EPOCH' => '2026-05-18T02:00:00', 'WINDOW' => '60'],
        ]);
        $report = $this->ingester([
            new Response(200, [], ''),
            new Response(200, [], (string) $payload),
        ])->run(50);

        $this->assertSame(3, $report->tipsFetched);
        $this->assertSame(1, $report->reentriesUpserted);
        $this->assertSame(2, $report->skippedMalformed);
    }
}
