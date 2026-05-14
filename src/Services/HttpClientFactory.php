<?php

declare(strict_types=1);

namespace SatTrackr\Services;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Builds a Guzzle HTTP client with sensible defaults for sat.trackr.live's
 * outbound calls (CelesTrak, future Space-Track, LL2, NOAA SWPC).
 *
 * - 30s read timeout, 10s connect timeout
 * - Up to 3 retries on 5xx, 429, or transport-level exceptions
 * - Exponential backoff (1s, 2s, 4s)
 * - Identifying User-Agent so upstream can contact us if we misbehave
 */
final class HttpClientFactory
{
    public function __construct(
        private readonly string $userAgent = 'sat.trackr.live/0.1 (+https://sat.trackr.live)',
        private readonly float $timeoutSeconds = 30.0,
        private readonly float $connectTimeoutSeconds = 10.0,
        private readonly int $maxRetries = 3,
    ) {
    }

    public function create(): Client
    {
        $stack = HandlerStack::create();
        $stack->push(Middleware::retry($this->retryDecider(), $this->retryDelay()));

        return new Client([
            'timeout'         => $this->timeoutSeconds,
            'connect_timeout' => $this->connectTimeoutSeconds,
            'handler'         => $stack,
            'http_errors'     => true,
            'headers'         => [
                'User-Agent' => $this->userAgent,
                'Accept'     => '*/*',
            ],
        ]);
    }

    private function retryDecider(): Closure
    {
        $maxRetries = $this->maxRetries;
        return static function (
            int $retries,
            RequestInterface $_request,
            ?ResponseInterface $response = null,
            ?Throwable $exception = null,
        ) use ($maxRetries): bool {
            if ($retries >= $maxRetries) {
                return false;
            }
            if ($exception !== null) {
                return true; // transport / DNS / connect errors
            }
            if ($response !== null) {
                $code = $response->getStatusCode();
                return $code >= 500 || $code === 429;
            }
            return false;
        };
    }

    private function retryDelay(): Closure
    {
        return static function (int $retries): int {
            // Exponential: 1000ms, 2000ms, 4000ms
            return 1000 * (2 ** ($retries - 1));
        };
    }
}
