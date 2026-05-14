<?php

declare(strict_types=1);

use SatTrackr\Database\Connection;
use SatTrackr\Database\Migration;

return new class extends Migration {
    public function up(Connection $connection): void
    {
        $pdo = $connection->pdo();

        $pdo->exec(<<<'SQL'
            CREATE TABLE launch_sites (
              id          INTEGER PRIMARY KEY,
              name        TEXT NOT NULL,
              latitude    REAL,
              longitude   REAL,
              country     TEXT,
              operator    TEXT,
              description TEXT,
              url         TEXT,
              updated_at  TEXT NOT NULL
            )
            SQL);

        $pdo->exec('CREATE INDEX idx_launch_sites_country ON launch_sites(country)');
    }

    public function down(Connection $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS launch_sites');
    }
};
