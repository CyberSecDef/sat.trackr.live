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
use SatTrackr\Ingest\SatCatClient;
use SatTrackr\Ingest\SatCatIngester;

final class SatCatIngesterTest extends TestCase
{
    private string $tempDb = '';
    private Connection $db;

    private const ISS_SATCAT = '[{"OBJECT_NAME":"ISS (ZARYA)","OBJECT_ID":"1998-067A","NORAD_CAT_ID":25544,"OBJECT_TYPE":"PAY","OPS_STATUS_CODE":"+","OWNER":"ISS","LAUNCH_DATE":"1998-11-20","LAUNCH_SITE":"TYMSC","DECAY_DATE":"","PERIOD":92.95,"INCLINATION":51.63,"APOGEE":424,"PERIGEE":414,"RCS":399.0524,"DATA_STATUS_CODE":"","ORBIT_CENTER":"EA","ORBIT_TYPE":"ORB"}]';

    private const STARLINK_SATCAT = '[{"OBJECT_NAME":"STARLINK-1007","OBJECT_ID":"2019-074A","NORAD_CAT_ID":44713,"OBJECT_TYPE":"PAY","OPS_STATUS_CODE":"+","OWNER":"US","LAUNCH_DATE":"2019-11-11","LAUNCH_SITE":"AFETR","DECAY_DATE":"","PERIOD":92.5,"INCLINATION":53.0,"APOGEE":555,"PERIGEE":540,"RCS":2.1,"DATA_STATUS_CODE":"","ORBIT_CENTER":"EA","ORBIT_TYPE":"ORB"}]';

    private const DECAYED_SATCAT = '[{"OBJECT_NAME":"OLD SAT","OBJECT_ID":"1970-001A","NORAD_CAT_ID":4711,"OBJECT_TYPE":"PAY","OPS_STATUS_CODE":"D","OWNER":"US","LAUNCH_DATE":"1970-01-15","LAUNCH_SITE":"AFETR","DECAY_DATE":"1985-06-12","PERIOD":0,"INCLINATION":0,"APOGEE":0,"PERIGEE":0,"RCS":0,"DATA_STATUS_CODE":"","ORBIT_CENTER":"EA","ORBIT_TYPE":"ORB"}]';

