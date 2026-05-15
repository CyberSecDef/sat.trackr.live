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
use SatTrackr\Ingest\LaunchLibraryClient;
use SatTrackr\Ingest\LaunchLibraryIngester;

final class LaunchLibraryIngesterTest extends TestCase
{
    private string $tempDb = '';
    private Connection $db;

    private const UPCOMING_RESPONSE = <<<'JSON'
        {
          "count": 1,
          "next": null,
          "previous": null,
          "results": [
            {
              "id": "abcd1234-aaaa-bbbb-cccc-deadbeef0001",
              "url": "https://ll.thespacedevs.com/2.2.0/launch/abcd1234/",
              "name": "Falcon 9 | Starlink 6-72",
              "status": {"id": 1, "name": "Go for Launch", "abbrev": "Go", "description": "..."},
              "net": "2026-05-20T03:14:00Z",
              "launch_service_provider": {"id": 121, "name": "SpaceX", "type": "Commercial"},
              "rocket": {"configuration": {"name": "Falcon 9", "family": "Falcon"}},
              "mission": {
                "id": 999,
                "name": "Starlink 6-72",
                "description": "A batch of 23 Starlink satellites for SpaceX's broadband constellation.",
                "type": "Communications",
                "orbit": {"name": "Low Earth Orbit", "abbrev": "LEO"},
                "agencies": [{"id": 121, "name": "SpaceX"}],
                "vid_urls": [{"url": "https://www.youtube.com/watch?v=foo"}]
              },
              "pad": {
                "id": 80,
                "url": "https://ll.thespacedevs.com/2.2.0/pad/80/",
                "name": "Space Launch Complex 40",
                "latitude": "28.5618",
                "longitude": "-80.5772",
                "location": {
                  "id": 12,
                  "name": "Cape Canaveral SFS, FL, USA",
                  "country_code": "USA",
                  "description": "Cape Canaveral Space Force Station"
                }
              },
              "image": "https://thespacedevs-prod.example/falcon9.jpg",
              "vidURLs": null
            }
          ]
        }
        JSON;

    private const PREVIOUS_RESPONSE = <<<'JSON'
        {
          "count": 1,
          "next": null,
          "previous": null,
          "results": [
            {
              "id": "ffff0000-1111-2222-3333-deadbeef0002",
              "name": "Atlas V 401 | USSF-95",
              "status": {"id": 3, "name": "Launch Successful", "abbrev": "Success"},
              "net": "2026-04-15T10:00:00Z",
              "launch_service_provider": {"id": 124, "name": "United Launch Alliance"},
              "rocket": {"configuration": {"name": "Atlas V 401"}},
              "mission": {
                "name": "USSF-95",
                "description": "Classified payload for the US Space Force.",
                "type": "Government / Top Secret",
                "orbit": {"name": "Geosynchronous Transfer Orbit"},
                "agencies": [{"id": 999, "name": "US Space Force"}]
              },
              "pad": {
                "id": 80,
                "name": "Space Launch Complex 40",
                "latitude": "28.5618",
                "longitude": "-80.5772",
                "location": {"name": "Cape Canaveral SFS, FL, USA", "country_code": "USA"}
              },
              "image": null
            }
          ]
        }
        JSON;

