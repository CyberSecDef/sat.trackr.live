<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SatTrackr\Database\Connection;
use Throwable;

/**
 * Ingests CelesTrak GP groups into the satellites + tle_current + tle_history
 * tables. Idempotent: rerunning is safe and adds no history rows when the
 * epoch hasn't changed.
 */
final class CelesTrakIngester
{
    public function __construct(
        private readonly CelesTrakClient $client,
        private readonly TleParser $parser,
        private readonly Connection $db,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param list<string> $groups       Groups to ingest. Empty = all per CelesTrakGroups::all().
     * @param ?callable(string $group, int $records, float $seconds): void $onGroup
     *        Optional progress callback fired after each group completes.
     */
    public function run(array $groups = [], ?callable $onGroup = null): IngestReport
    {
        $report = new IngestReport();
        $groups = $groups === [] ? CelesTrakGroups::all() : $groups;

        $this->prepareStatements();

        foreach ($groups as $group) {
            $groupStart = microtime(true);
            $groupRecords = 0;

            try {
                $body = $this->client->fetchGroup($group);
            } catch (NotModifiedException $e) {
                // CelesTrak says nothing changed — count as processed-but-skipped
                // and move on. Not an error.
                $report->groupsSkippedNotModified++;
                $duration = microtime(true) - $groupStart;
                if ($onGroup !== null) {
                    $onGroup($group, 0, $duration);
                }
                $this->logger->info("Skipped group {$group} (not modified)");
                continue;
            } catch (Throwable $e) {
                $report->recordError($group, $e->getMessage());
                $this->logger->warning("Failed to fetch group {$group}: {$e->getMessage()}");
                continue;
            }

            $records = $this->splitIntoTriplets($body);
            foreach ($records as [$name, $line1, $line2]) {
                try {
                    $tle = $this->parser->parse($name, $line1, $line2);
                } catch (InvalidTleException $e) {
                    // Try to peek the NORAD ID for the reject log, even if parse failed.
                    $maybeNorad = is_numeric(trim(substr($line1, 2, 5)))
                        ? (int) trim(substr($line1, 2, 5))
                        : null;
                    $report->recordReject($group, $maybeNorad, $e->getMessage());
                    continue;
                }

                try {
                    $this->upsert($tle, $report);
                    $groupRecords++;
                } catch (Throwable $e) {
                    $report->recordReject($group, $tle->noradId, 'upsert failed: ' . $e->getMessage());
                }
            }

            $report->groupsProcessed++;
            $duration = microtime(true) - $groupStart;
            if ($onGroup !== null) {
                $onGroup($group, $groupRecords, $duration);
            }
            $this->logger->info("Ingested group {$group}: {$groupRecords} records in " . round($duration, 2) . 's');
        }

        $report->finish();
        $this->logger->info('CelesTrak ingest complete', $report->toLogContext());
        return $report;
    }

    /**
     * Split a CelesTrak TLE-format response into [name, line1, line2] triplets.
     * Tolerant of trailing blank lines and Windows-style CRLF endings.
     *
     * @return list<array{string, string, string}>
     */
    private function splitIntoTriplets(string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($body)) ?: [];
        $records = [];
        $count = count($lines);
        for ($i = 0; $i + 2 < $count; $i += 3) {
            $records[] = [$lines[$i], $lines[$i + 1], $lines[$i + 2]];
        }
        // If $count isn't a multiple of 3, the trailing partial record is dropped
        // — every well-formed CelesTrak response is a multiple of 3 lines.
        return $records;
    }

    private \PDOStatement $upsertSatellite;
    private \PDOStatement $upsertTleCurrent;
    private \PDOStatement $insertTleHistory;

