<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SatTrackr\Database\Connection;
use SatTrackr\Database\Migrator;
use SatTrackr\Services\EventsAggregator;

final class EventsAggregatorTest extends TestCase
{
    private string $tempDb = '';
    private Connection $db;

    protected function setUp(): void
    {
        $this->tempDb = tempnam(sys_get_temp_dir(), 'events-') . '.db';
        if (file_exists($this->tempDb)) {
            unlink($this->tempDb);
        }
        $this->db = new Connection($this->tempDb);
        (new Migrator($this->db, dirname(__DIR__, 3) . '/migrations'))->migrate();

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
        $tca = static fn (string $offset): string =>
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify($offset)->format('Y-m-d\TH:i:s\Z');

        $pdo = $this->db->pdo();
        $pdo->exec("INSERT INTO satellites (norad_id, intl_designator, name, created_at, updated_at)
                    VALUES (25544, '1998-067A', 'ISS (ZARYA)', '{$now}', '{$now}'),
                           (44713, '2019-074A', 'STARLINK-1007', '{$now}', '{$now}')");

        $pdo->exec("INSERT INTO launch_sites (id, name, latitude, longitude, country, operator, url, updated_at)
                    VALUES (80, 'SLC-40', 28.5618, -80.5772, 'USA', 'KSC', NULL, '{$now}')");

        // One launch in window (+3d), one outside (+30d).
        $pdo->exec("INSERT INTO launches
                    (id, name, net, status, provider, vehicle, pad_id, mission_name, mission_type,
                     orbit_target, customer, webcast_url, image_url, description, associated_norad_ids, updated_at)
                    VALUES
                      ('uuid-soon',  'Falcon 9 | Future Mission', '{$tca('+3 days')}',  'GO', 'SpaceX', 'Falcon 9', 80, 'Future Mission', 'Communications', 'LEO', 'SpaceX', NULL, NULL, NULL, '[]', '{$now}'),
                      ('uuid-later', 'Falcon 9 | Far Away',       '{$tca('+30 days')}', 'TBD','SpaceX', 'Falcon 9', 80, 'Far Away',       'Communications', 'LEO', 'SpaceX', NULL, NULL, NULL, '[]', '{$now}')");

        // One reentry in window (+5d), one outside (+20d).
        $pdo->exec("INSERT INTO reentries
                    (norad_id, predicted_decay, confidence_window_hours, source, risk_score, raw_message, created_at, updated_at)
                    VALUES
                      (25544, '{$tca('+5 days')}',  6.0,  'SPACE_TRACK_TIP', NULL, NULL, '{$now}', '{$now}'),
                      (44713, '{$tca('+20 days')}', 12.0, 'SPACE_TRACK_TIP', NULL, NULL, '{$now}', '{$now}')");

        // Conjunctions: one strong (p=0.05), one weak (p=1e-6 — below MIN), one outside window.
        $pdo->exec("INSERT INTO conjunctions
                    (norad_id_primary, name_primary, dse_primary,
                     norad_id_secondary, name_secondary, dse_secondary,
                     tca, tca_range_km, tca_relative_speed_km_s, max_probability, dilution, created_at, updated_at)
                    VALUES
                      (25544, 'ISS [+]',  1.0, 44713, 'STARLINK [+]', 1.0, '{$tca('+2 days')}',  0.080, 11.3, 0.0500, 0.05, '{$now}', '{$now}'),
                      (25544, 'ISS [+]',  1.0, 44713, 'STARLINK [+]', 1.0, '{$tca('+4 days')}',  1.500, 11.3, 1.0E-6, 0.05, '{$now}', '{$now}'),
                      (25544, 'ISS [+]',  1.0, 44713, 'STARLINK [+]', 1.0, '{$tca('+30 days')}', 0.080, 11.3, 0.2500, 0.05, '{$now}', '{$now}')");

        // Storms: one noteworthy (G2), one not (G0/S0/R0/C-class).
        $pdo->exec("INSERT INTO space_weather_samples
                    (sampled_at, kp, x_ray_flux, x_ray_class, r_level, s_level, g_level, raw_message, created_at)
                    VALUES
                      ('{$tca('-1 days')}', 5.33, 2.5E-5, 'M', 1, 0, 2, NULL, '{$now}'),
                      ('{$tca('-2 days')}', 1.67, 3.0E-6, 'C', 1, 0, 0, NULL, '{$now}')");
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

    public function testRecentMergesAllSourcesInWindowAndSortsAscending(): void
    {
        $events = (new EventsAggregator($this->db))->recent(7, 7);

        $kinds = array_count_values(array_column($events, 'kind'));
        $this->assertSame(1, $kinds['launch']     ?? 0);
        $this->assertSame(1, $kinds['reentry']    ?? 0);
        $this->assertSame(1, $kinds['conjunction']?? 0, '+2d 0.05-prob conjunction should be in; weak one + far one filtered');
        $this->assertSame(1, $kinds['storm']      ?? 0);
        $this->assertCount(4, $events);

        // Sort: ascending by timestamp
        $timestamps = array_column($events, 'timestamp');
        $sorted = $timestamps;
        sort($sorted);
        $this->assertSame($sorted, $timestamps);
    }

    public function testNarrowWindowExcludesFarEvents(): void
    {
        // 1-day past, 1-day future — drops the +5d reentry, +2d conjunction, +3d launch.
        $events = (new EventsAggregator($this->db))->recent(1, 1);
        $kinds = array_count_values(array_column($events, 'kind'));
        $this->assertSame(0, $kinds['launch']     ?? 0);
        $this->assertSame(0, $kinds['reentry']    ?? 0);
        $this->assertSame(0, $kinds['conjunction']?? 0);
        // Storm at -1d still in.
        $this->assertSame(1, $kinds['storm']      ?? 0);
    }

    public function testConjunctionProbThresholdFiltersWeakRows(): void
    {
        // 35d window picks up all three seeded conjunctions before
        // probability filtering.  MIN_CONJUNCTION_PROB drops the
        // +4d/1e-6 row; the +2d/0.05 and +30d/0.25 rows remain.
        $events = (new EventsAggregator($this->db))->recent(7, 35);
        $conjunctions = array_filter($events, static fn ($e) => $e['kind'] === 'conjunction');
        $this->assertCount(2, $conjunctions);
    }

    public function testConjunctionLinkPointsAtReplayRoute(): void
    {
        // Phase 6 chunk 3 — Atom + /text/events entries link to the SPA
        // replay scene, not the JSON API. URL shape is /conjunction/{p}/{s}.
        $events = (new EventsAggregator($this->db))->recent(7, 35);
        $conjunctions = array_values(array_filter($events, static fn ($e) => $e['kind'] === 'conjunction'));
        $this->assertNotEmpty($conjunctions);
        foreach ($conjunctions as $e) {
            $this->assertMatchesRegularExpression(
                '#^/conjunction/\d+/\d+$#',
                (string) $e['link'],
                "conjunction event link should be /conjunction/{p}/{s}, got {$e['link']}",
            );
        }
    }

    public function testEventIdsArePrefixedByKind(): void
    {
        $events = (new EventsAggregator($this->db))->recent(7, 7);
        foreach ($events as $e) {
            $this->assertStringStartsWith($e['kind'] . ':', $e['id']);
        }
    }

    public function testStormHeadlineNamesTheActiveScales(): void
    {
        $events = (new EventsAggregator($this->db))->recent(7, 0);
        $storm = array_values(array_filter($events, static fn ($e) => $e['kind'] === 'storm'))[0];
        // G2 + M-class flare both qualify; both should appear in the title.
        $this->assertStringContainsString('G2', $storm['title']);
        $this->assertStringContainsString('M-class', $storm['title']);
    }

    public function testReentryTitleIncludesSatelliteName(): void
    {
        $events = (new EventsAggregator($this->db))->recent(0, 7);
        $reentry = array_values(array_filter($events, static fn ($e) => $e['kind'] === 'reentry'))[0];
        $this->assertStringContainsString('ISS (ZARYA)', $reentry['title']);
        $this->assertSame('/text/satellite/25544', $reentry['link']);
    }
}
