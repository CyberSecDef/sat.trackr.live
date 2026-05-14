<?php

declare(strict_types=1);

use SatTrackr\Database\Connection;
use SatTrackr\Database\Migration;

return new class extends Migration {
    public function up(Connection $connection): void
    {
        $pdo = $connection->pdo();

        $pdo->exec(<<<'SQL'
            CREATE TABLE tle_current (
              norad_id          INTEGER PRIMARY KEY REFERENCES satellites(norad_id) ON DELETE CASCADE,
              epoch             TEXT NOT NULL,
              line1             TEXT NOT NULL,
              line2             TEXT NOT NULL,
              mean_motion       REAL NOT NULL,
              eccentricity      REAL NOT NULL,
              inclination_deg   REAL NOT NULL,
              raan_deg          REAL NOT NULL,
              arg_perigee_deg   REAL NOT NULL,
              mean_anomaly_deg  REAL NOT NULL,
              bstar             REAL NOT NULL,
              rev_number        INTEGER NOT NULL,
              period_min        REAL NOT NULL,
              perigee_km        REAL NOT NULL,
              apogee_km         REAL NOT NULL,
              semimajor_km      REAL NOT NULL,
              source            TEXT NOT NULL DEFAULT 'CELESTRAK'
                                CHECK (source IN ('CELESTRAK','SPACE_TRACK')),
              updated_at        TEXT NOT NULL
            )
            SQL);

        $pdo->exec('CREATE INDEX idx_tle_current_epoch ON tle_current(epoch)');
    }

    public function down(Connection $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS tle_current');
    }
};
