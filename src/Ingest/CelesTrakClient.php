<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use RuntimeException;

/**
 * Thin wrapper around the CelesTrak GP endpoint. Returns the raw TLE text
 * for a group (3 lines per object: name + line1 + line2).
 *
 * We use FORMAT=TLE for now. After NORAD IDs cross 6 digits (~July 2026
 * per CelesTrak's announcement) we'll need to switch to FORMAT=JSON since
 * the legacy TLE format only encodes 5-digit catalog numbers.
 */
final class CelesTrakClient
{
    private const URL = 'https://celestrak.org/NORAD/elements/gp.php';

    public function __construct(
        private readonly ClientInterface $http,
    ) {
    }

    public function fetchGroup(string $group): string
    {
        try {
            $response = $this->http->request('GET', self::URL, [
                'query' => ['GROUP' => $group, 'FORMAT' => 'TLE'],
            ]);
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            // CelesTrak signals "no update since last fetch" with a 403 +
            // body starting with "GP data has not updated". Map that to
            // NotModifiedException so the ingester can treat it as success.
            if ($response->getStatusCode() === 403) {
                $body = (string) $response->getBody();
                if (stripos($body, 'has not updated') !== false) {
                    throw new NotModifiedException("Group '{$group}' not updated since last fetch");
                }
            }
            throw $e;
        }

        $body = (string) $response->getBody();
        if ($body === '' || str_starts_with(strtolower(ltrim($body)), 'no gp data found')) {
            throw new RuntimeException("CelesTrak returned no data for group '{$group}'.");
        }
        return $body;
    }
}
