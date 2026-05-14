<?php

declare(strict_types=1);

use SatTrackr\Database\Connection;
use SatTrackr\Database\Migration;

return new class extends Migration {
    public function up(Connection $connection): void
    {
        $pdo = $connection->pdo();

        $pdo->exec(<<<'SQL'
            CREATE VIRTUAL TABLE satellites_fts USING fts5(
              name,
              intl_designator,
              operator,
              content='satellites',
              content_rowid='norad_id',
              tokenize='unicode61 remove_diacritics 2'
            )
            SQL);

        // Sync triggers — keep FTS index aligned with the satellites table.
        $pdo->exec(<<<'SQL'
            CREATE TRIGGER satellites_fts_ai AFTER INSERT ON satellites BEGIN
              INSERT INTO satellites_fts(rowid, name, intl_designator, operator)
              VALUES (new.norad_id, new.name, new.intl_designator, new.operator);
            END
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TRIGGER satellites_fts_ad AFTER DELETE ON satellites BEGIN
              INSERT INTO satellites_fts(satellites_fts, rowid, name, intl_designator, operator)
              VALUES ('delete', old.norad_id, old.name, old.intl_designator, old.operator);
            END
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TRIGGER satellites_fts_au AFTER UPDATE ON satellites BEGIN
              INSERT INTO satellites_fts(satellites_fts, rowid, name, intl_designator, operator)
              VALUES ('delete', old.norad_id, old.name, old.intl_designator, old.operator);
              INSERT INTO satellites_fts(rowid, name, intl_designator, operator)
              VALUES (new.norad_id, new.name, new.intl_designator, new.operator);
            END
            SQL);
    }

    public function down(Connection $connection): void
    {
        $pdo = $connection->pdo();
        $pdo->exec('DROP TRIGGER IF EXISTS satellites_fts_au');
        $pdo->exec('DROP TRIGGER IF EXISTS satellites_fts_ad');
        $pdo->exec('DROP TRIGGER IF EXISTS satellites_fts_ai');
        $pdo->exec('DROP TABLE IF EXISTS satellites_fts');
    }
};
