<?php

declare(strict_types=1);

use SatTrackr\Database\Connection;
use SatTrackr\Database\Migration;

return new class extends Migration {
    public function up(Connection $connection): void
    {
        $pdo = $connection->pdo();

        // Phase 4 chunk 3A — periodic snapshots of NOAA SWPC space
        // weather indicators.  One row per ingest run (cron'd every
        // ~5 minutes); the 24h trend chart in the topbar popover reads
        // back from here rather than re-fetching NOAA on each page
        // load.  raw_message stashes the three source payloads as
        // JSON for traceability / future reprocessing.
        $pdo->exec(<<<'SQL'
            CREATE TABLE space_weather_samples (
              id              INTEGER PRIMARY KEY AUTOINCREMENT,
              sampled_at      TEXT NOT NULL,         -- ISO datetime (ingest time)
              kp              REAL,                  -- planetary K-index, 0-9 estimated_kp
              x_ray_flux      REAL,                  -- W/m² 0.1-0.8 nm band
              x_ray_class     TEXT,                  -- 'A' | 'B' | 'C' | 'M' | 'X' or null
              r_level         INTEGER,               -- NOAA R scale 0-5 (radio blackouts)
              s_level         INTEGER,               -- NOAA S scale 0-5 (radiation storms)
              g_level         INTEGER,               -- NOAA G scale 0-5 (geomagnetic storms)
              raw_message     TEXT,                  -- JSON: {kp_raw, xray_raw, scales_raw}
              created_at      TEXT NOT NULL
            )
            SQL);

        // Query patterns:
        //   "give me the latest sample"          → idx_sampled_at DESC LIMIT 1
        //   "all samples in last 24h"            → range scan on sampled_at
        //   "prune samples older than N days"    → range scan on sampled_at
        $pdo->exec('CREATE INDEX idx_space_weather_sampled_at ON space_weather_samples(sampled_at)');
    }

    public function down(Connection $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS space_weather_samples');
    }
};
