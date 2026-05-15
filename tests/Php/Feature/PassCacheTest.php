<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SatTrackr\Database\Connection;
use SatTrackr\Database\Migrator;
use SatTrackr\Services\PassCache;

final class PassCacheTest extends TestCase
{
    private string $tempDb = '';
    private Connection $db;

    protected function setUp(): void
    {
        $this->tempDb = tempnam(sys_get_temp_dir(), 'pass-cache-') . '.db';
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

    public function testKeyRoundsCoordinatesToThreeDecimals(): void
    {
        // 51.50723 and 51.50711 must collapse to the same key (~10m apart).
        $this->assertSame(
            PassCache::key(25544, 51.50723, -0.12762, '2026-05-15'),
            PassCache::key(25544, 51.50711, -0.12760, '2026-05-15'),
        );
        // But 51.508 → different key
        $this->assertNotSame(
            PassCache::key(25544, 51.50723, -0.12762, '2026-05-15'),
            PassCache::key(25544, 51.50800, -0.12762, '2026-05-15'),
        );
    }

    public function testGetMissReturnsNull(): void
    {
        $cache = new PassCache($this->db);
        $this->assertNull($cache->get(25544, 51.5, -0.1, '2026-05-15'));
    }

    public function testPutAndGetRoundTrip(): void
    {
        $cache = new PassCache($this->db);
        $payload = ['count' => 2, 'passes' => [['rise_at' => 'A'], ['rise_at' => 'B']]];
        $cache->put(25544, 51.50723, -0.12762, 30.0, '2026-05-15', $payload);

        $back = $cache->get(25544, 51.50711, -0.12760, '2026-05-15');     // sub-3dp jitter still hits
        $this->assertSame($payload, $back);
    }

    public function testReWritePreservesKeyButRefreshesTtl(): void
    {
        $cache = new PassCache($this->db);
        $cache->put(25544, 51.5, -0.1, 0.0, '2026-05-15', ['v' => 1]);
        $cache->put(25544, 51.5, -0.1, 0.0, '2026-05-15', ['v' => 2]);

        $rows = $this->db->pdo()->query('SELECT COUNT(*) FROM pass_cache')->fetchColumn();
        $this->assertSame(1, (int) $rows);
        $this->assertSame(['v' => 2], $cache->get(25544, 51.5, -0.1, '2026-05-15'));
    }

    public function testExpiredEntryIsNotReturnedAndPrunes(): void
    {
        $cache = new PassCache($this->db);
        $cache->put(25544, 51.5, -0.1, 0.0, '2026-05-15', ['v' => 1]);

        // Manually backdate expires_at past now.
        $this->db->pdo()->exec("UPDATE pass_cache SET expires_at = '2020-01-01T00:00:00Z'");

        $this->assertNull($cache->get(25544, 51.5, -0.1, '2026-05-15'));

        $deleted = $cache->prune();
        $this->assertSame(1, $deleted);
        $this->assertSame(0, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM pass_cache')->fetchColumn());
    }

    public function testPruneLeavesUnexpiredRowsAlone(): void
    {
        $cache = new PassCache($this->db);
        $cache->put(25544, 51.5, -0.1, 0.0, '2026-05-15', ['v' => 1]);
        $cache->put(44713, 38.9, -77.0, 0.0, '2026-05-15', ['v' => 2]);

        $this->db->pdo()->exec("UPDATE pass_cache SET expires_at = '2020-01-01T00:00:00Z' WHERE norad_id = 25544");

        $deleted = $cache->prune();
        $this->assertSame(1, $deleted);
        $this->assertSame(1, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM pass_cache')->fetchColumn());
    }
}
