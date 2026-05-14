<?php

declare(strict_types=1);

use SatTrackr\Database\Connection;
use SatTrackr\Database\Migration;

return new class extends Migration {
    public function up(Connection $connection): void
    {
        $pdo = $connection->pdo();

        $pdo->exec(<<<'SQL'
            CREATE TABLE reentries (
              id                      INTEGER PRIMARY KEY AUTOINCREMENT,
              norad_id                INTEGER NOT NULL REFERENCES satellites(norad_id) ON DELETE CASCADE,
              predicted_decay         TEXT NOT NULL,
              confidence_window_hours REAL,
              source                  TEXT NOT NULL DEFAULT 'SPACE_TRACK_TIP'
                                      CHECK (source IN ('SPACE_TRACK_TIP','CELESTRAK_SATCAT','COMPUTED')),
              risk_score              REAL,
              raw_message             TEXT,
              created_at              TEXT NOT NULL,
              updated_at              TEXT NOT NULL
            )
            SQL);

        $pdo->exec('CREATE INDEX idx_reentries_predicted_decay ON reentries(predicted_decay)');
        $pdo->exec('CREATE INDEX idx_reentries_norad           ON reentries(norad_id)');
        $pdo->exec('CREATE UNIQUE INDEX idx_reentries_norad_source ON reentries(norad_id, source)');
    }

    public function down(Connection $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS reentries');
    }
};
