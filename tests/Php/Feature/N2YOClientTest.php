<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Feature;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SatTrackr\Services\N2YOClient;

final class N2YOClientTest extends TestCase
{
    private string $stateFile = '';

    protected function setUp(): void
    {
        $this->stateFile = tempnam(sys_get_temp_dir(), 'n2yo-state-') . '.json';
        if (file_exists($this->stateFile)) {
            unlink($this->stateFile);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->stateFile)) {
            @unlink($this->stateFile);
        }
    }

    /** @param list<Response> $responses */
    private function client(array $responses, string $apiKey = 'fakekey'): N2YOClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        return new N2YOClient(
            http:          new GuzzleClient(['handler' => $stack]),
            apiKey:        $apiKey,
            stateFilePath: $this->stateFile,
        );
    }

    public function testFetchReturnsPassesAndSyncsCounterFromN2YOResponse(): void
    {
        $body = json_encode([
            'info' => ['transactionscount' => 42, 'passescount' => 1],
            'passes' => [[
                'startUTC' => 1778980240, 'maxUTC' => 1778980515, 'endUTC' => 1778980790,
                'maxEl' => 12.93, 'mag' => 0.1,
            ]],
        ]) ?: '{}';
        $result = $this->client([new Response(200, [], $body)])
            ->fetchVisualPasses(25544, 40.7128, -74.0060, 0, 3);

        $this->assertNotNull($result);
        $this->assertCount(1, $result['passes']);
        $this->assertSame(42, $result['transactions_today']);

        // State file mirrors the server-reported counter.
        $state = json_decode((string) file_get_contents($this->stateFile), true);
        $this->assertSame(42, $state['count']);
    }

    public function testMissingApiKeyReturnsNullWithoutAnyHttp(): void
    {
        $client = $this->client([], apiKey: '');
        $this->assertNull($client->fetchVisualPasses(25544, 40, -74, 0));
        $this->assertFalse(file_exists($this->stateFile));
    }

    public function testOverQuotaSkipsTheCall(): void
    {
        file_put_contents($this->stateFile, json_encode(['count' => N2YOClient::QUOTA_LIMIT, 'day' => gmdate('Y-m-d')]));
        $client = $this->client([]);    // empty handler → would throw if called
        $this->assertNull($client->fetchVisualPasses(25544, 40, -74, 0));
        $this->assertTrue($client->isOverQuota());
    }

    public function testHttp4xxReturnsNullButStillBumpsQuota(): void
    {
        $client = $this->client([new Response(429, [], 'rate limited')]);
        $this->assertNull($client->fetchVisualPasses(25544, 40, -74, 0));
        $state = json_decode((string) file_get_contents($this->stateFile), true);
        $this->assertSame(1, $state['count']);
    }

    public function testStateResetsOnNewUtcDay(): void
    {
        // Yesterday's counter at the cap — should be reset to 0 today.
        $yesterday = gmdate('Y-m-d', time() - 86400);
        file_put_contents($this->stateFile, json_encode(['count' => 9999, 'day' => $yesterday]));
        $client = $this->client([]);
        $this->assertFalse($client->isOverQuota());
        $this->assertSame(0, $client->readState()['count']);
    }

    public function testNonJsonBodyReturnsNullAndBumpsCounter(): void
    {
        $client = $this->client([new Response(200, [], '<html>maintenance</html>')]);
        $this->assertNull($client->fetchVisualPasses(25544, 40, -74, 0));
        $this->assertSame(1, $client->readState()['count']);
    }
}