    protected function setUp(): void
    {
        $this->tempDb = tempnam(sys_get_temp_dir(), 'll2-test-') . '.db';
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

    public function testIngestsUpcomingLaunchAndPad(): void
    {
        $ingester = $this->makeIngester([
            new Response(200, [], self::UPCOMING_RESPONSE),
        ]);

        $report = $ingester->run('upcoming');

        $this->assertSame(1, $report->upcomingFetched);
        $this->assertSame(1, $report->launchesUpserted);
        $this->assertSame(1, $report->padsUpserted);
        $this->assertSame(0, $report->launchesRejected);

        $launch = $this->db->pdo()
            ->query("SELECT * FROM launches WHERE id = 'abcd1234-aaaa-bbbb-cccc-deadbeef0001'")
            ->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('Falcon 9 | Starlink 6-72', $launch['name']);
        $this->assertSame('GO', $launch['status']);
        $this->assertSame('SpaceX', $launch['provider']);
        $this->assertSame('Falcon 9', $launch['vehicle']);
        $this->assertSame(80, (int) $launch['pad_id']);
        $this->assertSame('Starlink 6-72', $launch['mission_name']);
        $this->assertSame('Communications', $launch['mission_type']);
        $this->assertSame('Low Earth Orbit', $launch['orbit_target']);
        $this->assertSame('SpaceX', $launch['customer']);
        $this->assertSame('https://www.youtube.com/watch?v=foo', $launch['webcast_url']);
        $this->assertSame('https://thespacedevs-prod.example/falcon9.jpg', $launch['image_url']);

        $pad = $this->db->pdo()->query("SELECT * FROM launch_sites WHERE id = 80")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('Space Launch Complex 40', $pad['name']);
        $this->assertEqualsWithDelta(28.5618, (float) $pad['latitude'], 1e-4);
        $this->assertEqualsWithDelta(-80.5772, (float) $pad['longitude'], 1e-4);
        $this->assertSame('USA', $pad['country']);
    }

    public function testIngestsBothModesAndDeduplicatesPads(): void
    {
        $ingester = $this->makeIngester([
            new Response(200, [], self::UPCOMING_RESPONSE),
            new Response(200, [], self::PREVIOUS_RESPONSE),
        ]);

        $report = $ingester->run('both');

        $this->assertSame(1, $report->upcomingFetched);
        $this->assertSame(1, $report->previousFetched);
        $this->assertSame(2, $report->launchesUpserted);
        $this->assertSame(2, $report->padsUpserted);  // counts upsert ops, not distinct rows

        // Both launches share pad_id=80 → only 1 distinct pad row
        $padCount = (int) $this->db->pdo()->query('SELECT COUNT(*) FROM launch_sites')->fetchColumn();
        $this->assertSame(1, $padCount);

        $launchCount = (int) $this->db->pdo()->query('SELECT COUNT(*) FROM launches')->fetchColumn();
        $this->assertSame(2, $launchCount);
    }

    public function testReingestIsIdempotent(): void
    {
        $ingester = $this->makeIngester([
            new Response(200, [], self::UPCOMING_RESPONSE),
            new Response(200, [], self::UPCOMING_RESPONSE),
        ]);

        $ingester->run('upcoming');
        $second = $ingester->run('upcoming');

        $this->assertSame(1, $second->launchesUpserted, 'Re-ingesting same upcoming list should still upsert (no-op DB-side)');
        $this->assertSame(1, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM launches')->fetchColumn());
        $this->assertSame(1, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM launch_sites')->fetchColumn());
    }

    public function testFetchFailureRecordedAsError(): void
    {
        $ingester = $this->makeIngester([
            new Response(500, [], 'Internal Server Error'),
        ]);

        $report = $ingester->run('upcoming');

        $this->assertSame(0, $report->launchesUpserted);
        $this->assertCount(1, $report->errors);
        $this->assertSame('upcoming', $report->errors[0]['mode']);
    }

    public function testStatusMappingCoversCommonValues(): void
    {
        $tbdResponse = str_replace('"abbrev": "Go"', '"abbrev": "TBD"', self::UPCOMING_RESPONSE);
        $ingester = $this->makeIngester([new Response(200, [], $tbdResponse)]);
        $ingester->run('upcoming');

        $status = $this->db->pdo()
            ->query("SELECT status FROM launches WHERE id = 'abcd1234-aaaa-bbbb-cccc-deadbeef0001'")
            ->fetchColumn();
        $this->assertSame('TBD', $status);
    }

    /**
     * @param list<Response> $responses
     */
    private function makeIngester(array $responses): LaunchLibraryIngester
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $http = new GuzzleClient(['handler' => $stack, 'http_errors' => true]);

        return new LaunchLibraryIngester(
            client: new LaunchLibraryClient($http),
            db:     $this->db,
        );
    }
}
