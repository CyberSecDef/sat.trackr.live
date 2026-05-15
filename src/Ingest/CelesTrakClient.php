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
        $body = $this->request($group, 'TLE');
        if ($body === '' || str_starts_with(strtolower(ltrim($body)), 'no gp data found')) {
            throw new RuntimeException("CelesTrak returned no data for group '{$group}'.");
        }
        return $body;
    }

    /**
     * Fetch the same group as a list of OMM JSON records.  Used by the
     * Phase 2 chunk 7 OMM ingest path; the legacy {@see fetchGroup}
     * stays as a fallback while we cut over.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchGroupJson(string $group): array
    {
        $body = $this->request($group, 'JSON');
        $trimmed = ltrim($body);
        if ($trimmed === '') {
            throw new RuntimeException("CelesTrak returned no data for group '{$group}'.");
        }
        if ($trimmed[0] !== '[' && $trimmed[0] !== '{') {
            // Some retired groups (noaa, iridium, swarm, etc.) return a
            // plain-text "GROUP \"x\" does not exist" body.  Treat as empty.
            return [];
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("CelesTrak returned non-array JSON for group '{$group}'");
        }
        if (!array_is_list($decoded)) {
            // A single-record response is wrapped in {} rather than [{}].
            $decoded = [$decoded];
        }
        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
    }

    private function request(string $group, string $format): string
    {
        try {
            $response = $this->http->request('GET', self::URL, [
                'query' => ['GROUP' => $group, 'FORMAT' => $format],
            ]);
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            if ($response->getStatusCode() === 403) {
                $body = (string) $response->getBody();
                if (stripos($body, 'has not updated') !== false) {
                    throw new NotModifiedException("Group '{$group}' not updated since last fetch");
                }
            }
            throw $e;
        }
        return (string) $response->getBody();
    }
}
