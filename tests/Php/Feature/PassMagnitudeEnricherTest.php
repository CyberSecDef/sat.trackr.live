<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Feature;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SatTrackr\Services\N2YOClient;
use SatTrackr\Services\PassMagnitudeEnricher;

final class PassMagnitudeEnricherTest extends TestCase
{
    private string $stateFile = '';

    protected function setUp(): void
    {
        $this->stateFile = tempnam(sys_get_temp_dir(), 'pme-state-') . '.json';
        if (file_exists($this->stateFile)) unlink($this->stateFile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->stateFile)) @unlink($this->stateFile);
    }

    /** @param list<Response> $responses */
    private function enricher(array $responses, string $apiKey = 'fake'): PassMagnitudeEnricher
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $n2yo = new N2YOClient(new GuzzleClient(['handler' => $stack]), $apiKey, $this->stateFile);
        return new PassMagnitudeEnricher($n2yo);
    }

    /** @return list<array<string, mixed>> */
    private function sgp4Passes(): array
    {
        // Two SGP4 passes — both have peak times that should match N2YO entries below.
        return [
            ['rise_at' => '2026-05-20T00:00:00Z', 'peak_at' => '2026-05-20T00:05:00Z', 'set_at' => '2026-05-20T00:10:00Z', 'max_elevation_deg' => 45],
            ['rise_at' => '2026-05-20T01:30:00Z', 'peak_at' => '2026-05-20T01:35:00Z', 'set_at' => '2026-05-20T01:40:00Z', 'max_elevation_deg' => 60],
        ];
    }

    private function n2yoBody(int $peak1Utc, ?float $mag1, int $peak2Utc, ?float $mag2, int $txn = 5): string
    {
        $passes = [];
        if ($mag1 !== null) $passes[] = ['maxUTC' => $peak1Utc, 'mag' => $mag1];
        if ($mag2 !== null) $passes[] = ['maxUTC' => $peak2Utc, 'mag' => $mag2];
        return json_encode([
            'info'   => ['transactionscount' => $txn, 'passescount' => count($passes)],
            'passes' => $passes,
        ]) ?: '{}';
    }

    public function testMergesMagnitudesByClosestPeakTime(): void
    {
        $peak1 = strtotime('2026-05-20T00:05:00Z');
        $peak2 = strtotime('2026-05-20T01:35:00Z');
        $body = $this->n2yoBody($peak1, 2.4, $peak2, 0.1);
        $out = $this->enricher([new Response(200, [], $body)])
            ->enrich($this->sgp4Passes(), 25544, 40.7128, -74.0060, 0, 3);

        $this->assertEqualsWithDelta(2.4, $out[0]['magnitude'], 1e-6);
        $this->assertEqualsWithDelta(0.1, $out[1]['magnitude'], 1e-6);
    }

    public function testLeavesMagnitudeNullWhenN2YOHasNoMatchingPass(): void
    {
        // N2YO returns only the second pass; first stays null.
        $peak2 = strtotime('2026-05-20T01:35:00Z');
        $body = $this->n2yoBody(0, null, $peak2, 0.5);
        $out = $this->enricher([new Response(200, [], $body)])
            ->enrich($this->sgp4Passes(), 25544, 40.7128, -74.0060, 0, 3);

        $this->assertNull($out[0]['magnitude']);
        $this->assertEqualsWithDelta(0.5, $out[1]['magnitude'], 1e-6);
    }

    public function testDoesNotMatchWhenPeakIsBeyond60Seconds(): void
    {
        // N2YO peak shifted by 5 minutes from our SGP4 peak.
        $shifted = strtotime('2026-05-20T00:10:00Z');     // 5 min after our 00:05 peak
        $body = $this->n2yoBody($shifted, 2.4, $shifted + 60_000, 0.1);
        $out = $this->enricher([new Response(200, [], $body)])
            ->enrich($this->sgp4Passes(), 25544, 40.7128, -74.0060, 0, 3);
        $this->assertNull($out[0]['magnitude']);
        $this->assertNull($out[1]['magnitude']);
    }

    public function testMissingApiKeyReturnsPassesUnmodifiedWithNullMagnitude(): void
    {
        $out = $this->enricher([], apiKey: '')
            ->enrich($this->sgp4Passes(), 25544, 40.7128, -74.0060, 0, 3);

        // Every pass has a magnitude key (UI shape stable) but the value is null.
        foreach ($out as $p) {
            $this->assertArrayHasKey('magnitude', $p);
            $this->assertNull($p['magnitude']);
        }
    }

    public function testEmptyPassesShortCircuits(): void
    {
        // Should NOT call N2YO if there are no SGP4 passes to enrich.
        $out = $this->enricher([])->enrich([], 25544, 40, -74, 0, 3);
        $this->assertSame([], $out);
    }
}
