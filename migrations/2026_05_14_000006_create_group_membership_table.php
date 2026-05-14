<?php

declare(strict_types=1);

use SatTrackr\Database\Connection;
use SatTrackr\Database\Migration;

return new class extends Migration {
    public function up(Connection $connection): void
    {
        $pdo = $connection->pdo();

        $pdo->exec(<<<'SQL'
            CREATE TABLE group_membership (
              norad_id     INTEGER NOT NULL REFERENCES satellites(norad_id) ON DELETE CASCADE,
              group_slug   TEXT NOT NULL,
              last_seen_at TEXT NOT NULL,
              PRIMARY KEY (norad_id, group_slug)
            )
            SQL);

        $pdo->exec('CREATE INDEX idx_group_membership_slug ON group_membership(group_slug)');
    }

    public function down(Connection $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS group_membership');
    }
};
