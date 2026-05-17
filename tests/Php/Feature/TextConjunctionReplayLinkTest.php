<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SatTrackr\Database\Connection;
use SatTrackr\Database\Migrator;
use SatTrackr\Http\Controllers\Text\TextConjunctionListController;
use SatTrackr\Services\TextRenderer;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Phase 6 chunk 3A — every conjunction row in /text/conjunctions
 * carries a /conjunction/{primary}/{secondary} link so users can jump
 * into the 3D replay.  Empty rows render the existing empty-state with
 * no replay table at all.
 */
final class TextConjunctionReplayLinkTest extends TestCase
{
    private string $tempDb = '';
    private Connection $db;

    protected function setUp(): void
    {
        $this->tempDb = tempnam(sys_get_temp_dir(), 'replay-link-') . '.db';
        if (file_exists($this->tempDb)) unlink($this->tempDb);
        $this->db = new Connection($this->tempDb);
        (new Migrator($this->db, dirname(__DIR__, 3) . '/migrations'))->migrate();
    }

    protected function tearDown(): void
    {
        unset($this->db);
        foreach ([$this->tempDb, $this->tempDb . '-wal', $this->tempDb . '-shm'] as $f) {
            if (file_exists($f)) @unlink($f);
        }
    }

    private function render(): string
    {
        $controller = new TextConjunctionListController(
            $this->db,
            new TextRenderer(dirname(__DIR__, 3), 'https://sat.trackr.live'),
        );
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/text/conjunctions?within_hours=720');
        $res = $controller($req, new Response(), []);
        return (string) $res->getBody();
    }

    public function testEmptyTableHasNoReplayLinks(): void
    {
        $html = $this->render();
        $this->assertStringNotContainsString('class="replay-link"', $html);
        $this->assertStringContainsString('No conjunctions match', $html);
    }

    public function testReplayLinkPerRowPointsAtBothNorads(): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $tca = $now->modify('+3 hours')->format('Y-m-d\TH:i:s\Z');
        $nowIso = $now->format('Y-m-d\TH:i:s\Z');
        // Seed a couple of satellites + a conjunction between them.
        $this->db->pdo()->exec("INSERT INTO satellites (norad_id, intl_designator, name, created_at, updated_at)
            VALUES (25544, '1998-067A', 'ISS (ZARYA)', '{$nowIso}', '{$nowIso}'),
                   (44713, '2019-074A', 'STARLINK-1007', '{$nowIso}', '{$nowIso}')");
        $this->db->pdo()->exec("INSERT INTO conjunctions
            (norad_id_primary, name_primary, norad_id_secondary, name_secondary,
             tca, tca_range_km, tca_relative_speed_km_s, max_probability,
             source, created_at, updated_at)
            VALUES (25544, 'ISS (ZARYA)', 44713, 'STARLINK-1007',
                    '{$tca}', 0.42, 12.1, 5e-4, 'CELESTRAK_SOCRATES',
                    '{$nowIso}', '{$nowIso}')");

        $html = $this->render();
        $this->assertStringContainsString('class="replay-link"', $html);
        $this->assertStringContainsString('href="/conjunction/25544/44713"', $html);
        // The replay column header is present too.
        $this->assertStringContainsString('<th>Replay</th>', $html);
    }
}
