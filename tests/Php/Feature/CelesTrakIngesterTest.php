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
use SatTrackr\Ingest\CelesTrakClient;
use SatTrackr\Ingest\CelesTrakIngester;
use SatTrackr\Ingest\TleParser;

final class CelesTrakIngesterTest extends TestCase
{
    private string $tempDb = '';
    private Connection $db;

    /** Real ISS TLE — used as the canned response in most tests. */
    private const ISS_BODY = <<<'TLE'
        ISS (ZARYA)
        1 25544U 98067A   26134.19858747  .00005122  00000+0  10032-3 0  9993
        2 25544  51.6312 108.3512 0007535  56.9254 303.2457 15.49211692566484
        TLE;

    /** Same satellite, fresher epoch (one day later) — checksums recomputed. */
    private static function issBodyNextDay(): string
    {
        $line1Body = '1 25544U 98067A   26135.19858747  .00005122  00000+0  10032-3 0  999';
        $line2Body = '2 25544  51.6312 108.3512 0007535  56.9254 303.2457 15.4921169256648';
        return "ISS (ZARYA)\n"
             . $line1Body . self::checksumDigit($line1Body) . "\n"
             . $line2Body . self::checksumDigit($line2Body) . "\n";
    }

    /** Mod-10 TLE checksum digit for a 68-char body. */
    private static function checksumDigit(string $body): string
    {
        $sum = 0;
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $c = $body[$i];
            if ($c >= '0' && $c <= '9') {
                $sum += (int) $c;
            } elseif ($c === '-') {
                $sum += 1;
            }
        }
        return (string) ($sum % 10);
    }

    protected function setUp(): void
    {
        $this->tempDb = tempnam(sys_get_temp_dir(), 'sat-ingest-test-') . '.db';
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

    public function testIngestsOneGroupAndPopulatesAllFourTables(): void
    {
        $ingester = $this->makeIngester([new Response(200, [], self::ISS_BODY)]);

        $report = $ingester->run(['stations']);

        $this->assertSame(1, $report->groupsProcessed);
        $this->assertSame(1, $report->satellitesUpserted);
        $this->assertSame(1, $report->tleCurrentUpserted);
        $this->assertSame(1, $report->tleHistoryAdded);
        $this->assertSame(0, $report->tleRejected);
        $this->assertSame([], $report->errors);

        // Row checks
        $this->assertSame(1, $this->countRows('satellites'));
        $this->assertSame(1, $this->countRows('tle_current'));
        $this->assertSame(1, $this->countRows('tle_history'));
        $this->assertSame(1, $this->countRows('group_membership'));

        // Field correctness on the ISS row
        $sat = $this->fetchOne('SELECT * FROM satellites WHERE norad_id = 25544');
        $this->assertSame('ISS (ZARYA)', $sat['name']);
        $this->assertSame('1998-067A', $sat['intl_designator']);

        $tle = $this->fetchOne('SELECT * FROM tle_current WHERE norad_id = 25544');
        $this->assertEqualsWithDelta(15.49211692, (float) $tle['mean_motion'], 1e-7);
        $this->assertEqualsWithDelta(51.6312, (float) $tle['inclination_deg'], 1e-4);
        $this->assertSame('CELESTRAK', $tle['source']);

        // Group membership
        $mem = $this->fetchOne('SELECT * FROM group_membership WHERE norad_id = 25544');
        $this->assertSame('stations', $mem['group_slug']);
    }

    public function testSameSatelliteInTwoGroupsGetsTwoMembershipRows(): void
    {
        $ingester = $this->makeIngester([
            new Response(200, [], self::ISS_BODY),
            new Response(200, [], self::ISS_BODY),
        ]);

        $ingester->run(['active', 'stations']);

        $this->assertSame(1, $this->countRows('satellites'));
        $this->assertSame(2, $this->countRows('group_membership'));

        $stmt = $this->db->pdo()->query("SELECT group_slug FROM group_membership WHERE norad_id = 25544 ORDER BY group_slug");
        $slugs = $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);
        $this->assertSame(['active', 'stations'], $slugs);
    }

    public function testRerunningWithIdenticalDataAddsNoHistoryRow(): void
    {
        $ingester = $this->makeIngester([
            new Response(200, [], self::ISS_BODY),
            new Response(200, [], self::ISS_BODY),
        ]);

        $first = $ingester->run(['stations']);
        $second = $ingester->run(['stations']);

        $this->assertSame(1, $first->tleHistoryAdded);
        $this->assertSame(0, $second->tleHistoryAdded, 'Re-running with the same epoch must not add a history row');
        $this->assertSame(1, $this->countRows('tle_history'));
    }

    public function testNewEpochAddsAnotherHistoryRow(): void
    {
        $ingester = $this->makeIngester([
            new Response(200, [], self::ISS_BODY),
            new Response(200, [], self::issBodyNextDay()),
        ]);

        $ingester->run(['stations']);
        $second = $ingester->run(['stations']);

        $this->assertSame(1, $second->tleHistoryAdded, 'A fresher epoch should append a new history row');
        $this->assertSame(2, $this->countRows('tle_history'));
        $this->assertSame(1, $this->countRows('tle_current'), 'tle_current should still have one row per object');
    }

    public function testGroupFetchFailureLoggedAndOtherGroupsContinue(): void
    {
        // The test Guzzle client has no retry middleware, so each MockHandler
        // response = one fetch attempt. Group 1 fails, group 2 succeeds.
        $ingester = $this->makeIngester([
            new Response(500, [], 'oops'),
            new Response(200, [], self::ISS_BODY),
        ]);

        $report = $ingester->run(['will-fail', 'stations']);

        $this->assertSame(1, $report->groupsProcessed, 'Only the successful group counts as processed');
        $this->assertCount(1, $report->errors);
        $this->assertSame('will-fail', $report->errors[0]['group']);
        $this->assertSame(1, $this->countRows('satellites'));
    }

    public function testInvalidTleRecordedAsRejectAndDoesNotAbortGroup(): void
    {
        // Tamper line 1 to break the checksum. The good ISS triplet follows.
        $bad = "BAD SAT                 \n"
             . "1 25544U 98067A   26134.19858747  .00005122  00000+0  10032-3 0  9999\n"
             . "2 25544  51.6312 108.3512 0007535  56.9254 303.2457 15.49211692566484\n";
        $body = $bad . trim(self::ISS_BODY) . "\n";

        $ingester = $this->makeIngester([new Response(200, [], $body)]);
        $report = $ingester->run(['stations']);

        $this->assertSame(1, $report->tleRejected);
        $this->assertSame(1, $report->satellitesUpserted, 'The good record after the bad one still ingests');
        $this->assertNotEmpty($report->rejects);
        $this->assertStringContainsString('Checksum failure', $report->rejects[0]['reason']);
    }

    /**
     * @param list<Response> $responses
     */
    private function makeIngester(array $responses): CelesTrakIngester
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $http = new GuzzleClient(['handler' => $stack, 'http_errors' => true]);

        return new CelesTrakIngester(
            client: new CelesTrakClient($http),
            parser: new TleParser(),
            db:     $this->db,
        );
    }

    private function countRows(string $table): int
    {
        $stmt = $this->db->pdo()->query("SELECT COUNT(*) FROM {$table}");
        if ($stmt === false) {
            return -1;
        }
        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOne(string $sql): array
    {
        $stmt = $this->db->pdo()->query($sql);
        $this->assertNotFalse($stmt, 'Query failed: ' . $sql);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row, 'No row returned: ' . $sql);
        return $row;
    }
}
