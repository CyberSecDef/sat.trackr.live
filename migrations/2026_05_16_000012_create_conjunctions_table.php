<?php

declare(strict_types=1);

use SatTrackr\Database\Connection;
use SatTrackr\Database\Migration;

return new class extends Migration {
    public function up(Connection $connection): void
    {
        $pdo = $connection->pdo();

        // Phase 4 chunk 1A — close-approach (conjunction) predictions.
        // Source today is CelesTrak SOCRATES Plus (sort-minRange.csv).
        // No FK on norad_id_primary / _secondary because conjunctions
        // frequently involve debris that's in SATCAT but not in our
        // currently-tracked GP feed (e.g. inactive Cosmos/Iridium debris).
        // The optional join is handled at query time.
        $pdo->exec(<<<'SQL'
            CREATE TABLE conjunctions (
              id                      INTEGER PRIMARY KEY AUTOINCREMENT,
              norad_id_primary        INTEGER NOT NULL,
              name_primary            TEXT NOT NULL,
              dse_primary             REAL,                       -- days since epoch for the primary's TLE
              norad_id_secondary      INTEGER NOT NULL,
              name_secondary          TEXT NOT NULL,
              dse_secondary           REAL,                       -- days since epoch for the secondary's TLE
              tca                     TEXT NOT NULL,              -- ISO datetime — Time of Closest Approach
              tca_range_km            REAL NOT NULL,              -- miss distance at TCA
              tca_relative_speed_km_s REAL,                       -- closure speed at TCA
              max_probability         REAL,                       -- max collision probability (Foster method)
              dilution                REAL,                       -- sigma value
              source                  TEXT NOT NULL DEFAULT 'CELESTRAK_SOCRATES'
                                      CHECK (source IN ('CELESTRAK_SOCRATES')),
              created_at              TEXT NOT NULL,
              updated_at              TEXT NOT NULL
            )
            SQL);

        // Query patterns:
        //   "upcoming within N hours, sorted by TCA"           → idx_tca
        //   "all conjunctions involving NORAD X"               → idx_norad_primary + idx_norad_secondary (UNION)
        //   "top probability conjunctions"                     → idx_probability
        //   "upsert on (primary, secondary, tca) deduplicates" → idx_pair_tca (UNIQUE)
        $pdo->exec('CREATE INDEX idx_conjunctions_tca         ON conjunctions(tca)');
        $pdo->exec('CREATE INDEX idx_conjunctions_norad_pri   ON conjunctions(norad_id_primary)');
        $pdo->exec('CREATE INDEX idx_conjunctions_norad_sec   ON conjunctions(norad_id_secondary)');
        $pdo->exec('CREATE INDEX idx_conjunctions_probability ON conjunctions(max_probability)');
        $pdo->exec('CREATE UNIQUE INDEX idx_conjunctions_pair_tca ON conjunctions(norad_id_primary, norad_id_secondary, tca)');
    }

    public function down(Connection $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS conjunctions');
    }
};
