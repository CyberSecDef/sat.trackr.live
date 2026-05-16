<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SatTrackr\Database\Connection;
use Throwable;

/**
 * Phase 5 chunk 1A — orchestrates SatnogsClient + UPSERTs into the
 * `satellite_radio` table.  Skips transmitters whose `norad_cat_id`
 * isn't in our catalog (no FK on the table, but storing orphan
 * rows is pointless when the join would never find a parent).
 *
 * The cron'd refresh runs weekly (amateur frequencies don't shift
 * often); each run is idempotent because we UPSERT on the SatNOGS
 * UUID.
 */
final class SatnogsIngester
{
    private \PDOStatement $upsert;
    private \PDOStatement $exists;

    public function __construct(
        private readonly SatnogsClient $client,
        private readonly Connection $db,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /** @return array<string, int> */
    public function run(): array
    {
        $report = ['fetched' => 0, 'upserted' => 0, 'skipped_orphan' => 0, 'skipped_malformed' => 0];

        try {
            $rows = $this->client->fetchAllTransmitters();
        } catch (Throwable $e) {
            $this->logger->warning('SatNOGS fetch failed: ' . $e->getMessage());
            throw $e;
        }
        $report['fetched'] = count($rows);

        $this->prepareStatements();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            foreach ($rows as $r) {
                $uuid  = self::str($r['uuid'] ?? null);
                $norad = self::intOrNull($r['norad_cat_id'] ?? null);
                if ($uuid === null || $norad === null) {
                    $report['skipped_malformed']++;
                    continue;
                }

                $this->exists->execute(['norad' => $norad]);
                if ($this->exists->fetchColumn() === false) {
                    $report['skipped_orphan']++;
                    continue;
                }

                $this->upsert->execute([
                    'uuid'              => $uuid,
                    'norad_id'          => $norad,
                    'description'       => self::str($r['description'] ?? null),
                    'type'              => self::str($r['type'] ?? null),
                    'alive'             => !empty($r['alive']) ? 1 : 0,
                    'uplink_low_hz'     => self::intOrNull($r['uplink_low'] ?? null),
                    'uplink_high_hz'    => self::intOrNull($r['uplink_high'] ?? null),
                    'downlink_low_hz'   => self::intOrNull($r['downlink_low'] ?? null),
                    'downlink_high_hz'  => self::intOrNull($r['downlink_high'] ?? null),
                    'mode'              => self::str($r['mode'] ?? null),
                    'baud'              => self::floatOrNull($r['baud'] ?? null),
                    'service'           => self::str($r['service'] ?? null),
                    'status'            => self::str($r['status'] ?? null),
                    'updated_at'        => $now,
                ]);
                $report['upserted']++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->logger->info('SatNOGS ingest complete', $report);
        return $report;
    }

    private function prepareStatements(): void
    {
        $pdo = $this->db->pdo();
        $this->exists = self::prepare($pdo, 'SELECT 1 FROM satellites WHERE norad_id = :norad LIMIT 1');
        $this->upsert = self::prepare($pdo, <<<'SQL'
            INSERT INTO satellite_radio
              (uuid, norad_id, description, type, alive,
               uplink_low_hz, uplink_high_hz, downlink_low_hz, downlink_high_hz,
               mode, baud, service, status, updated_at)
            VALUES
              (:uuid, :norad_id, :description, :type, :alive,
               :uplink_low_hz, :uplink_high_hz, :downlink_low_hz, :downlink_high_hz,
               :mode, :baud, :service, :status, :updated_at)
            ON CONFLICT(uuid) DO UPDATE SET
              norad_id         = excluded.norad_id,
              description      = excluded.description,
              type             = excluded.type,
              alive            = excluded.alive,
              uplink_low_hz    = excluded.uplink_low_hz,
              uplink_high_hz   = excluded.uplink_high_hz,
              downlink_low_hz  = excluded.downlink_low_hz,
              downlink_high_hz = excluded.downlink_high_hz,
              mode             = excluded.mode,
              baud             = excluded.baud,
              service          = excluded.service,
              status           = excluded.status,
              updated_at       = excluded.updated_at
            SQL);
    }

    private static function prepare(PDO $pdo, string $sql): \PDOStatement
    {
        $stmt = $pdo->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare statement: ' . substr($sql, 0, 80));
        }
        return $stmt;
    }

    private static function str(mixed $v): ?string
    {
        if (!is_string($v)) return null;
        $v = trim($v);
        return $v === '' ? null : $v;
    }

    private static function intOrNull(mixed $v): ?int
    {
        if (is_int($v)) return $v;
        if (is_string($v) && ctype_digit($v)) return (int) $v;
        return null;
    }

    private static function floatOrNull(mixed $v): ?float
    {
        if (is_float($v) || is_int($v)) return (float) $v;
        if (is_string($v) && is_numeric($v)) return (float) $v;
        return null;
    }
}
