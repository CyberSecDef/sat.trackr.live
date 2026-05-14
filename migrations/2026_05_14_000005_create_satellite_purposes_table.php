<?php

declare(strict_types=1);

use SatTrackr\Database\Connection;
use SatTrackr\Database\Migration;

return new class extends Migration {
    public function up(Connection $connection): void
    {
        $pdo = $connection->pdo();

        $pdo->exec(<<<'SQL'
            CREATE TABLE satellite_purposes (
              norad_id  INTEGER NOT NULL REFERENCES satellites(norad_id) ON DELETE CASCADE,
              purpose   TEXT NOT NULL
                        CHECK (purpose IN ('comms','earth_obs','nav','science','military',
                                           'human_sf','weather','station','tech_demo','unknown')),
              PRIMARY KEY (norad_id, purpose)
            )
            SQL);

        $pdo->exec('CREATE INDEX idx_satellite_purposes_purpose ON satellite_purposes(purpose)');
    }

    public function down(Connection $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS satellite_purposes');
    }
};
