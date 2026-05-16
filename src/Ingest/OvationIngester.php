<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Phase 4 chunk 4A — orchestrates OvationClient + AuroraRasterGenerator
 * and overwrites the public aurora overlay PNG with a fresh render.
 *
 * The PNG lives at `public/textures/aurora-latest.png` and is served
 * verbatim by the chunk-4B client overlay.  Cron'd every ~15 minutes
 * (matches NOAA's OVATION update cadence); the chunk-4B layer
 * lazy-fetches it only when the user toggles the overlay on.
 *
 * Also writes a sidecar `aurora-latest.json` with the observation /
 * forecast times so the client can show "forecast for HH:MM UTC".
 */
final class OvationIngester
{
    public function __construct(
        private readonly OvationClient $client,
        private readonly AuroraRasterGenerator $generator,
        private readonly string $outputPng,
        private readonly string $outputMetaJson,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /** @return array{cells_painted: int, observation_time: string, forecast_time: string, duration_seconds: float} */
    public function run(): array
    {
        $start = microtime(true);
        try {
            $latest = $this->client->fetchLatest();
        } catch (Throwable $e) {
            $this->logger->warning('OVATION fetch failed: ' . $e->getMessage());
            throw $e;
        }

        $painted = $this->generator->generate($latest['coordinates'], $this->outputPng);

        $meta = [
            'observation_time' => $latest['observation_time'],
            'forecast_time'    => $latest['forecast_time'],
            'generated_at'     => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'cells_painted'    => $painted,
            'image_path'       => '/textures/aurora-latest.png',
        ];
        $dir = dirname($this->outputMetaJson);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create output dir {$dir}");
        }
        file_put_contents($this->outputMetaJson, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $duration = microtime(true) - $start;
        $this->logger->info('OVATION ingest complete', [
            'painted'  => $painted,
            'duration' => round($duration, 2),
        ]);

        return [
            'cells_painted'    => $painted,
            'observation_time' => $latest['observation_time'],
            'forecast_time'    => $latest['forecast_time'],
            'duration_seconds' => $duration,
        ];
    }
}
