<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SatTrackr\Services\AtomGenerator;

final class AtomGeneratorTest extends TestCase
{
    /** @return list<array{id: string, kind: string, timestamp: string, title: string, summary: string, link: string}> */
    private function fixtureEvents(): array
    {
        return [
            [
                'id'        => 'launch:uuid-soon',
                'kind'      => 'launch',
                'timestamp' => '2026-05-18T15:30:00Z',
                'title'     => 'Falcon 9 | Starlink Group 7-99',
                'summary'   => 'SpaceX · Falcon 9 · GO',
                'link'      => '/text/launches/uuid-soon',
            ],
            [
                'id'        => 'storm:42',
                'kind'      => 'storm',
                'timestamp' => '2026-05-17T03:00:00Z',
                'title'     => 'G2 geomagnetic storm · M-class X-ray flare',
                'summary'   => 'Kp 5.33 · X-ray M · R1/S0/G2',
                'link'      => '/text/space-weather',
            ],
        ];
    }

    public function testRendersValidXmlWithFeedAndEntries(): void
    {
        $xml = (new AtomGenerator())->build(
            events:    $this->fixtureEvents(),
            feedTitle: 'sat.trackr.live — events',
            baseUrl:   'https://sat.trackr.live',
            selfUrl:   'https://sat.trackr.live/events.atom',
            updated:   '2026-05-18T16:00:00Z',
        );

        // Header + namespace
        $this->assertStringStartsWith('<?xml version="1.0" encoding="utf-8"?>', $xml);
        $this->assertStringContainsString('<feed xmlns="http://www.w3.org/2005/Atom">', $xml);

        // Feed-level metadata
        $this->assertStringContainsString('<title>sat.trackr.live — events</title>', $xml);
        $this->assertStringContainsString('<updated>2026-05-18T16:00:00Z</updated>', $xml);
        $this->assertStringContainsString('rel="self" href="https://sat.trackr.live/events.atom"', $xml);

        // Both entries present
        $this->assertSame(2, substr_count($xml, '<entry>'));
        $this->assertStringContainsString('urn:sat.trackr.live:event:launch:uuid-soon', $xml);
        $this->assertStringContainsString('urn:sat.trackr.live:event:storm:42', $xml);

        // Deep links converted to absolute URLs
        $this->assertStringContainsString('href="https://sat.trackr.live/text/launches/uuid-soon"', $xml);
        $this->assertStringContainsString('href="https://sat.trackr.live/text/space-weather"', $xml);

        // Categories
        $this->assertStringContainsString('<category term="launch"/>', $xml);
        $this->assertStringContainsString('<category term="storm"/>', $xml);
    }

    public function testEscapesXmlSpecialCharactersInTitleAndSummary(): void
    {
        $xml = (new AtomGenerator())->build(
            events: [[
                'id'        => 'conjunction:1',
                'kind'      => 'conjunction',
                'timestamp' => '2026-05-18T00:00:00Z',
                'title'     => 'ISS [+] × STARLINK [+] & friends',
                'summary'   => 'Miss < 1km · p > 0.05',
                'link'      => '/api/v1/conjunctions/25544/44713',
            ]],
            feedTitle: 'sat.trackr.live — events',
            baseUrl:   'https://sat.trackr.live',
            selfUrl:   'https://sat.trackr.live/events.atom',
            updated:   '2026-05-18T00:00:00Z',
        );

        $this->assertStringContainsString('&amp;', $xml);
        $this->assertStringContainsString('&lt;', $xml);
        $this->assertStringContainsString('&gt;', $xml);
        // The raw '<' and '&' inside attribute/content positions must NOT appear
        // (other than inside the < of tag boundaries themselves).
        $this->assertStringNotContainsString('ISS [+] × STARLINK [+] & friends', $xml);
    }

    public function testEmptyEventListProducesValidFeedWithZeroEntries(): void
    {
        $xml = (new AtomGenerator())->build(
            events: [],
            feedTitle: 't',
            baseUrl:   'https://x',
            selfUrl:   'https://x/events.atom',
            updated:   '2026-05-18T00:00:00Z',
        );
        $this->assertStringContainsString('<feed', $xml);
        $this->assertSame(0, substr_count($xml, '<entry>'));
    }

    public function testOutputParsesAsXml(): void
    {
        // The cheapest "valid Atom" smoke test we can do without
        // running an external Atom validator: load it via SimpleXML
        // and assert the entries count matches.
        $xml = (new AtomGenerator())->build($this->fixtureEvents(), 'feed', 'https://x', 'https://x/events.atom', '2026-05-18T00:00:00Z');
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        $this->assertNotFalse($doc, 'AtomGenerator emitted unparseable XML: ' . implode(', ', array_map(static fn ($e) => trim($e->message), $errors)));
        $this->assertSame(2, $doc->entry->count());
    }
}
