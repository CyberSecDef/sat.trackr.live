<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

/**
 * Accumulates per-run stats for an ingest pass. Logged at completion;
 * also returned to the caller for CLI summary output.
 */
final class IngestReport
{
    public int $groupsProcessed = 0;
    public int $satellitesUpserted = 0;
    public int $tleCurrentUpserted = 0;
    public int $tleHistoryAdded = 0;
    public int $tleRejected = 0;

    /** @var list<array{group: string, norad: int|null, reason: string}> */
    public array $rejects = [];

    /** @var list<array{group: string, error: string}> */
    public array $errors = [];

    public float $startedAt;
    public float $finishedAt = 0.0;

    public function __construct()
    {
        $this->startedAt = microtime(true);
    }

    public function recordReject(string $group, ?int $norad, string $reason): void
    {
        $this->tleRejected++;
        $this->rejects[] = ['group' => $group, 'norad' => $norad, 'reason' => $reason];
    }

    public function recordError(string $group, string $error): void
    {
        $this->errors[] = ['group' => $group, 'error' => $error];
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
            'groups_processed'    => $this->groupsProcessed,
            'satellites_upserted' => $this->satellitesUpserted,
            'tle_current_upserted'=> $this->tleCurrentUpserted,
            'tle_history_added'   => $this->tleHistoryAdded,
            'tle_rejected'        => $this->tleRejected,
            'errors'              => count($this->errors),
            'duration_seconds'    => round($this->durationSeconds(), 2),
        ];
    }
}
