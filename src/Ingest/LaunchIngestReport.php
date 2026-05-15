<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

/**
 * Per-run stats for a Launch Library 2 ingest pass.
 */
final class LaunchIngestReport
{
    public int $upcomingFetched = 0;
    public int $previousFetched = 0;
    public int $launchesUpserted = 0;
    public int $padsUpserted = 0;
    public int $launchesRejected = 0;

    /** @var list<array{mode: string, error: string}> */
    public array $errors = [];

    public float $startedAt;
    public float $finishedAt = 0.0;

    public function __construct()
    {
        $this->startedAt = microtime(true);
    }

    public function recordError(string $mode, string $error): void
    {
        $this->errors[] = ['mode' => $mode, 'error' => $error];
    }

    public function finish(): void
    {
        $this->finishedAt = microtime(true);
    }

    public function durationSeconds(): float
    {
        $end = $this->finishedAt > 0 ? $this->finishedAt : microtime(true);
        return $end - $this->startedAt;
    }

    /**
     * @return array<string, scalar>
     */
    public function toLogContext(): array
    {
        return [
            'upcoming_fetched'  => $this->upcomingFetched,
            'previous_fetched'  => $this->previousFetched,
            'launches_upserted' => $this->launchesUpserted,
            'pads_upserted'     => $this->padsUpserted,
            'launches_rejected' => $this->launchesRejected,
            'errors'            => count($this->errors),
            'duration_seconds'  => round($this->durationSeconds(), 2),
        ];
    }
}
