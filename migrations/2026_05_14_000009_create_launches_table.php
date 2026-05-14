<?php

declare(strict_types=1);

use SatTrackr\Database\Connection;
use SatTrackr\Database\Migration;

return new class extends Migration {
    public function up(Connection $connection): void
    {
        $pdo = $connection->pdo();

        $pdo->exec(<<<'SQL'
            CREATE TABLE launches (
              id                    TEXT PRIMARY KEY,
              name                  TEXT NOT NULL,
              net                   TEXT NOT NULL,
              status                TEXT NOT NULL DEFAULT 'TBD'
                                    CHECK (status IN ('GO','TBD','HOLD','SUCCESS','FAILURE','PARTIAL_FAILURE','UNKNOWN')),
              provider              TEXT,
              vehicle               TEXT,
              pad_id                INTEGER REFERENCES launch_sites(id) ON DELETE SET NULL,
              mission_name          TEXT,
              mission_type          TEXT,
              orbit_target          TEXT,
              customer              TEXT,
              webcast_url           TEXT,
              image_url             TEXT,
              description           TEXT,
              associated_norad_ids  TEXT,
              updated_at            TEXT NOT NULL
            )
            SQL);

        $pdo->exec('CREATE INDEX idx_launches_net    ON launches(net)');
        $pdo->exec('CREATE INDEX idx_launches_status ON launches(status)');
        $pdo->exec('CREATE INDEX idx_launches_pad    ON launches(pad_id)');
    }

    public function down(Connection $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS launches');
    }
};
