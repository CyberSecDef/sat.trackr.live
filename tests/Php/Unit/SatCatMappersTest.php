<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SatTrackr\Ingest\SatCatMappers;

final class SatCatMappersTest extends TestCase
{
    /**
     * @return list<array{0: string|null, 1: string}>
     */
    public static function objectTypeProvider(): array
    {
        return [
            ['PAY', 'PAYLOAD'],
            ['R/B', 'ROCKET_BODY'],
            ['RB', 'ROCKET_BODY'],
            ['DEB', 'DEBRIS'],
            ['TBA', 'TBA'],
            ['UNK', 'UNKNOWN'],
            ['', 'UNKNOWN'],
            [null, 'UNKNOWN'],
            ['something-weird', 'UNKNOWN'],
        ];
    }

    #[DataProvider('objectTypeProvider')]
    public function testObjectTypeMapping(?string $code, string $expected): void
    {
        $this->assertSame($expected, SatCatMappers::objectType($code));
    }

    /**
     * @return list<array{0: string|null, 1: string}>
     */
    public static function statusProvider(): array
    {
        return [
            ['+', 'ACTIVE'],
            ['P', 'ACTIVE'],
            ['B', 'ACTIVE'],
            ['S', 'INACTIVE'],
            ['X', 'INACTIVE'],
            ['-', 'INACTIVE'],
            ['D', 'DECAYED'],
            ['', 'UNKNOWN'],
            ['?', 'UNKNOWN'],
            [null, 'UNKNOWN'],
            ['UNDOCUMENTED_CODE', 'UNKNOWN'],
        ];
    }

    #[DataProvider('statusProvider')]
    public function testStatusMapping(?string $code, string $expected): void
    {
        $this->assertSame($expected, SatCatMappers::status($code));
    }

    public function testPurposesForGroupsDeduplicates(): void
    {
        // ISS appears in both 'stations' and 'active'; 'active' adds nothing,
        // 'stations' adds 'station'.
        $this->assertSame(['station'], SatCatMappers::purposesForGroups(['stations', 'active']));
    }

    public function testPurposesForGroupsCoversCommonCategories(): void
    {
        $this->assertSame(['nav'], SatCatMappers::purposesForGroups(['gps-ops']));
        $this->assertSame(['comms'], SatCatMappers::purposesForGroups(['starlink', 'active']));
        $this->assertSame(['earth_obs'], SatCatMappers::purposesForGroups(['weather']));
        $this->assertSame(['military'], SatCatMappers::purposesForGroups(['military', 'radar']));
        $this->assertSame(['science'], SatCatMappers::purposesForGroups(['science', 'geodetic']));
        $this->assertSame(['tech_demo'], SatCatMappers::purposesForGroups(['cubesat']));
    }

    public function testPurposesForGroupsReturnsEmptyForAggregationsOnly(): void
    {
        $this->assertSame([], SatCatMappers::purposesForGroups(['active', 'last-30-days', 'analyst', 'other']));
    }

    public function testPurposesForGroupsHandlesMultipleCategories(): void
    {
        // Hypothetical satellite that's both in 'amateur' (comms) and 'cubesat' (tech_demo)
        $purposes = SatCatMappers::purposesForGroups(['amateur', 'cubesat']);
        sort($purposes);
        $this->assertSame(['comms', 'tech_demo'], $purposes);
    }

    public function testPurposesForGroupsHandlesEmptyInput(): void
    {
        $this->assertSame([], SatCatMappers::purposesForGroups([]));
    }
}
