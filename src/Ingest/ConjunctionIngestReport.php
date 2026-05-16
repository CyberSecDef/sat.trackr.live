<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

/** Per-run stats for a SOCRATES / conjunctions ingest pass. */
final class ConjunctionIngestReport
{
    public int $rowsFetched = 0;
    public int $rowsParsed = 0;
    public int $upserted = 0;
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
            'rows_fetched'      => $this->rowsFetched,
            'rows_parsed'       => $this->rowsParsed,
            'upserted'          => $this->upserted,
            'skipped_malformed' => $this->skippedMalformed,
            'errors'            => count($this->errors),
            'duration_seconds'  => round($this->durationSeconds(), 2),
        ];
    }
}
