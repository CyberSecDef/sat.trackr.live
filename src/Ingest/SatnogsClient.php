<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use RuntimeException;

/**
 * Phase 5 chunk 1A — fetcher for the SatNOGS DB transmitter catalog.
 *
 *   GET https://db.satnogs.org/api/transmitters/?format=json
 *
 * Returns the full catalog as a single un-paginated JSON array
 * (~4,900 rows / 3.4 MB as of this writing).  No API key, no
 * pagination dance.  Refreshed weekly is plenty — amateur radio
 * frequencies don't shift often, and the cron'd ingest is idempotent.
 */
final class SatnogsClient
{
    public const URL = 'https://db.satnogs.org/api/transmitters/?format=json';

    public function __construct(
        private readonly ClientInterface $http,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function fetchAllTransmitters(): array
    {
        try {
            $response = $this->http->request('GET', self::URL, [
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (BadResponseException $e) {
            $code = $e->getResponse()->getStatusCode();
            $body = substr((string) $e->getResponse()->getBody(), 0, 200);
            throw new RuntimeException("SatNOGS fetch failed ({$code}): {$body}", 0, $e);
        }

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new RuntimeException('SatNOGS returned non-list JSON');
        }
        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
    }
}
