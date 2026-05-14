<?php

declare(strict_types=1);

use SatTrackr\Database\Connection;
use SatTrackr\Database\Migration;

/**
 * Phase 2 chunk 1: SATCAT exposes a LAUNCH_SITE field as a 5-char text code
 * (e.g. "TYMSC" = Tyuratam, "KSC" = Kennedy). Our existing satellites.launch_site_id
 * column is INTEGER (reserved for LL2's numeric pad ID, populated in chunk 3).
 * The two systems don't align, so add a separate column for the SATCAT code.
 */
return new class extends Migration {
    public function up(Connection $connection): void
    {
        $connection->pdo()->exec(
            'ALTER TABLE satellites ADD COLUMN launch_site_code TEXT'
        );
    }

    public function down(Connection $connection): void
    {
        // SQLite supports DROP COLUMN since 3.35.
        $connection->pdo()->exec(
            'ALTER TABLE satellites DROP COLUMN launch_site_code'
        );
    }
};
