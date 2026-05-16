<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SatTrackr\Ingest\InvalidTleException;
use SatTrackr\Ingest\SocratesCsvParser;

final class SocratesCsvParserTest extends TestCase
{
    private function fixture(): string
    {
        $path = dirname(__DIR__) . '/Fixtures/socrates-sample.csv';
        $body = file_get_contents($path);
        if ($body === false) {
            $this->fail("Could not read fixture {$path}");
        }
        return $body;
    }

    public function testParsesAllValidRowsAndSkipsMalformed(): void
    {
        $parser = new SocratesCsvParser();
        $rows = $parser->parse($this->fixture());

        // Fixture has 5 data rows; row 4 missing primary NORAD and row 5
        // missing TCA — both rejected.  Three valid rows expected.
        $this->assertCount(3, $rows);
    }

    public function testFirstRowDecodesAllFields(): void
    {
        $parser = new SocratesCsvParser();
        $first = $parser->parse($this->fixture())[0];

        $this->assertSame(48667, $first['norad_primary']);
        $this->assertSame('STARLINK-2735 [P]', $first['name_primary']);
        $this->assertEqualsWithDelta(4.444, $first['dse_primary'], 1e-6);
        $this->assertSame(58934, $first['norad_secondary']);
        $this->assertSame('STARLINK-31337 [+]', $first['name_secondary']);
        $this->assertSame('2026-05-19T17:34:05.231Z', $first['tca']);
        $this->assertEqualsWithDelta(0.021, $first['tca_range_km'], 1e-6);
        $this->assertEqualsWithDelta(12.104, $first['tca_relative_speed_km_s'], 1e-6);
        $this->assertEqualsWithDelta(0.4472, $first['max_probability'], 1e-6);
        $this->assertEqualsWithDelta(0.005, $first['dilution'], 1e-6);
    }

    public function testIssRowKeepsObjectFlagInName(): void
    {
        // We deliberately preserve the [+] / [-] / [P] flags so the UI
        // can render badges without re-deriving status.
        $parser = new SocratesCsvParser();
        $rows = $parser->parse($this->fixture());
        $iss = array_values(array_filter($rows, static fn ($r) => $r['norad_primary'] === 25544))[0];
        $this->assertSame('ISS (ZARYA) [+]', $iss['name_primary']);
        $this->assertSame('DEBRIS X [-]', $iss['name_secondary']);
    }

    public function testTcaNormalizationAddsTAndZ(): void
    {
        $parser = new SocratesCsvParser();
        foreach ($parser->parse($this->fixture()) as $row) {
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
                $row['tca'],
                'TCA should be ISO with T separator'
            );
            $this->assertStringEndsWith('Z', $row['tca']);
        }
    }

    public function testEmptyInputReturnsEmptyList(): void
    {
        $parser = new SocratesCsvParser();
        $this->assertSame([], $parser->parse(''));
    }

    public function testWrongHeaderThrows(): void
    {
        $parser = new SocratesCsvParser();
        $this->expectException(InvalidTleException::class);
        $this->expectExceptionMessage('SOCRATES header mismatch');
        $parser->parse("FOO,BAR\n1,2\n");
    }

    public function testHandlesLfOnlyLineEndings(): void
    {
        // Make sure CRLF tolerance works for plain LF too.
        $crlfFixture = $this->fixture();
        $lfFixture = str_replace("\r\n", "\n", $crlfFixture);
        $parser = new SocratesCsvParser();
        $this->assertSame(
            $parser->parse($crlfFixture),
            $parser->parse($lfFixture),
        );
    }
}
