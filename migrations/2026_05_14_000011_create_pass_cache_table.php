<?php

declare(strict_types=1);

use SatTrackr\Database\Connection;
use SatTrackr\Database\Migration;

return new class extends Migration {
    public function up(Connection $connection): void
    {
        $pdo = $connection->pdo();

        $pdo->exec(<<<'SQL'
            CREATE TABLE pass_cache (
              cache_key    TEXT PRIMARY KEY,
              norad_id     INTEGER NOT NULL,
              observer_lat REAL NOT NULL,
              observer_lon REAL NOT NULL,
              observer_alt REAL NOT NULL DEFAULT 0,
              day          TEXT NOT NULL,
              passes_json  TEXT NOT NULL,
              computed_at  TEXT NOT NULL,
              expires_at   TEXT NOT NULL
            )
            SQL);

        $pdo->exec('CREATE INDEX idx_pass_cache_expires ON pass_cache(expires_at)');
        $pdo->exec('CREATE INDEX idx_pass_cache_norad   ON pass_cache(norad_id)');
    }

    public function down(Connection $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS pass_cache');
    }
};