    protected function setUp(): void
    {
        $this->tempDb = tempnam(sys_get_temp_dir(), 'satcat-test-') . '.db';
        if (file_exists($this->tempDb)) {
            unlink($this->tempDb);
        }
        $this->db = new Connection($this->tempDb);
        (new Migrator($this->db, dirname(__DIR__, 3) . '/migrations'))->migrate();

        // Seed catalog with the rows SATCAT will enrich. Mirrors what the
        // CelesTrak GP ingester would have created.
        $this->db->pdo()->exec("INSERT INTO satellites (norad_id, intl_designator, name, created_at, updated_at)
            VALUES
              (25544, '1998-067A', 'ISS (ZARYA)',  '2026-05-14T00:00:00Z', '2026-05-14T00:00:00Z'),
              (44713, '2019-074A', 'STARLINK-1007', '2026-05-14T00:00:00Z', '2026-05-14T00:00:00Z'),
              (4711,  '1970-001A', 'OLD SAT',       '2026-05-14T00:00:00Z', '2026-05-14T00:00:00Z')");

        // Group memberships drive purposes derivation.
        $this->db->pdo()->exec("INSERT INTO group_membership (norad_id, group_slug, last_seen_at)
            VALUES
              (25544, 'stations', '2026-05-14T00:00:00Z'),
              (25544, 'active',   '2026-05-14T00:00:00Z'),
              (44713, 'starlink', '2026-05-14T00:00:00Z'),
              (44713, 'active',   '2026-05-14T00:00:00Z')");
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

    public function testEnrichesSatelliteWithSatcatFields(): void
    {
        $ingester = $this->makeIngester([new Response(200, [], self::ISS_SATCAT)]);

        $report = $ingester->run(['stations']);

        $this->assertSame(1, $report->groupsProcessed);
        $this->assertSame(1, $report->recordsSeen);
        $this->assertSame(1, $report->satellitesUpdated);
        $this->assertSame(0, $report->satellitesUnknown);

        $iss = $this->db->pdo()->query('SELECT * FROM satellites WHERE norad_id = 25544')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('PAYLOAD', $iss['object_type']);
        $this->assertSame('ACTIVE',  $iss['status']);
        $this->assertSame('ISS',     $iss['country']);
        $this->assertSame('1998-11-20', $iss['launch_date']);
        $this->assertSame('TYMSC',   $iss['launch_site_code']);
        $this->assertNull($iss['decayed_at']);
        $this->assertEqualsWithDelta(399.0524, (float) $iss['rcs_meters'], 1e-3);

        // Name + intl_designator preserved (NEVER overwritten by SATCAT).
        $this->assertSame('ISS (ZARYA)', $iss['name']);
        $this->assertSame('1998-067A',   $iss['intl_designator']);
    }

    public function testDecayedSatellitePopulatesDecayedAtAndStatus(): void
    {
        $ingester = $this->makeIngester([new Response(200, [], self::DECAYED_SATCAT)]);
        $report = $ingester->run(['stations']);

        $this->assertSame(1, $report->satellitesUpdated);
        $row = $this->db->pdo()->query('SELECT status, decayed_at, rcs_meters FROM satellites WHERE norad_id = 4711')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('DECAYED', $row['status']);
        $this->assertSame('1985-06-12', $row['decayed_at']);
        $this->assertNull($row['rcs_meters'], 'SATCAT RCS=0 should normalize to NULL');
    }

    public function testUnknownNoradIdSkippedAndCounted(): void
    {
        $orphan = '[{"OBJECT_NAME":"GHOST","OBJECT_ID":"2099-001A","NORAD_CAT_ID":99999,"OBJECT_TYPE":"PAY","OPS_STATUS_CODE":"+","OWNER":"US","LAUNCH_DATE":"2099-01-01","LAUNCH_SITE":"KSC","DECAY_DATE":"","RCS":1.0}]';
        $ingester = $this->makeIngester([new Response(200, [], $orphan)]);
        $report = $ingester->run(['stations']);

        $this->assertSame(1, $report->recordsSeen);
        $this->assertSame(0, $report->satellitesUpdated);
        $this->assertSame(1, $report->satellitesUnknown);
    }

    public function testPurposesDerivedFromGroupMembershipAfterIngest(): void
    {
        $ingester = $this->makeIngester([
            new Response(200, [], self::ISS_SATCAT),
            new Response(200, [], self::STARLINK_SATCAT),
        ]);

        $report = $ingester->run(['stations', 'starlink']);

        $this->assertSame(2, $report->satellitesUpdated);
        $this->assertGreaterThanOrEqual(2, $report->purposesDerived); // station + comms

        // ISS is in 'stations' (→ station) + 'active' (→ no purpose); should have 'station'.
        $iss = $this->db->pdo()->query("SELECT purpose FROM satellite_purposes WHERE norad_id = 25544")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('station', $iss);

        // STARLINK in 'starlink' (→ comms) + 'active' (→ none); should have 'comms'.
        $starlink = $this->db->pdo()->query("SELECT purpose FROM satellite_purposes WHERE norad_id = 44713")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('comms', $starlink);
    }

    public function testPurposesAreRebuiltIdempotently(): void
    {
        $ingester = $this->makeIngester([
            new Response(200, [], self::ISS_SATCAT),
            new Response(200, [], self::ISS_SATCAT),
        ]);

        $first = $ingester->run(['stations']);
        $second = $ingester->run(['stations']);

        $this->assertSame($first->purposesDerived, $second->purposesDerived,
            'Rebuilt satellite_purposes count should be stable across re-runs');

        $countAfterTwo = (int) $this->db->pdo()->query('SELECT COUNT(*) FROM satellite_purposes')->fetchColumn();
        $this->assertSame($first->purposesDerived, $countAfterTwo);
    }

    public function testGroupFetch500LoggedAndOtherGroupsContinue(): void
    {
        $ingester = $this->makeIngester([
            new Response(500, [], 'oops'),
            new Response(200, [], self::ISS_SATCAT),
        ]);

        $report = $ingester->run(['will-fail', 'stations']);

        $this->assertSame(1, $report->groupsProcessed);
        $this->assertCount(1, $report->errors);
        $this->assertSame('will-fail', $report->errors[0]['group']);
    }

    /**
     * @param list<Response> $responses
     */
    private function makeIngester(array $responses): SatCatIngester
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $http = new GuzzleClient(['handler' => $stack, 'http_errors' => true]);

        return new SatCatIngester(
            client: new SatCatClient($http),
            db:     $this->db,
        );
    }
}
