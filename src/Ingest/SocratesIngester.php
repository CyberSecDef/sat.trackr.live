<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SatTrackr\Database\Connection;
use Throwable;

/**
 * Phase 4 chunk 1C — orchestrates SocratesClient + SocratesCsvParser
 * and UPSERTs the result into the `conjunctions` table.
 *
 * The CSV typically carries 30k-50k close-approach rows.  An optional
 * `--max-tca-hours` filter trims to the user-visible window so we
 * don't store 30 days of low-probability noise.  Default is 168h
 * (7 days) which matches the chunk-2 default endpoint cap.
 *
 * UPSERT is keyed on the (norad_primary, norad_secondary, tca) unique
 * index — re-running for the same conjunction event refreshes
 * range/probability/dilution in place.
 */
final class SocratesIngester
{
    private \PDOStatement $upsertConjunction;

    public function __construct(
        private readonly SocratesClient $client,
        private readonly SocratesCsvParser $parser,
        private readonly Connection $db,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function run(int $maxTcaHours = 168): ConjunctionIngestReport
    {
        $report = new ConjunctionIngestReport();

        try {
            $csv = $this->client->fetchMinRangeCsv();
        } catch (Throwable $e) {
            $report->recordError('fetch', $e->getMessage());
            $this->logger->warning('SOCRATES fetch failed: ' . $e->getMessage());
            $report->finish();
            return $report;
        }
        $report->rowsFetched = max(0, substr_count($csv, "\n") - 1);

        try {
            $rows = $this->parser->parse($csv);
            $report->rowsParsed = count($rows);
        } catch (Throwable $e) {
            $report->recordError('parse', $e->getMessage());
            $this->logger->warning('SOCRATES parse failed: ' . $e->getMessage());
            $report->finish();
            return $report;
        }

        $this->prepareStatements();

        $now    = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
        $window = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("+{$maxTcaHours} hours")
            ->format('Y-m-d\TH:i:s\Z');

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            foreach ($rows as $r) {
                if ($r['tca'] > $window) {
                    continue;       // outside user-visible window — skip
                }
                try {
                    $this->upsertConjunction->execute([
                        'norad_primary'           => $r['norad_primary'],
                        'name_primary'            => $r['name_primary'],
                        'dse_primary'             => $r['dse_primary'],
                        'norad_secondary'         => $r['norad_secondary'],
                        'name_secondary'          => $r['name_secondary'],
                        'dse_secondary'           => $r['dse_secondary'],
                        'tca'                     => $r['tca'],
                        'tca_range_km'            => $r['tca_range_km'],
                        'tca_relative_speed_km_s' => $r['tca_relative_speed_km_s'],
                        'max_probability'         => $r['max_probability'],
                        'dilution'                => $r['dilution'],
                        'now'                     => $now,
                    ]);
                    $report->upserted++;
                } catch (Throwable $e) {
                    $report->skippedMalformed++;
                    $this->logger->warning(
                        sprintf(
                            'Conjunction upsert failed for %d×%d @%s: %s',
                            $r['norad_primary'], $r['norad_secondary'], $r['tca'], $e->getMessage()
                        )
                    );
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $report->recordError('upsert-tx', $e->getMessage());
            throw $e;
        }

        $report->finish();
        $this->logger->info('SOCRATES ingest complete', $report->toLogContext());
        return $report;
    }

    private function prepareStatements(): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(<<<'SQL'
            INSERT INTO conjunctions
              (norad_id_primary, name_primary, dse_primary,
               norad_id_secondary, name_secondary, dse_secondary,
               tca, tca_range_km, tca_relative_speed_km_s,
               max_probability, dilution,
               source, created_at, updated_at)
            VALUES
              (:norad_primary, :name_primary, :dse_primary,
               :norad_secondary, :name_secondary, :dse_secondary,
               :tca, :tca_range_km, :tca_relative_speed_km_s,
               :max_probability, :dilution,
               'CELESTRAK_SOCRATES', :now, :now)
            ON CONFLICT(norad_id_primary, norad_id_secondary, tca) DO UPDATE SET
              name_primary            = excluded.name_primary,
              dse_primary             = excluded.dse_primary,
              name_secondary          = excluded.name_secondary,
              dse_secondary           = excluded.dse_secondary,
              tca_range_km            = excluded.tca_range_km,
              tca_relative_speed_km_s = excluded.tca_relative_speed_km_s,
              max_probability         = excluded.max_probability,
              dilution                = excluded.dilution,
              updated_at              = excluded.updated_at
            SQL);
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare conjunction upsert');
        }
        $this->upsertConjunction = $stmt;
    }
}
