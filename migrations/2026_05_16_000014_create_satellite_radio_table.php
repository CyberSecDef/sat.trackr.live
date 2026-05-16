<?php

declare(strict_types=1);

use SatTrackr\Database\Connection;
use SatTrackr\Database\Migration;

return new class extends Migration {
    public function up(Connection $connection): void
    {
        $pdo = $connection->pdo();

        // Phase 5 chunk 1A — amateur-radio + operational transmitter
        // metadata sourced from the SatNOGS DB.  Multiple rows per
        // NORAD ID are normal (e.g. ISS has 10+ transmitters across
        // APRS, voice repeater, packet, etc.).  UUID is SatNOGS's
        // stable identifier so the ingester can UPSERT.
        //
        // No FK on norad_id — SatNOGS includes many transmitters for
        // satellites that aren't in our CelesTrak GP feed (older /
        // inactive). The ingester filters orphans at insert time;
        // we keep this open in case operators want to pre-seed.
        $pdo->exec(<<<'SQL'
            CREATE TABLE satellite_radio (
              uuid              TEXT PRIMARY KEY,           -- SatNOGS transmitter UUID
              norad_id          INTEGER NOT NULL,
              description       TEXT,                       -- "Mode V APRS", "Mode V/V FM (crew R2+3)", …
              type              TEXT,                       -- Transmitter | Receiver | Transceiver | Transponder
              alive             INTEGER NOT NULL DEFAULT 0, -- 0/1
              uplink_low_hz     INTEGER,
              uplink_high_hz    INTEGER,
              downlink_low_hz   INTEGER,
              downlink_high_hz  INTEGER,
              mode              TEXT,                       -- AFSK | FM | BPSK | LRPT | …
              baud              REAL,
              service           TEXT,                       -- Amateur | Operational | Educational | …
              status            TEXT,                       -- active | inactive | invalid
              updated_at        TEXT NOT NULL
            )
            SQL);

        $pdo->exec('CREATE INDEX idx_satellite_radio_norad ON satellite_radio(norad_id)');
        $pdo->exec('CREATE INDEX idx_satellite_radio_alive ON satellite_radio(alive)');
    }

    public function down(Connection $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS satellite_radio');
    }
};
