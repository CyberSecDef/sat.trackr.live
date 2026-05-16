<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use RuntimeException;

/**
 * Phase 4 chunk 3A — fetcher for NOAA SWPC's free JSON endpoints.
 *
 *   planetary_k_index_1m.json   1-minute Kp samples (last ~6h)
 *   xrays-6-hour.json           1-minute GOES X-ray flux (0.1-0.8 nm)
 *   noaa-scales.json            current R/S/G storm scales + 1/2/3-day forecasts
 *
 * No API key; SWPC asks for "polite" use (cron at 5-minute intervals
 * is well within their etiquette).  Each endpoint returns plain JSON
 * — error responses come back as text/html which we treat as a
 * fetch failure rather than a parse failure.
 */
final class SwpcClient
{
    public const URL_KP_1M       = 'https://services.swpc.noaa.gov/json/planetary_k_index_1m.json';
    public const URL_XRAYS_6H    = 'https://services.swpc.noaa.gov/json/goes/primary/xrays-6-hour.json';
    public const URL_NOAA_SCALES = 'https://services.swpc.noaa.gov/products/noaa-scales.json';

    public function __construct(
        private readonly ClientInterface $http,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function fetchKp1m(): array
    {
        return $this->fetchJsonList(self::URL_KP_1M);
    }

    /** @return list<array<string, mixed>> */
    public function fetchXrays6h(): array
    {
        return $this->fetchJsonList(self::URL_XRAYS_6H);
    }

    /** @return array<string, mixed>   keys are stringified indices "0", "1", … */
    public function fetchNoaaScales(): array
    {
        $body = $this->fetchBody(self::URL_NOAA_SCALES);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('SWPC noaa-scales returned non-array JSON');
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchJsonList(string $url): array
    {
        $body = $this->fetchBody($url);
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new RuntimeException("SWPC {$url} returned non-list JSON");
        }
        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
    }

    private function fetchBody(string $url): string
    {
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (BadResponseException $e) {
            $code = $e->getResponse()->getStatusCode();
            $body = substr((string) $e->getResponse()->getBody(), 0, 200);
            throw new RuntimeException("SWPC fetch failed ({$code}) for {$url}: {$body}", 0, $e);
        }
        $body = (string) $response->getBody();
        if ($body === '') {
            throw new RuntimeException("SWPC returned empty body for {$url}");
        }
        return $body;
    }
}
