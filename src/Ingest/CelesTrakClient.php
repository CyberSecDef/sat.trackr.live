<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

use GuzzleHttp\ClientInterface;
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
        $response = $this->http->request('GET', self::URL, [
            'query' => ['GROUP' => $group, 'FORMAT' => 'TLE'],
        ]);

        $body = (string) $response->getBody();
        if ($body === '' || str_starts_with(strtolower(ltrim($body)), 'no gp data found')) {
            throw new RuntimeException("CelesTrak returned no data for group '{$group}'.");
        }
        return $body;
    }
}
