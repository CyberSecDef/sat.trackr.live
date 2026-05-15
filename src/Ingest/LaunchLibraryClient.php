<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use RuntimeException;

/**
 * Thin Guzzle wrapper around Launch Library 2 (ll.thespacedevs.com/2.2.0/).
 *
 * Auth: paid personal tier ($5/mo) sends `Authorization: Token {token}`.
 * Free tier (no token) is rate-limited to 15 req/hr — should still work
 * but production deploy assumes the paid token in $LL2_API_TOKEN.
 *
 * Endpoints used by chunk 3:
 *   GET /launch/upcoming/?limit=50
 *   GET /launch/previous/?limit=100&ordering=-net
 *
 * All responses are paginated `{count, next, previous, results: [...]}`.
 */
final class LaunchLibraryClient
{
    private const BASE = 'https://ll.thespacedevs.com/2.2.0';

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $apiToken = '',
    ) {
    }

    /**
     * @return list<array<string, mixed>>  the `results` array
     */
    public function fetchUpcoming(int $limit = 50): array
    {
        return $this->fetchList('/launch/upcoming/', ['limit' => $limit, 'mode' => 'detailed']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchPrevious(int $limit = 100): array
    {
        return $this->fetchList('/launch/previous/', [
            'limit'    => $limit,
            'ordering' => '-net',
            'mode'     => 'detailed',
        ]);
    }

    /**
     * @param array<string, scalar> $query
     * @return list<array<string, mixed>>
     */
    private function fetchList(string $path, array $query): array
    {
        try {
            $response = $this->http->request('GET', self::BASE . $path, [
                'query'   => $query,
                'headers' => $this->headers(),
            ]);
        } catch (BadResponseException $e) {
            throw new RuntimeException(
                "LL2 fetch failed for {$path} ({$e->getResponse()->getStatusCode()}): "
                . substr((string) $e->getResponse()->getBody(), 0, 200),
                0,
                $e,
            );
        }

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['results']) || !is_array($decoded['results'])) {
            throw new RuntimeException("LL2 returned unexpected shape for {$path}");
        }
        /** @var list<array<string, mixed>> $results */
        $results = $decoded['results'];
        return $results;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $h = ['Accept' => 'application/json'];
        if ($this->apiToken !== '') {
            $h['Authorization'] = 'Token ' . $this->apiToken;
        }
        return $h;
    }
}
