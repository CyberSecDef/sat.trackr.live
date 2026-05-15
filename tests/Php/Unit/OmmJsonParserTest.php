<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SatTrackr\Ingest\InvalidTleException;
use SatTrackr\Ingest\OmmJsonParser;
use SatTrackr\Ingest\TleParser;

final class OmmJsonParserTest extends TestCase
{
    /**
     * Real CelesTrak OMM record fetched live during chunk 7B development.
     * @return array<string, mixed>
     */
    private static function issRecord(): array
    {
        return [
            'OBJECT_NAME'         => 'ISS (ZARYA)',
            'OBJECT_ID'           => '1998-067A',
            'EPOCH'               => '2026-05-14T04:45:57.957408',
            'MEAN_MOTION'         => 15.49211692,
            'ECCENTRICITY'        => 0.00075358,
            'INCLINATION'         => 51.6312,
            'RA_OF_ASC_NODE'      => 108.3512,
            'ARG_OF_PERICENTER'   => 56.9254,
            'MEAN_ANOMALY'        => 303.2457,
            'EPHEMERIS_TYPE'      => 0,
            'CLASSIFICATION_TYPE' => 'U',
            'NORAD_CAT_ID'        => 25544,
            'ELEMENT_SET_NO'      => 999,
            'REV_AT_EPOCH'        => 56648,
            'BSTAR'               => 0.00010032304,
            'MEAN_MOTION_DOT'     => 5.122e-05,
            'MEAN_MOTION_DDOT'    => 0,
        ];
    }

    public function testParsesRealIssRecordIntoExpectedShape(): void
    {
        $parser = new OmmJsonParser();
        $tle = $parser->parse(self::issRecord());

        $this->assertSame(25544, $tle->noradId);
        $this->assertSame('ISS (ZARYA)', $tle->name);
        $this->assertSame('1998-067A', $tle->intlDesignator);
        $this->assertSame('U', $tle->classification);
        $this->assertStringStartsWith('2026-05-14T04:45:57', $tle->epochIso);
        $this->assertEqualsWithDelta(15.49211692, $tle->meanMotion, 1e-7);
        $this->assertEqualsWithDelta(0.00075358, $tle->eccentricity, 1e-9);
        $this->assertEqualsWithDelta(51.6312, $tle->inclinationDeg, 1e-4);
        $this->assertSame(56648, $tle->revNumber);

        // Derived: ISS is ~415-425 km / ~93 min.
        $this->assertEqualsWithDelta(92.95, $tle->periodMin, 0.05);
        $this->assertEqualsWithDelta(414.0, $tle->perigeeKm, 1.0);
    }

    public function testSynthesizedTleStringsRoundTripBackThroughTleParser(): void
    {
        $parser  = new OmmJsonParser();
        $reverse = new TleParser();

        $tle = $parser->parse(self::issRecord());
        $this->assertSame(69, strlen($tle->line1), "line1 actually = '{$tle->line1}' (" . strlen($tle->line1) . ' chars)');
        $this->assertSame(69, strlen($tle->line2));
        $this->assertSame('1', $tle->line1[0]);
        $this->assertSame('2', $tle->line2[0]);

        // The reverse parse should recover the same numbers within
        // the precision of the assumed-exponent encoding for B*.
        $back = $reverse->parse($tle->name, $tle->line1, $tle->line2);
        $this->assertSame($tle->noradId, $back->noradId);
        $this->assertSame($tle->intlDesignator, $back->intlDesignator);
        $this->assertEqualsWithDelta($tle->meanMotion,     $back->meanMotion,     1e-7);
        $this->assertEqualsWithDelta($tle->eccentricity,   $back->eccentricity,   1e-7);
        $this->assertEqualsWithDelta($tle->inclinationDeg, $back->inclinationDeg, 1e-4);
        $this->assertEqualsWithDelta($tle->raanDeg,        $back->raanDeg,        1e-4);
        $this->assertEqualsWithDelta($tle->bstar,          $back->bstar,          5e-9);
    }

    public function testAlpha5NoradEncodesAndRoundTripsViaSynthesizedLines(): void
    {
        $parser  = new OmmJsonParser();
        $reverse = new TleParser();

        $rec = self::issRecord();
        $rec['NORAD_CAT_ID'] = 148493;     // would be "E8493" in Alpha-5
        $tle = $parser->parse($rec);

        // The synthesized line1 should carry "E8493" in cols 2-7.
        $this->assertSame('E8493', substr($tle->line1, 2, 5));
        $this->assertSame('E8493', substr($tle->line2, 2, 5));

        $back = $reverse->parse($tle->name, $tle->line1, $tle->line2);
        $this->assertSame(148493, $back->noradId);
    }

    public function testRejectsRecordMissingRequiredField(): void
    {
        $parser = new OmmJsonParser();
        $rec = self::issRecord();
        unset($rec['MEAN_MOTION']);
        $this->expectException(InvalidTleException::class);
        $this->expectExceptionMessage('MEAN_MOTION');
        $parser->parse($rec);
    }

    public function testRejectsImpossibleEccentricity(): void
    {
        $parser = new OmmJsonParser();
        $rec = self::issRecord();
        $rec['ECCENTRICITY'] = 1.5;
        $this->expectException(InvalidTleException::class);
        $this->expectExceptionMessage('Eccentricity');
        $parser->parse($rec);
    }

    public function testTreatsBlankClassificationAsUnclassified(): void
    {
        $parser = new OmmJsonParser();
        $rec = self::issRecord();
        $rec['CLASSIFICATION_TYPE'] = '';
        $this->assertSame('U', $parser->parse($rec)->classification);
    }

    public function testNormalizesEpochWithoutTrailingZ(): void
    {
        $parser = new OmmJsonParser();
        $tle = $parser->parse(self::issRecord());
        $this->assertStringEndsWith('Z', $tle->epochIso);
    }
}
