<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use RuntimeException;

/**
 * Phase 4 chunk 1B — fetcher for the CelesTrak SOCRATES Plus
 * "sort-minRange.csv" download.  The file contains every active
 * close-approach prediction sorted by minimum TCA range, ~16MB,
 * refreshed by CelesTrak roughly every 8 hours.
 *
 *   https://celestrak.org/SOCRATES/sort-minRange.csv
 *
 * Plan/spec swerve, justified in the commit message: chunk 4
 * §II row 2 locked "Full HTML scrape" because at planning time
 * I assumed SOCRATES only published HTML reports.  Probing the
 * live site found this clean CSV — same data, simpler + more
 * robust parser.  Going with CSV.
 */
final class SocratesClient
{
    public const URL = 'https://celestrak.org/SOCRATES/sort-minRange.csv';

    public function __construct(
        private readonly ClientInterface $http,
    ) {
    }

    /** Fetch the raw CSV body. */
    public function fetchMinRangeCsv(): string
    {
        try {
            $response = $this->http->request('GET', self::URL, [
                'headers' => ['Accept' => 'text/csv,application/octet-stream'],
            ]);
        } catch (BadResponseException $e) {
            $code = $e->getResponse()->getStatusCode();
            $body = substr((string) $e->getResponse()->getBody(), 0, 200);
            throw new RuntimeException("SOCRATES fetch failed ({$code}): {$body}", 0, $e);
        }

        $body = (string) $response->getBody();
        if ($body === '') {
            throw new RuntimeException('SOCRATES returned empty body');
        }
        return $body;
    }
}
