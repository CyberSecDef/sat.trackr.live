<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SatTrackr\Database\Connection;
use Throwable;

/**
 * Phase 4 chunk 3A — fetches the three SWPC payloads, normalizes the
 * latest reading from each, and inserts one row into
 * `space_weather_samples`.  Cron'd every ~5 minutes; the chunk-3B
 * "now" endpoint reads the latest row, the "24h" endpoint reads the
 * trailing 288 rows for the topbar popover's SVG chart.
 *
 * The X-ray flux → flare class mapping is the standard NOAA scheme:
 *   < 1e-7    → 'A'
 *   < 1e-6    → 'B'
 *   < 1e-5    → 'C'
 *   < 1e-4    → 'M'
 *   >= 1e-4   → 'X'
 */
final class SwpcIngester
{
    public function __construct(
        private readonly SwpcClient $client,
        private readonly Connection $db,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return array<string, mixed>  the inserted sample (handy for tests + the CLI summary)
     */
    public function run(): array
    {
        $kpRaw     = $this->safe(fn () => $this->client->fetchKp1m(),     'kp');
        $xrayRaw   = $this->safe(fn () => $this->client->fetchXrays6h(),  'xray');
        $scalesRaw = $this->safe(fn () => $this->client->fetchNoaaScales(),'scales');

        $kpLatest    = $this->latestKp($kpRaw);
        $xrayLatest  = $this->latestXrayFlux($xrayRaw);
        $scalesNow   = is_array($scalesRaw['0'] ?? null) ? $scalesRaw['0'] : null;

        $sample = [
            'sampled_at'  => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'kp'          => $kpLatest,
            'x_ray_flux'  => $xrayLatest,
            'x_ray_class' => $xrayLatest !== null ? self::xrayClass($xrayLatest) : null,
            'r_level'     => $scalesNow !== null ? self::scaleInt($scalesNow['R']['Scale'] ?? null) : null,
            's_level'     => $scalesNow !== null ? self::scaleInt($scalesNow['S']['Scale'] ?? null) : null,
            'g_level'     => $scalesNow !== null ? self::scaleInt($scalesNow['G']['Scale'] ?? null) : null,
            'raw_message' => json_encode([
                'kp_raw'     => $kpRaw[count($kpRaw) - 1] ?? null,
                'xray_raw'   => $xrayRaw[count($xrayRaw) - 1] ?? null,
                'scales_raw' => $scalesNow,
            ], JSON_UNESCAPED_SLASHES),
            'created_at'  => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ];

        $stmt = $this->db->pdo()->prepare(<<<'SQL'
            INSERT INTO space_weather_samples
              (sampled_at, kp, x_ray_flux, x_ray_class, r_level, s_level, g_level, raw_message, created_at)
            VALUES
              (:sampled_at, :kp, :x_ray_flux, :x_ray_class, :r_level, :s_level, :g_level, :raw_message, :created_at)
            SQL);
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare SWPC insert');
        }
        $stmt->execute($sample);

        $this->logger->info('SWPC sample stored', [
            'kp'    => $kpLatest,
            'xray'  => $xrayLatest,
            'class' => $sample['x_ray_class'],
            'g'     => $sample['g_level'],
        ]);
        return $sample;
    }

    /**
     * @template T
     * @param callable():T $thunk
     * @return T|list<array<string,mixed>>|array<string,mixed>
     */
    private function safe(callable $thunk, string $label)
    {
        try {
            return $thunk();
        } catch (Throwable $e) {
            $this->logger->warning("SWPC {$label} fetch failed: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function latestKp(array $rows): ?float
    {
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $kp = $rows[$i]['estimated_kp'] ?? $rows[$i]['kp_index'] ?? null;
            if (is_numeric($kp)) {
                return (float) $kp;
            }
        }
        return null;
    }

    /**
     * Returns the latest valid GOES short-band X-ray flux (W/m²).
     * The feed mixes long-band + short-band on alternating samples
     * via the `energy` field; only the 0.1-0.8 nm rows are useful
     * for flare classification.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function latestXrayFlux(array $rows): ?float
    {
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            if (($rows[$i]['energy'] ?? null) !== '0.1-0.8nm') {
                continue;
            }
            $flux = $rows[$i]['flux'] ?? null;
            if (is_numeric($flux)) {
                return (float) $flux;
            }
        }
        return null;
    }

    public static function xrayClass(float $flux): string
    {
        if ($flux >= 1e-4) return 'X';
        if ($flux >= 1e-5) return 'M';
        if ($flux >= 1e-6) return 'C';
        if ($flux >= 1e-7) return 'B';
        return 'A';
    }

    private static function scaleInt(mixed $v): ?int
    {
        if (is_int($v)) return $v;
        if (is_string($v) && ctype_digit($v)) return (int) $v;
        return null;
    }
}