    private function prepareStatements(): void
    {
        $pdo = $this->db->pdo();

        // Satellite upsert preserves Phase-2 SATCAT-populated fields (operator,
        // country, mass, etc.) — only name + intl_designator + updated_at change
        // on conflict.
        $this->upsertSatellite = $this->prepare($pdo, <<<'SQL'
            INSERT INTO satellites (norad_id, name, intl_designator, created_at, updated_at)
            VALUES (:norad_id, :name, :intl_designator, :now, :now)
            ON CONFLICT(norad_id) DO UPDATE SET
              name            = excluded.name,
              intl_designator = excluded.intl_designator,
              updated_at      = excluded.updated_at
            SQL);

        $this->upsertTleCurrent = $this->prepare($pdo, <<<'SQL'
            INSERT INTO tle_current (
              norad_id, epoch, line1, line2,
              mean_motion, eccentricity, inclination_deg, raan_deg,
              arg_perigee_deg, mean_anomaly_deg, bstar, rev_number,
              period_min, perigee_km, apogee_km, semimajor_km,
              source, updated_at
            ) VALUES (
              :norad_id, :epoch, :line1, :line2,
              :mean_motion, :eccentricity, :inclination_deg, :raan_deg,
              :arg_perigee_deg, :mean_anomaly_deg, :bstar, :rev_number,
              :period_min, :perigee_km, :apogee_km, :semimajor_km,
              'CELESTRAK', :now
            )
            ON CONFLICT(norad_id) DO UPDATE SET
              epoch            = excluded.epoch,
              line1            = excluded.line1,
              line2            = excluded.line2,
              mean_motion      = excluded.mean_motion,
              eccentricity     = excluded.eccentricity,
              inclination_deg  = excluded.inclination_deg,
              raan_deg         = excluded.raan_deg,
              arg_perigee_deg  = excluded.arg_perigee_deg,
              mean_anomaly_deg = excluded.mean_anomaly_deg,
              bstar            = excluded.bstar,
              rev_number       = excluded.rev_number,
              period_min       = excluded.period_min,
              perigee_km       = excluded.perigee_km,
              apogee_km        = excluded.apogee_km,
              semimajor_km     = excluded.semimajor_km,
              updated_at       = excluded.updated_at
            SQL);

        // tle_history is append-only; INSERT OR IGNORE makes re-runs cheap when
        // the epoch hasn't changed since the last fetch.
        $this->insertTleHistory = $this->prepare($pdo, <<<'SQL'
            INSERT OR IGNORE INTO tle_history (
              norad_id, epoch, line1, line2,
              mean_motion, eccentricity, inclination_deg, raan_deg,
              arg_perigee_deg, mean_anomaly_deg, bstar, rev_number,
              period_min, perigee_km, apogee_km, semimajor_km,
              source, ingested_at
            ) VALUES (
              :norad_id, :epoch, :line1, :line2,
              :mean_motion, :eccentricity, :inclination_deg, :raan_deg,
              :arg_perigee_deg, :mean_anomaly_deg, :bstar, :rev_number,
              :period_min, :perigee_km, :apogee_km, :semimajor_km,
              'CELESTRAK', :now
            )
            SQL);
    }

    private function prepare(PDO $pdo, string $sql): \PDOStatement
    {
        $stmt = $pdo->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare statement: ' . $sql);
        }
        return $stmt;
    }

    private function upsert(ParsedTle $tle, IngestReport $report): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');

        $this->upsertSatellite->execute([
            'norad_id'        => $tle->noradId,
            'name'            => $tle->name,
            'intl_designator' => $tle->intlDesignator,
            'now'             => $now,
        ]);
        $report->satellitesUpserted++;

        $tleParams = [
            'norad_id'         => $tle->noradId,
            'epoch'            => $tle->epochIso,
            'line1'            => $tle->line1,
            'line2'            => $tle->line2,
            'mean_motion'      => $tle->meanMotion,
            'eccentricity'     => $tle->eccentricity,
            'inclination_deg'  => $tle->inclinationDeg,
            'raan_deg'         => $tle->raanDeg,
            'arg_perigee_deg'  => $tle->argPerigeeDeg,
            'mean_anomaly_deg' => $tle->meanAnomalyDeg,
            'bstar'            => $tle->bstar,
            'rev_number'       => $tle->revNumber,
            'period_min'       => $tle->periodMin,
            'perigee_km'       => $tle->perigeeKm,
            'apogee_km'        => $tle->apogeeKm,
            'semimajor_km'     => $tle->semimajorKm,
            'now'              => $now,
        ];

        $this->upsertTleCurrent->execute($tleParams);
        $report->tleCurrentUpserted++;

        $this->insertTleHistory->execute($tleParams);
        if ($this->insertTleHistory->rowCount() > 0) {
            $report->tleHistoryAdded++;
        }
    }
}
