<?php

declare(strict_types=1);

use SatTrackr\Database\Connection;
use SatTrackr\Database\Migration;

return new class extends Migration {
    public function up(Connection $connection): void
    {
        $pdo = $connection->pdo();

        $pdo->exec(<<<'SQL'
            CREATE TABLE satellites (
              norad_id          INTEGER PRIMARY KEY,
              intl_designator   TEXT,
              name              TEXT NOT NULL,
              alt_names         TEXT,
              object_type       TEXT NOT NULL DEFAULT 'UNKNOWN'
                                CHECK (object_type IN ('PAYLOAD','ROCKET_BODY','DEBRIS','TBA','UNKNOWN')),
              status            TEXT NOT NULL DEFAULT 'UNKNOWN'
                                CHECK (status IN ('ACTIVE','INACTIVE','PARTIALLY_OPERATIONAL','DECAYED','UNKNOWN')),
              operator          TEXT,
              country           TEXT,
              launch_date       TEXT,
              launch_site_id    INTEGER,
              launch_vehicle    TEXT,
              mission           TEXT,
              orbit_class       TEXT NOT NULL DEFAULT 'UNKNOWN'
                                CHECK (orbit_class IN ('LEO','MEO','GEO','HEO','MOLNIYA','SSO','POLAR','GTO','UNKNOWN')),
              rcs_meters        REAL,
              size_class        TEXT CHECK (size_class IN ('SMALL','MEDIUM','LARGE')),
              mass_kg           INTEGER,
              dimensions        TEXT,
              has_3d_model      INTEGER NOT NULL DEFAULT 0,
              image_url         TEXT,
              wikipedia_slug    TEXT,
              decayed_at        TEXT,
              created_at        TEXT NOT NULL,
              updated_at        TEXT NOT NULL
            )
            SQL);

        $pdo->exec('CREATE INDEX idx_satellites_country     ON satellites(country)');
        $pdo->exec('CREATE INDEX idx_satellites_operator    ON satellites(operator)');
        $pdo->exec('CREATE INDEX idx_satellites_status_type ON satellites(status, object_type)');
        $pdo->exec('CREATE INDEX idx_satellites_orbit_class ON satellites(orbit_class)');
        $pdo->exec('CREATE INDEX idx_satellites_launch_date ON satellites(launch_date)');
        $pdo->exec('CREATE INDEX idx_satellites_name        ON satellites(name)');
    }

    public function down(Connection $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS satellites');
    }
};
