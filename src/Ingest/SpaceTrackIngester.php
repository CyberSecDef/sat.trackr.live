<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SatTrackr\Database\Connection;
use Throwable;

/**
 * Phase 2 chunk 4 ingester.  Pulls TIP (Tracking and Impact Prediction)
 * messages from Space-Track and UPSERTs them into the `reentries`
 * table keyed by `(norad_id, source='SPACE_TRACK_TIP')`.
 *
 * TIPs that refer to a NORAD ID not in our `satellites` table are
 * skipped (counted in the report) — the FK constraint would reject
 * them and there's no useful detail we could surface in the UI for
 * an unknown object anyway.
 */
final class SpaceTrackIngester
{
    private const QUERY = 'class/tip/orderby/INSERT_EPOCH desc/limit/';

    private \PDOStatement $upsertReentry;
    private \PDOStatement $satelliteExists;

    public function __construct(
        private readonly SpaceTrackClient $client,
        private readonly Connection $db,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function run(int $limit = 100): ReentryIngestReport
    {
        $report = new ReentryIngestReport();
        $this->prepareStatements();

        try {
            $tips = $this->client->query(self::QUERY . max(1, $limit));
            $report->tipsFetched = count($tips);
        } catch (Throwable $e) {
            $report->recordError('fetch', $e->getMessage());
            $this->logger->warning('Space-Track TIP fetch failed: ' . $e->getMessage());
            $report->finish();
            return $report;
        }

        foreach ($tips as $tip) {
            $this->ingestTip($tip, $report);
        }

        $report->finish();
        $this->logger->info('Space-Track ingest complete', $report->toLogContext());
        return $report;
    }

    private function prepareStatements(): void
    {
        $pdo = $this->db->pdo();

        $this->upsertReentry = $this->prepare($pdo, <<<'SQL'
            INSERT INTO reentries
              (norad_id, predicted_decay, confidence_window_hours, source, risk_score, raw_message, created_at, updated_at)
            VALUES
              (:norad_id, :predicted_decay, :confidence_window_hours, :source, :risk_score, :raw_message, :now, :now)
            ON CONFLICT(norad_id, source) DO UPDATE SET
              predicted_decay         = excluded.predicted_decay,
              confidence_window_hours = excluded.confidence_window_hours,
              risk_score              = COALESCE(reentries.risk_score, excluded.risk_score),
              raw_message             = excluded.raw_message,
              updated_at              = excluded.updated_at
            SQL);

        $this->satelliteExists = $this->prepare(
            $pdo,
            'SELECT 1 FROM satellites WHERE norad_id = :norad LIMIT 1'
        );
    }

    private function prepare(PDO $pdo, string $sql): \PDOStatement
    {
        $stmt = $pdo->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare statement: ' . substr($sql, 0, 80));
        }
        return $stmt;
    }

    /** @param array<string, mixed> $tip */
    private function ingestTip(array $tip, ReentryIngestReport $report): void
    {
        $norad = self::intOrNull($tip['NORAD_CAT_ID'] ?? null);
        $decay = self::str($tip['DECAY_EPOCH'] ?? null);
        if ($norad === null || $decay === null) {
            $report->skippedMalformed++;
            return;
        }

        $this->satelliteExists->execute(['norad' => $norad]);
        if ($this->satelliteExists->fetchColumn() === false) {
            $report->skippedUnknownNorad++;
            return;
        }

        $now    = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
        $window = self::floatOrNull($tip['WINDOW'] ?? null);
        $hours  = $window !== null ? round($window / 60.0, 3) : null;

        try {
            $this->upsertReentry->execute([
                'norad_id'                => $norad,
                'predicted_decay'         => $decay,
                'confidence_window_hours' => $hours,
                'source'                  => 'SPACE_TRACK_TIP',
                'risk_score'              => null,
                'raw_message'             => json_encode($tip, JSON_UNESCAPED_SLASHES),
                'now'                     => $now,
            ]);
            $report->reentriesUpserted++;
        } catch (Throwable $e) {
            $report->recordError('upsert', "norad={$norad}: {$e->getMessage()}");
            $this->logger->warning("TIP upsert failed for {$norad}: {$e->getMessage()}");
        }
    }

    private static function str(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        return $v === '' ? null : $v;
    }

    private static function intOrNull(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && ctype_digit($v)) {
            return (int) $v;
        }
        return null;
    }

    private static function floatOrNull(mixed $v): ?float
    {
        if (is_float($v) || is_int($v)) {
            return (float) $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (float) $v;
        }
        return null;
    }
}
