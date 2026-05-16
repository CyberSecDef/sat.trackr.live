<?php

declare(strict_types=1);

namespace SatTrackr\Services;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Phase 4 chunk 7A — read-through wrapper around N2YO's free-tier
 * visualpasses endpoint, with a daily quota guard so we never blow
 * past the published 1000-req/hr limit.
 *
 *   GET /rest/v1/satellite/visualpasses/{norad}/{lat}/{lng}/{alt}/{days}/{min_visibility}
 *
 * The endpoint conveniently returns `info.transactionscount` (the
 * running 24h count for the calling API key), which we mirror into
 * a small state file so the guard stays accurate across processes.
 * If we don't have a key configured, calls degrade silently — the
 * caller (PassCalculator enrichment) treats null returns as "no
 * magnitude data this pass, sorry".
 *
 * Quota is capped at QUOTA_LIMIT/day (default 800, leaves headroom
 * under N2YO's 1000/day informal cap so other features can use the
 * same key).
 */
final class N2YOClient
{
    public const QUOTA_LIMIT = 800;
    public const URL_BASE    = 'https://api.n2yo.com/rest/v1/satellite/visualpasses';
    private const STATE_TTL_SECONDS = 24 * 3600;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $apiKey,
        private readonly string $stateFilePath,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?\Closure $clock = null,
    ) {
    }

    /**
     * Fetch visual passes for the given satellite + observer.  Returns
     * `null` when the API key is missing, the quota is exhausted, or
     * the call errors out — the caller is expected to degrade silently.
     *
     * @return array{
     *   passes: list<array<string, mixed>>,
     *   transactions_today: int,
     * }|null
     */
    public function fetchVisualPasses(
        int $norad,
        float $lat,
        float $lon,
        float $altMeters,
        int $days = 7,
        int $minVisibilitySeconds = 30,
    ): ?array {
        if ($this->apiKey === '') {
            return null;
        }
        if ($this->isOverQuota()) {
            $this->logger->info('N2YO call skipped — over daily quota cap');
            return null;
        }

        $altKm = max(0.0, $altMeters / 1000.0);
        $url = sprintf(
            '%s/%d/%.4f/%.4f/%.1f/%d/%d&apiKey=%s',
            self::URL_BASE, $norad, $lat, $lon, $altKm, $days, $minVisibilitySeconds, $this->apiKey,
        );

        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (BadResponseException $e) {
            $code = $e->getResponse()->getStatusCode();
            $this->logger->warning("N2YO HTTP {$code}");
            $this->bumpQuota(1);            // a 4xx still counts as a call
            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('N2YO call failed: ' . $e->getMessage());
            return null;
        }

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $this->logger->warning('N2YO returned non-JSON body');
            $this->bumpQuota(1);
            return null;
        }

        $passes = is_array($decoded['passes'] ?? null) ? $decoded['passes'] : [];
        $txn    = (int) ($decoded['info']['transactionscount'] ?? 0);

        // Sync with the server-reported counter — it's the source of
        // truth.  Falls back to our local +1 increment if N2YO doesn't
        // return a count (some error responses omit info).
        if ($txn > 0) {
            $this->setQuotaTo($txn);
        } else {
            $this->bumpQuota(1);
        }

        /** @var list<array<string, mixed>> $passes */
        return [
            'passes'             => $passes,
            'transactions_today' => max($txn, $this->readState()['count']),
        ];
    }

    /** @return array{count: int, day: string} */
    public function readState(): array
    {
        $now = $this->now();
        $today = gmdate('Y-m-d', $now);

        if (!is_file($this->stateFilePath)) {
            return ['count' => 0, 'day' => $today];
        }
        $raw = @file_get_contents($this->stateFilePath);
        if ($raw === false) {
            return ['count' => 0, 'day' => $today];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['count' => 0, 'day' => $today];
        }
        $day = (string) ($decoded['day'] ?? '');
        if ($day !== $today) {
            // New UTC day — counter resets.
            return ['count' => 0, 'day' => $today];
        }
        return ['count' => (int) ($decoded['count'] ?? 0), 'day' => $today];
    }

    public function isOverQuota(): bool
    {
        return $this->readState()['count'] >= self::QUOTA_LIMIT;
    }

    private function bumpQuota(int $by): void
    {
        $state = $this->readState();
        $this->setQuotaTo($state['count'] + $by);
    }

    private function setQuotaTo(int $count): void
    {
        $today = gmdate('Y-m-d', $this->now());
        $payload = ['count' => max(0, $count), 'day' => $today];
        $dir = dirname($this->stateFilePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        @file_put_contents($this->stateFilePath, json_encode($payload) ?: '{}');
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
