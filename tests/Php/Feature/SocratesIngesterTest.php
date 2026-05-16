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
use SatTrackr\Ingest\SocratesClient;
use SatTrackr\Ingest\SocratesCsvParser;
use SatTrackr\Ingest\SocratesIngester;

/**
 * End-to-end: mock SOCRATES CSV → real SQLite DB through the
 * upsert path with the chunk-1A unique-index dedup.
 */
final class SocratesIngesterTest extends TestCase
{
    private string $tempDb = '';
    private Connection $db;

    private const HEADER = "NORAD_CAT_ID_1,OBJECT_NAME_1,DSE_1,NORAD_CAT_ID_2,OBJECT_NAME_2,DSE_2,TCA,TCA_RANGE,TCA_RELATIVE_SPEED,MAX_PROB,DILUTION\n";

    protected function setUp(): void
    {
        $this->tempDb = tempnam(sys_get_temp_dir(), 'socrates-') . '.db';
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
    private function ingester(array $responses): SocratesIngester
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $guzzle = new GuzzleClient(['handler' => $stack]);
        return new SocratesIngester(
            client: new SocratesClient($guzzle),
            parser: new SocratesCsvParser(),
            db:     $this->db,
        );
    }

    private function rowsAt(string $isoOffset): string
    {
        // Build TCAs relative to "now" so the chunk-2 window filter passes.
        $tcaSoon  = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d H:i:s.000');
        $tcaLater = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+30 days')->format('Y-m-d H:i:s.000');
        return self::HEADER
            . "48667,STARLINK-2735 [P],4.444,58934,STARLINK-31337 [+],5.386,{$tcaSoon},0.021,12.104,4.472E-01,0.005\n"
            . "59763,STARLINK-11104 [+],4.858,54039,CZ-2C R/B [-],4.746,{$tcaSoon},0.023,9.808,1.586E-01,0.007\n"
            . "25544,ISS (ZARYA) [+],1.000,99999,DEBRIS X [-],2.000,{$tcaLater},5.0,7.5,1.0E-3,0.5\n";
    }

    public function testRunInsertsConjunctionsWithinTcaWindow(): void
    {
        $csv = $this->rowsAt('+2 days');
        $report = $this->ingester([new Response(200, [], $csv)])->run(168);

        $this->assertSame(3, $report->rowsParsed);
        $this->assertSame(2, $report->upserted);    // ISS row outside the 168h window
        $this->assertSame(2, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM conjunctions')->fetchColumn());
    }

    public function testRunRespectsLargerWindow(): void
    {
        $csv = $this->rowsAt('+2 days');
        $report = $this->ingester([new Response(200, [], $csv)])->run(24 * 31); // 31d

        $this->assertSame(3, $report->upserted);
    }

    public function testReRunUpdatesRangeForSamePairAndTca(): void
    {
        $tcaSoon = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+1 days')->format('Y-m-d H:i:s.000');
        $first   = self::HEADER . "12345,A,1,67890,B,1,{$tcaSoon},5.000,10.0,1.0E-3,0.01\n";
        $second  = self::HEADER . "12345,A,1,67890,B,1,{$tcaSoon},1.234,10.0,2.5E-2,0.04\n";

        $this->ingester([new Response(200, [], $first)])->run(168);
        $report2 = $this->ingester([new Response(200, [], $second)])->run(168);

        $this->assertSame(1, $report2->upserted);
        $this->assertSame(1, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM conjunctions')->fetchColumn());

        $row = $this->db->pdo()->query('SELECT tca_range_km, max_probability FROM conjunctions')->fetch(\PDO::FETCH_ASSOC);
        $this->assertEqualsWithDelta(1.234, (float) $row['tca_range_km'], 1e-6);
        $this->assertEqualsWithDelta(0.025, (float) $row['max_probability'], 1e-6);
    }

    public function testFetchFailureRecordsErrorAndPersistsNothing(): void
    {
        $report = $this->ingester([new Response(500, [], 'celestrak temporarily down')])->run(168);

        $this->assertSame(0, $report->upserted);
        $this->assertCount(1, $report->errors);
        $this->assertSame('fetch', $report->errors[0]['stage']);
        $this->assertSame(0, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM conjunctions')->fetchColumn());
    }

    public function testWrongHeaderRecordsParseError(): void
    {
        $report = $this->ingester([new Response(200, [], "FOO,BAR\n1,2\n")])->run(168);
        $this->assertSame(0, $report->upserted);
        $this->assertSame('parse', $report->errors[0]['stage']);
    }
}
