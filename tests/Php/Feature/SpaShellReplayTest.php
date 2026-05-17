<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SatTrackr\Database\Connection;
use SatTrackr\Database\Migrator;
use SatTrackr\Http\Controllers\SpaShellController;
use SatTrackr\Services\ViteAssetResolver;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Phase 6 chunk 1 — /conjunction/{primary}/{secondary} SSR contract.
 *
 *   - Latest TCA for the pair is embedded as #sat-replay-context JSON.
 *   - replay-mode="conjunction" attribute appears on <sat-app>.
 *   - Order-insensitive: /N/M and /M/N return the same row.
 *   - Unknown pair degrades gracefully (no replay context, no error).
 */
final class SpaShellReplayTest extends TestCase
{
    private string $tempDb = '';
    private Connection $db;

    protected function setUp(): void
    {
        $this->tempDb = tempnam(sys_get_temp_dir(), 'replay-') . '.db';
        if (file_exists($this->tempDb)) unlink($this->tempDb);
        $this->db = new Connection($this->tempDb);
        (new Migrator($this->db, dirname(__DIR__, 3) . '/migrations'))->migrate();

        // Seed two conjunctions for the same pair so we can verify the
        // controller picks the soonest TCA.
        $now = '2026-05-16T00:00:00Z';
        $pdo = $this->db->pdo();
        $pdo->exec("INSERT INTO conjunctions
            (norad_id_primary, name_primary, norad_id_secondary, name_secondary,
             tca, tca_range_km, tca_relative_speed_km_s, max_probability,
             source, created_at, updated_at)
            VALUES
              (48667, 'STARLINK-2735', 58934, 'STARLINK-31337',
               '2026-05-20T12:00:00Z', 3.5, 12.1, 1e-5, 'CELESTRAK_SOCRATES', '{$now}', '{$now}'),
              (48667, 'STARLINK-2735', 58934, 'STARLINK-31337',
               '2026-05-19T09:00:00Z', 0.5, 14.2, 5e-4, 'CELESTRAK_SOCRATES', '{$now}', '{$now}')");
    }

    protected function tearDown(): void
    {
        unset($this->db);
        foreach ([$this->tempDb, $this->tempDb . '-wal', $this->tempDb . '-shm'] as $f) {
            if (file_exists($f)) @unlink($f);
        }
    }

    private function controller(): SpaShellController
    {
        return new SpaShellController(
            vite: new ViteAssetResolver(dirname(__DIR__, 3), 'http://localhost:5173'),
            rootDir: dirname(__DIR__, 3),
            appName: 'sat.trackr.live',
            appUrl: 'https://sat.trackr.live',
            cesiumIonToken: '',
            db: $this->db,
        );
    }

    /** @param array<string, string> $args */
    private function render(array $args): string
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/conjunction/' . implode('/', $args));
        $res = ($this->controller())($req, new Response(), $args);
        return (string) $res->getBody();
    }

    public function testReplayContextEmbeddedForKnownPair(): void
    {
        $html = $this->render(['primary' => '48667', 'secondary' => '58934']);
        $this->assertStringContainsString('id="sat-replay-context"', $html);
        $this->assertStringContainsString('replay-mode="conjunction"', $html);
        // Soonest TCA wins (2026-05-19, not 2026-05-20):
        $this->assertStringContainsString('"tca":"2026-05-19T09:00:00Z"', $html);
        $this->assertStringContainsString('"primary":48667', $html);
        $this->assertStringContainsString('"secondary":58934', $html);
        $this->assertStringContainsString('"miss_km":0.5', $html);
    }

    public function testPairIsOrderInsensitive(): void
    {
        $forward  = $this->render(['primary' => '48667', 'secondary' => '58934']);
        $reversed = $this->render(['primary' => '58934', 'secondary' => '48667']);
        $this->assertStringContainsString('id="sat-replay-context"', $reversed);
        // Both routes resolve to the same soonest TCA.
        $this->assertStringContainsString('"tca":"2026-05-19T09:00:00Z"', $forward);
        $this->assertStringContainsString('"tca":"2026-05-19T09:00:00Z"', $reversed);
    }

    public function testUnknownPairDegradesGracefullyWithoutReplayContext(): void
    {
        $html = $this->render(['primary' => '99999', 'secondary' => '88888']);
        $this->assertStringNotContainsString('id="sat-replay-context"', $html);
        $this->assertStringNotContainsString('replay-mode="conjunction"', $html);
        // Shell still renders normally.
        $this->assertStringContainsString('<sat-app', $html);
    }
}
