<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SatTrackr\Ingest\InvalidTleException;
use SatTrackr\Ingest\NoradId;

final class NoradIdTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideKnownPairs(): iterable
    {
        yield 'plain ISS'               => ['25544', 25544];
        yield 'plain max-classic'       => ['99999', 99999];
        yield 'leading whitespace'      => ['  500', 500];
        yield 'Alpha-5 lower bound'     => ['A0000', 100000];
        yield 'Alpha-5 sample'          => ['E8493', 148493];
        yield 'Alpha-5 mid (P)'         => ['P9999', 239999];     // P = 23 (I + O skipped) → 23*10000+9999
        yield 'Alpha-5 ceiling'         => ['Z9999', 339999];
    }

    #[DataProvider('provideKnownPairs')]
    public function testDecodeRoundTripsKnownPairs(string $slot, int $expected): void
    {
        $this->assertSame($expected, NoradId::decode($slot));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function provideEncodePairs(): iterable
    {
        yield 'plain ISS'               => [25544, '25544'];
        yield 'plain padded'            => [500, '00500'];
        yield 'Alpha-5 lower bound'     => [100000, 'A0000'];
        yield 'Alpha-5 sample'          => [148493, 'E8493'];
        yield 'Alpha-5 ceiling'         => [339999, 'Z9999'];
    }

    #[DataProvider('provideEncodePairs')]
    public function testEncodeProducesExpectedSlot(int $norad, string $expected): void
    {
        $this->assertSame($expected, NoradId::encode($norad));
    }

    public function testEncodeAndDecodeRoundTrip(): void
    {
        foreach ([1, 25544, 99999, 100000, 200000, 339999] as $n) {
            $this->assertSame($n, NoradId::decode(NoradId::encode($n)), "round-trip {$n}");
        }
    }

    public function testRejectsBadAlpha5Prefix(): void
    {
        // 'I' and 'O' are forbidden; '5' is digit-only territory but slot
        // length is wrong here.
        $this->expectException(InvalidTleException::class);
        NoradId::decode('I1234');
    }

    public function testRejectsBadAlpha5Tail(): void
    {
        $this->expectException(InvalidTleException::class);
        NoradId::decode('A12X4');
    }

    public function testRejectsEmptySlot(): void
    {
        $this->expectException(InvalidTleException::class);
        NoradId::decode('     ');
    }

    public function testEncodeRejectsTooLarge(): void
    {
        $this->expectException(InvalidTleException::class);
        NoradId::encode(400000);
    }

    public function testEncodeRejectsNegative(): void
    {
        $this->expectException(InvalidTleException::class);
        NoradId::encode(-1);
    }
}
