<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

/**
 * Per-run stats for a Space-Track TIP / reentry ingest pass.
 */
final class ReentryIngestReport
{
    public int $tipsFetched = 0;
    public int $reentriesUpserted = 0;
    public int $skippedUnknownNorad = 0;
    public int $skippedMalformed = 0;

    /** @var list<array{stage: string, error: string}> */
    public array $errors = [];

    public float $startedAt;
    public float $finishedAt = 0.0;

    public function __construct()
    {
        $this->startedAt = microtime(true);
    }

    public function recordError(string $stage, string $error): void
    {
        $this->errors[] = ['stage' => $stage, 'error' => $error];
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

    /** @return array<string, scalar> */
    public function toLogContext(): array
    {
        return [
            'tips_fetched'          => $this->tipsFetched,
            'reentries_upserted'    => $this->reentriesUpserted,
            'skipped_unknown_norad' => $this->skippedUnknownNorad,
            'skipped_malformed'     => $this->skippedMalformed,
            'errors'                => count($this->errors),
            'duration_seconds'      => round($this->durationSeconds(), 2),
        ];
    }
}
