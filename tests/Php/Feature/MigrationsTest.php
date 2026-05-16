<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SatTrackr\Database\Connection;
use SatTrackr\Database\Migrator;

final class MigrationsTest extends TestCase
{
    private string $tempDb = '';
    private Connection $connection;
    private Migrator $migrator;

    protected function setUp(): void
    {
        $this->tempDb = tempnam(sys_get_temp_dir(), 'sat-trackr-test-') . '.db';
        if (file_exists($this->tempDb)) {
            unlink($this->tempDb);
        }
        $this->connection = new Connection($this->tempDb);
        $this->migrator = new Migrator(
            connection: $this->connection,
            migrationsDir: dirname(__DIR__, 3) . '/migrations',
        );
    }

    protected function tearDown(): void
    {
        // Force-close connection so we can delete the file
        unset($this->connection);
        foreach ([$this->tempDb, $this->tempDb . '-wal', $this->tempDb . '-shm'] as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }
    }

    public function testMigratorAppliesAllPhaseOneAndTwoMigrations(): void
    {
        $applied = $this->migrator->migrate();

        $this->assertCount(13, $applied);
        $this->assertSame(
            [
                // Phase 1
                '2026_05_14_000001_create_satellites_table',
                '2026_05_14_000002_create_satellites_fts_table',
                '2026_05_14_000003_create_tle_current_table',
                '2026_05_14_000004_create_tle_history_table',
                '2026_05_14_000005_create_satellite_purposes_table',
                '2026_05_14_000006_create_group_membership_table',
                // Phase 2 (chunk 1)
                '2026_05_14_000007_add_launch_site_code_to_satellites_table',
                '2026_05_14_000008_create_launch_sites_table',
                '2026_05_14_000009_create_launches_table',
                '2026_05_14_000010_create_reentries_table',
                '2026_05_14_000011_create_pass_cache_table',
                // Phase 4 (chunks 1 + 3)
                '2026_05_16_000012_create_conjunctions_table',
                '2026_05_16_000013_create_space_weather_samples_table',
            ],
            $applied
        );
    }

    public function testAllExpectedTablesExistAfterMigrate(): void
    {
        $this->migrator->migrate();

        $expected = [
            // Phase 1
            'satellites', 'satellites_fts', 'tle_current', 'tle_history',
            'satellite_purposes', 'group_membership',
            // Phase 2 chunk 1
            'launch_sites', 'launches', 'reentries', 'pass_cache',
            // Phase 4 chunks 1 + 3
            'conjunctions', 'space_weather_samples',
        ];
        foreach ($expected as $table) {
            $count = $this->connection->pdo()
                ->query("SELECT COUNT(*) FROM {$table}")
                ?->fetchColumn();
            $this->assertNotFalse($count, "Table {$table} should exist and be queryable");
        }
    }

    public function testFtsTriggersKeepIndexInSync(): void
    {
        $this->migrator->migrate();
        $pdo = $this->connection->pdo();

        // Insert a satellite — the AFTER INSERT trigger should mirror it into FTS.
        $pdo->exec(<<<'SQL'
            INSERT INTO satellites (norad_id, intl_designator, name, operator, created_at, updated_at)
            VALUES (25544, '1998-067A', 'ISS (ZARYA)', 'NASA/Roscosmos', '2026-05-14T00:00:00Z', '2026-05-14T00:00:00Z')
            SQL);

        // FTS5 MATCH should find it by name fragment
        $stmt = $pdo->prepare("SELECT rowid FROM satellites_fts WHERE satellites_fts MATCH 'zarya'");
        $stmt->execute();
        $rowId = $stmt->fetchColumn();
        $this->assertSame(25544, (int) $rowId);

        // UPDATE should resync
        $pdo->exec("UPDATE satellites SET name = 'ISS (UPDATED)' WHERE norad_id = 25544");
        $stmt = $pdo->prepare("SELECT rowid FROM satellites_fts WHERE satellites_fts MATCH 'updated'");
        $stmt->execute();
        $this->assertSame(25544, (int) $stmt->fetchColumn());

        // DELETE should remove from FTS
        $pdo->exec('DELETE FROM satellites WHERE norad_id = 25544');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM satellites_fts WHERE satellites_fts MATCH 'updated'");
        $stmt->execute();
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testCheckConstraintsRejectInvalidEnumValues(): void
    {
        $this->migrator->migrate();
        $pdo = $this->connection->pdo();

        $this->expectException(\PDOException::class);
        $pdo->exec(<<<'SQL'
            INSERT INTO satellites (norad_id, name, object_type, created_at, updated_at)
            VALUES (1, 'BadType', 'NOT_A_VALID_TYPE', '2026-05-14T00:00:00Z', '2026-05-14T00:00:00Z')
            SQL);
    }

    public function testCascadeDeleteRemovesTleCurrent(): void
    {
        $this->migrator->migrate();
        $pdo = $this->connection->pdo();

        $pdo->exec(<<<'SQL'
            INSERT INTO satellites (norad_id, name, created_at, updated_at)
            VALUES (1, 'Sat A', '2026-05-14T00:00:00Z', '2026-05-14T00:00:00Z')
            SQL);
        $pdo->exec(<<<'SQL'
            INSERT INTO tle_current
              (norad_id, epoch, line1, line2, mean_motion, eccentricity,
               inclination_deg, raan_deg, arg_perigee_deg, mean_anomaly_deg,
               bstar, rev_number, period_min, perigee_km, apogee_km,
               semimajor_km, updated_at)
            VALUES (1, '2026-05-14T00:00:00Z', 'L1', 'L2', 15.5, 0.001,
                    51.6, 12.0, 56.0, 90.0, 0.00001, 47000, 92.7, 415,
                    422, 6790, '2026-05-14T00:00:00Z')
            SQL);

        $pdo->exec('DELETE FROM satellites WHERE norad_id = 1');

        $count = $pdo->query('SELECT COUNT(*) FROM tle_current WHERE norad_id = 1')?->fetchColumn();
        $this->assertSame(0, (int) $count, 'tle_current row should cascade-delete with its parent satellite');
    }

    public function testRollbackReversesEverything(): void
    {
        $this->migrator->migrate();
        $rolled = $this->migrator->rollback();
        $this->assertCount(13, $rolled);

        // All app tables should be gone (the migrations table itself stays).
        $stmt = $this->connection->pdo()
            ->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('satellites','tle_current','tle_history','satellite_purposes','group_membership','launches','launch_sites','reentries','pass_cache','conjunctions','space_weather_samples')");
        $this->assertNotFalse($stmt);
        $remaining = $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);
        $this->assertSame([], $remaining);
    }

    public function testMigrateIsIdempotentWhenNothingPending(): void
    {
        $this->migrator->migrate();
        $second = $this->migrator->migrate();
        $this->assertSame([], $second, 'A second migrate() with nothing pending should apply nothing');
    }
}
