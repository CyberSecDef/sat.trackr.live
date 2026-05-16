<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Feature;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SatTrackr\Ingest\SatnogsClient;

final class SatnogsClientTest extends TestCase
{
    public function testFetchReturnsDecodedList(): void
    {
        $body = json_encode([
            ['uuid' => 'aaa', 'norad_cat_id' => 25544],
            ['uuid' => 'bbb', 'norad_cat_id' => 48274],
        ]) ?: '[]';

        $client = new SatnogsClient(new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler([new Response(200, [], $body)])),
        ]));

        $rows = $client->fetchAllTransmitters();
        $this->assertCount(2, $rows);
        $this->assertSame('aaa', $rows[0]['uuid']);
        $this->assertSame(48274, $rows[1]['norad_cat_id']);
    }

    public function testNonListResponseThrows(): void
    {
        $client = new SatnogsClient(new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler([new Response(200, [], '{"oops":"not a list"}')])),
        ]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/non-list JSON/');
        $client->fetchAllTransmitters();
    }

    public function testServerErrorIsWrapped(): void
    {
        $client = new SatnogsClient(new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler([new Response(503, [], 'maintenance')])),
        ]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/SatNOGS fetch failed/');
        $client->fetchAllTransmitters();
    }
}
