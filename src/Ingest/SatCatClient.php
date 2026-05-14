<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use RuntimeException;

/**
 * Thin Guzzle wrapper around CelesTrak's SATCAT JSON endpoint.
 *
 * Per req_spec §4.1, SATCAT lives at
 *   https://celestrak.org/satcat/records.php?GROUP={slug}&FORMAT=JSON
 * and exposes per-object metadata (operator/country/launch_date/RCS/status)
 * complementary to the GP feed.
 *
 * Returns the parsed JSON array directly. Honors CelesTrak's polite
 * 403 + "not modified" response the same way as CelesTrakClient.
 */
final class SatCatClient
{
    private const URL = 'https://celestrak.org/satcat/records.php';

    public function __construct(
        private readonly ClientInterface $http,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchGroup(string $group): array
    {
        try {
            $response = $this->http->request('GET', self::URL, [
                'query' => ['GROUP' => $group, 'FORMAT' => 'JSON'],
            ]);
        } catch (BadResponseException $e) {
            $r = $e->getResponse();
            if ($r->getStatusCode() === 403) {
                $body = (string) $r->getBody();
                if (stripos($body, 'has not updated') !== false) {
                    throw new NotModifiedException("SATCAT group '{$group}' not updated since last fetch");
                }
            }
            throw $e;
        }

        $body = (string) $response->getBody();
        if (trim($body) === '' || str_starts_with(strtolower(ltrim($body)), 'no satcat data found')) {
            throw new RuntimeException("CelesTrak SATCAT returned no data for group '{$group}'.");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("CelesTrak SATCAT returned non-array JSON for group '{$group}'.");
        }
        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
    }
}
