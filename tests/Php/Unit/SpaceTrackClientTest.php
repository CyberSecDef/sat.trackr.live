<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Unit;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SatTrackr\Ingest\SpaceTrackClient;

final class SpaceTrackClientTest extends TestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface, response?: mixed}> */
    private array $captured = [];

    /** @param list<Response> $responses */
    private function makeClient(array $responses, string $user = 'u@example.com', string $pass = 'pw'): SpaceTrackClient
    {
        $this->captured = [];
        $mock           = new MockHandler($responses);
        $stack          = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->captured));
        $guzzle = new GuzzleClient(['handler' => $stack]);
        return new SpaceTrackClient($guzzle, $user, $pass, new CookieJar());
    }

    public function testQueryLogsInFirstThenFetches(): void
    {
        $client = $this->makeClient([
            new Response(200, [], ''),                              // login
            new Response(200, [], '[{"NORAD_CAT_ID":"25544"}]'),    // query
        ]);

        $rows = $client->query('class/tip/orderby/INSERT_EPOCH desc');

        $this->assertCount(2, $this->captured);
        $this->assertSame('POST', $this->captured[0]['request']->getMethod());
        $this->assertSame('/ajaxauth/login', $this->captured[0]['request']->getUri()->getPath());
        $this->assertStringContainsString('identity=u%40example.com', (string) $this->captured[0]['request']->getBody());

        $this->assertSame('GET', $this->captured[1]['request']->getMethod());
        $this->assertStringContainsString('/basicspacedata/query/class/tip', (string) $this->captured[1]['request']->getUri());
        $this->assertStringContainsString('format/json', (string) $this->captured[1]['request']->getUri());
        $this->assertSame([['NORAD_CAT_ID' => '25544']], $rows);
    }

    public function testSecondQueryReusesSessionWithoutReLogin(): void
    {
        $client = $this->makeClient([
            new Response(200, [], ''),
            new Response(200, [], '[]'),
            new Response(200, [], '[{"x":1}]'),
        ]);
        $client->query('class/tip');
        $client->query('class/decay');

        $methods = array_map(static fn ($r) => $r['request']->getMethod(), $this->captured);
        $this->assertSame(['POST', 'GET', 'GET'], $methods);
    }

    public function testQueryAppendsFormatJsonWhenMissing(): void
    {
        $client = $this->makeClient([
            new Response(200, [], ''),
            new Response(200, [], '[]'),
        ]);
        $client->query('class/tip/limit/5');
        $this->assertStringContainsString('class/tip/limit/5/format/json', (string) $this->captured[1]['request']->getUri());
    }

    public function testQueryDoesNotDuplicateExistingFormat(): void
    {
        $client = $this->makeClient([
            new Response(200, [], ''),
            new Response(200, [], '[]'),
        ]);
        $client->query('class/tip/format/xml');
        $uri = (string) $this->captured[1]['request']->getUri();
        $this->assertStringContainsString('format/xml', $uri);
        $this->assertStringNotContainsString('format/json', $uri);
    }

    public function testEmptyCredentialsThrowBeforeAnyHttpCall(): void
    {
        $client = $this->makeClient([], '', '');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SPACE_TRACK_USER');
        $client->query('class/tip');
    }

    public function testNonJsonBodyThrows(): void
    {
        $client = $this->makeClient([
            new Response(200, [], ''),
            new Response(200, [], '<html>maintenance</html>'),
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-JSON');
        $client->query('class/tip');
    }
}
