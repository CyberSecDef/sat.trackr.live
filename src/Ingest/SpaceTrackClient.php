<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Exception\BadResponseException;
use RuntimeException;

/**
 * Cookie-jar Guzzle wrapper around www.space-track.org.
 *
 * Auth flow: POST /ajaxauth/login with `identity` + `password` form fields.
 * On success Space-Track sets a `chocolatechip` cookie which the jar
 * carries forward into subsequent /basicspacedata/query/... GETs. We
 * log in lazily on the first query and reuse the session for the rest
 * of the ingest run.
 *
 * The class accepts a Guzzle ClientInterface so tests can pass a
 * MockHandler-backed client and assert on the exact request stream.
 *
 * Usage:
 *   $client = new SpaceTrackClient($guzzle, 'me@example.com', 'secret');
 *   $rows   = $client->query('class/tip/orderby/INSERT_EPOCH desc');
 */
final class SpaceTrackClient
{
    public const BASE = 'https://www.space-track.org';

    private bool $loggedIn = false;
    private CookieJarInterface $jar;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $username,
        private readonly string $password,
        ?CookieJarInterface $jar = null,
    ) {
        $this->jar = $jar ?? new CookieJar();
    }

    /**
     * Run a basicspacedata query. The path is everything after
     * `/basicspacedata/query/`, e.g.  `class/tip/orderby/INSERT_EPOCH desc`.
     * `format/json` is appended automatically if not already present.
     *
     * @return list<array<string, mixed>>
     */
    public function query(string $path): array
    {
        $this->loginIfNeeded();

        if (!str_contains($path, 'format/')) {
            $path = rtrim($path, '/') . '/format/json';
        }
        $url = self::BASE . '/basicspacedata/query/' . ltrim($path, '/');

        try {
            $response = $this->http->request('GET', $url, [
                'cookies' => $this->jar,
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (BadResponseException $e) {
            $code = $e->getResponse()->getStatusCode();
            $body = substr((string) $e->getResponse()->getBody(), 0, 200);
            throw new RuntimeException("Space-Track query failed ({$code}) for {$path}: {$body}", 0, $e);
        }

        $body    = (string) $response->getBody();
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Space-Track returned non-JSON for {$path}: " . substr($body, 0, 200));
        }
        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
    }

    private function loginIfNeeded(): void
    {
        if ($this->loggedIn) {
            return;
        }
        if ($this->username === '' || $this->password === '') {
            throw new RuntimeException('SPACE_TRACK_USER / SPACE_TRACK_PASS are required to query Space-Track');
        }

        try {
            $this->http->request('POST', self::BASE . '/ajaxauth/login', [
                'cookies'     => $this->jar,
                'form_params' => [
                    'identity' => $this->username,
                    'password' => $this->password,
                ],
            ]);
        } catch (BadResponseException $e) {
            $code = $e->getResponse()->getStatusCode();
            throw new RuntimeException("Space-Track login failed ({$code})", 0, $e);
        }

        $this->loggedIn = true;
    }

    /** Test affordance: lets a feature test pre-seed cookies. */
    public function jar(): CookieJarInterface
    {
        return $this->jar;
    }
}
