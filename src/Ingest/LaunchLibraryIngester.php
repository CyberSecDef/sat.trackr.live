<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SatTrackr\Database\Connection;
use Throwable;

/**
 * Phase 2 chunk 3 ingester. Pulls upcoming + previous launches from
 * Launch Library 2 and UPSERTs them into our `launches` + `launch_sites`
 * tables.
 *
 * Per-launch flow: extract the pad → UPSERT into launch_sites first (FK
 * dependency for launches.pad_id) → UPSERT the launch row. Idempotent
 * on re-run.
 */
final class LaunchLibraryIngester
{
    public function __construct(
        private readonly LaunchLibraryClient $client,
        private readonly Connection $db,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function run(string $mode = 'both'): LaunchIngestReport
    {
        $report = new LaunchIngestReport();
        $this->prepareStatements();

        if ($mode === 'upcoming' || $mode === 'both') {
            try {
                $upcoming = $this->client->fetchUpcoming(50);
                $report->upcomingFetched = count($upcoming);
                foreach ($upcoming as $launch) {
                    $this->ingestLaunch($launch, $report);
                }
            } catch (Throwable $e) {
                $report->recordError('upcoming', $e->getMessage());
                $this->logger->warning('LL2 upcoming fetch failed: ' . $e->getMessage());
            }
        }

        if ($mode === 'previous' || $mode === 'both') {
            try {
                $previous = $this->client->fetchPrevious(100);
                $report->previousFetched = count($previous);
                foreach ($previous as $launch) {
                    $this->ingestLaunch($launch, $report);
                }
            } catch (Throwable $e) {
                $report->recordError('previous', $e->getMessage());
                $this->logger->warning('LL2 previous fetch failed: ' . $e->getMessage());
            }
        }

        $report->finish();
        $this->logger->info('LL2 ingest complete', $report->toLogContext());
        return $report;
    }

    private \PDOStatement $upsertPad;
    private \PDOStatement $upsertLaunch;

    private function prepareStatements(): void
    {
        $pdo = $this->db->pdo();

        $this->upsertPad = $this->prepare($pdo, <<<'SQL'
            INSERT INTO launch_sites (id, name, latitude, longitude, country, operator, description, url, updated_at)
            VALUES (:id, :name, :latitude, :longitude, :country, :operator, :description, :url, :now)
            ON CONFLICT(id) DO UPDATE SET
              name        = excluded.name,
              latitude    = excluded.latitude,
              longitude   = excluded.longitude,
              country     = excluded.country,
              operator    = excluded.operator,
              description = excluded.description,
              url         = excluded.url,
              updated_at  = excluded.updated_at
            SQL);

        $this->upsertLaunch = $this->prepare($pdo, <<<'SQL'
            INSERT INTO launches (
              id, name, net, status, provider, vehicle, pad_id,
              mission_name, mission_type, orbit_target, customer,
              webcast_url, image_url, description, associated_norad_ids,
              updated_at
            ) VALUES (
              :id, :name, :net, :status, :provider, :vehicle, :pad_id,
              :mission_name, :mission_type, :orbit_target, :customer,
              :webcast_url, :image_url, :description, :associated_norad_ids,
              :now
            )
            ON CONFLICT(id) DO UPDATE SET
              name                 = excluded.name,
              net                  = excluded.net,
              status               = excluded.status,
              provider             = excluded.provider,
              vehicle              = excluded.vehicle,
              pad_id               = excluded.pad_id,
              mission_name         = excluded.mission_name,
              mission_type         = excluded.mission_type,
              orbit_target         = excluded.orbit_target,
              customer             = excluded.customer,
              webcast_url          = excluded.webcast_url,
              image_url            = excluded.image_url,
              description          = excluded.description,
              -- preserve any associated_norad_ids we already populated
              associated_norad_ids = COALESCE(launches.associated_norad_ids, excluded.associated_norad_ids),
              updated_at           = excluded.updated_at
            SQL);
    }

    private function prepare(PDO $pdo, string $sql): \PDOStatement
    {
        $stmt = $pdo->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare statement: ' . substr($sql, 0, 80));
        }
        return $stmt;
    }

    /**
     * @param array<string, mixed> $launch
     */
    private function ingestLaunch(array $launch, LaunchIngestReport $report): void
    {
        $id = self::str($launch['id'] ?? null);
        if ($id === null) {
            $report->launchesRejected++;
            return;
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');

        // Pad first (FK dependency)
        $padId = null;
        $pad = $launch['pad'] ?? null;
        if (is_array($pad) && isset($pad['id']) && is_int($pad['id'])) {
            $padId = $pad['id'];
            try {
                $this->upsertPad->execute([
                    'id'          => $padId,
                    'name'        => self::str($pad['name'] ?? '') ?? "Pad {$padId}",
                    'latitude'    => self::floatOrNull($pad['latitude'] ?? null),
                    'longitude'   => self::floatOrNull($pad['longitude'] ?? null),
                    'country'     => self::str($pad['location']['country_code'] ?? null),
                    'operator'    => self::str($pad['location']['name'] ?? null),
                    'description' => self::str($pad['location']['description'] ?? null),
                    'url'         => self::str($pad['url'] ?? null),
                    'now'         => $now,
                ]);
                $report->padsUpserted++;
            } catch (Throwable $e) {
                $this->logger->warning("LL2 pad upsert failed for {$padId}: {$e->getMessage()}");
                $padId = null; // FK would fail downstream; fall back to NULL
            }
        }

        $mission = is_array($launch['mission'] ?? null) ? $launch['mission'] : [];
        $rocket  = is_array($launch['rocket']  ?? null) ? $launch['rocket']  : [];
        $rocketCfg = is_array($rocket['configuration'] ?? null) ? $rocket['configuration'] : [];
        $provider  = is_array($launch['launch_service_provider'] ?? null) ? $launch['launch_service_provider'] : [];
        $orbit     = is_array($mission['orbit'] ?? null) ? $mission['orbit'] : [];
        $status    = is_array($launch['status'] ?? null) ? $launch['status'] : [];

        $vidUrls = $mission['vid_urls'] ?? [];
        $webcastUrl = null;
        if (is_array($vidUrls) && isset($vidUrls[0]['url']) && is_string($vidUrls[0]['url'])) {
            $webcastUrl = $vidUrls[0]['url'];
        }

        try {
            $this->upsertLaunch->execute([
                'id'                   => $id,
                'name'                 => self::str($launch['name'] ?? '') ?? $id,
                'net'                  => self::str($launch['net'] ?? '') ?? $now,
                'status'               => LL2StatusMapper::status(self::str($status['abbrev'] ?? null)),
                'provider'             => self::str($provider['name'] ?? null),
                'vehicle'              => self::str($rocketCfg['name'] ?? null),
                'pad_id'               => $padId,
                'mission_name'         => self::str($mission['name'] ?? null),
                'mission_type'         => self::str($mission['type'] ?? null),
                'orbit_target'         => self::str($orbit['name'] ?? null),
                'customer'             => self::str($mission['agencies'][0]['name'] ?? null),
                'webcast_url'          => $webcastUrl,
                'image_url'            => self::str($launch['image'] ?? null),
                'description'          => self::str($mission['description'] ?? null),
                'associated_norad_ids' => '[]',
                'now'                  => $now,
            ]);
            $report->launchesUpserted++;
        } catch (Throwable $e) {
            $report->launchesRejected++;
            $this->logger->warning("LL2 launch upsert failed for {$id}: {$e->getMessage()}");
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
