<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SatTrackr\Database\Connection;
use Throwable;

/**
 * Phase 2 chunk 1: enrich the existing satellites rows with CelesTrak SATCAT
 * metadata (operator/country/launch_date/RCS/status/decay_date) and rebuild
 * satellite_purposes from group_membership.
 *
 * Idempotent — safe to re-run any time. Only touches SATCAT-derived columns
 * on satellites; never overwrites name/intl_designator (those are owned by
 * the CelesTrak GP ingester).
 */
final class SatCatIngester
{
    public function __construct(
        private readonly SatCatClient $client,
        private readonly Connection $db,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param list<string> $groups Empty = all per CelesTrakGroups::all().
     * @param ?callable(string $group, int $records, float $seconds): void $onGroup
     */
    public function run(array $groups = [], ?callable $onGroup = null): SatCatReport
    {
        $report = new SatCatReport();
        $groups = $groups === [] ? CelesTrakGroups::all() : $groups;

        $this->prepareStatements();

        foreach ($groups as $group) {
            $start = microtime(true);
            try {
                $records = $this->client->fetchGroup($group);
            } catch (NotModifiedException) {
                $report->groupsSkippedNotModified++;
                if ($onGroup !== null) {
                    $onGroup($group, 0, microtime(true) - $start);
                }
                $this->logger->info("SATCAT skip {$group} (not modified)");
                continue;
            } catch (Throwable $e) {
                $report->recordError($group, $e->getMessage());
                $this->logger->warning("SATCAT failed {$group}: {$e->getMessage()}");
                continue;
            }

            $touched = 0;
            foreach ($records as $record) {
                $report->recordsSeen++;
                if ($this->upsert($record)) {
                    $report->satellitesUpdated++;
                    $touched++;
                } else {
                    $report->satellitesUnknown++;
                }
            }
            $report->groupsProcessed++;
            $duration = microtime(true) - $start;
            if ($onGroup !== null) {
                $onGroup($group, $touched, $duration);
            }
            $this->logger->info("SATCAT {$group}: {$touched}/" . count($records) . " in " . round($duration, 2) . 's');
        }

        // After all SATCAT groups have run, rebuild satellite_purposes from
        // the (now-populated) group_membership table.
        $report->purposesDerived = $this->derivePurposes();

        $report->finish();
        $this->logger->info('SATCAT ingest complete', $report->toLogContext());
        return $report;
    }

    private \PDOStatement $updateSatellite;

    private function prepareStatements(): void
    {
        $pdo = $this->db->pdo();

        // UPDATE only — never INSERT. SATCAT records for objects we don't have
        // in our catalog (haven't ingested via CelesTrak GP) are skipped.
        // Only touches SATCAT-derived columns; preserves name + intl_designator
        // (owned by CelesTrak GP) and any future SATCAT-untouched fields.
        $stmt = $pdo->prepare(<<<'SQL'
            UPDATE satellites SET
              object_type      = :object_type,
              status           = :status,
              country          = :country,
              launch_date      = :launch_date,
              launch_site_code = :launch_site_code,
              decayed_at       = :decayed_at,
              rcs_meters       = :rcs_meters,
              updated_at       = :now
            WHERE norad_id = :norad_id
            SQL);
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare satellites UPDATE.');
        }
        $this->updateSatellite = $stmt;
    }

    /**
     * @param array<string, mixed> $record  one SATCAT JSON object
     * @return bool true if the satellite was updated; false if NORAD unknown
     */
    private function upsert(array $record): bool
    {
        $norad = (int) ($record['NORAD_CAT_ID'] ?? 0);
        if ($norad <= 0) {
            return false;
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');

        $launchDate     = self::normalizeDate($record['LAUNCH_DATE'] ?? null);
        $decayedAt      = self::normalizeDate($record['DECAY_DATE'] ?? null);
        $launchSiteCode = self::normalizeString($record['LAUNCH_SITE'] ?? null);
        $owner          = self::normalizeString($record['OWNER'] ?? null);
        $rcsRaw         = $record['RCS'] ?? null;
        $rcs            = (is_numeric($rcsRaw) && (float) $rcsRaw > 0) ? (float) $rcsRaw : null;

        $this->updateSatellite->execute([
            'norad_id'         => $norad,
            'object_type'      => SatCatMappers::objectType((string) ($record['OBJECT_TYPE'] ?? '')),
            'status'           => SatCatMappers::status((string) ($record['OPS_STATUS_CODE'] ?? '')),
            'country'          => $owner,
            'launch_date'      => $launchDate,
            'launch_site_code' => $launchSiteCode,
            'decayed_at'       => $decayedAt,
            'rcs_meters'       => $rcs,
            'now'              => $now,
        ]);

        return $this->updateSatellite->rowCount() > 0;
    }

    /**
     * Rebuild satellite_purposes from group_membership using the
     * SatCatMappers::purposesForGroups() heuristic. Wipe first, then
     * INSERT — keeps the table consistent with the latest grouping.
     *
     * @return int rows inserted
     */
    private function derivePurposes(): int
    {
        $pdo = $this->db->pdo();
        $pdo->exec('DELETE FROM satellite_purposes');

        // Pull all (norad_id, group_slug) rows, group in PHP, derive, insert.
        $stmt = $pdo->query('SELECT norad_id, group_slug FROM group_membership ORDER BY norad_id');
        if ($stmt === false) {
            return 0;
        }

        $bySat = [];
        foreach ($stmt as $row) {
            $bySat[(int) $row['norad_id']][] = (string) $row['group_slug'];
        }

        $insert = $pdo->prepare(
            'INSERT OR IGNORE INTO satellite_purposes (norad_id, purpose) VALUES (:norad_id, :purpose)'
        );
        if ($insert === false) {
            throw new \RuntimeException('Failed to prepare satellite_purposes INSERT.');
        }

        $count = 0;
        foreach ($bySat as $norad => $slugs) {
            foreach (SatCatMappers::purposesForGroups($slugs) as $purpose) {
                $insert->execute(['norad_id' => $norad, 'purpose' => $purpose]);
                if ($insert->rowCount() > 0) {
                    $count++;
                }
            }
        }
        return $count;
    }

    private static function normalizeDate(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        if ($v === '' || $v === '0000-00-00') {
            return null;
        }
        return $v;
    }

    private static function normalizeString(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        return $v === '' ? null : $v;
    }
}
