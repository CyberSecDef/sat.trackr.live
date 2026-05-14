<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

/**
 * Per-run stats for a SATCAT ingest pass. Same shape spirit as IngestReport
 * but with the SATCAT-specific counters (no TLE history concept here, but
 * we track records seen vs. satellites actually touched, plus the
 * purposes-derivation pass at the end).
 */
final class SatCatReport
{
    public int $groupsProcessed = 0;
    public int $groupsSkippedNotModified = 0;
    public int $recordsSeen = 0;
    /** SATCAT records whose NORAD ID exists in the satellites table — UPDATE'd. */
    public int $satellitesUpdated = 0;
    /** SATCAT records whose NORAD ID is NOT in satellites — skipped. */
    public int $satellitesUnknown = 0;
    public int $purposesDerived = 0;

    /** @var list<array{group: string, error: string}> */
    public array $errors = [];

    public float $startedAt;
    public float $finishedAt = 0.0;

    public function __construct()
    {
        $this->startedAt = microtime(true);
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
            'groups_processed'            => $this->groupsProcessed,
            'groups_skipped_not_modified' => $this->groupsSkippedNotModified,
            'records_seen'                => $this->recordsSeen,
            'satellites_updated'          => $this->satellitesUpdated,
            'satellites_unknown'          => $this->satellitesUnknown,
            'purposes_derived'            => $this->purposesDerived,
            'errors'                      => count($this->errors),
            'duration_seconds'            => round($this->durationSeconds(), 2),
        ];
    }
}
