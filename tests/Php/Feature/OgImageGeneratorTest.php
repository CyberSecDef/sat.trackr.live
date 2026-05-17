<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SatTrackr\Services\OgImageGenerator;

final class OgImageGeneratorTest extends TestCase
{
    private function generator(): OgImageGenerator
    {
        return new OgImageGenerator();
    }

    public function testRenderSatelliteEmitsValid1200x630Png(): void
    {
        $png = $this->generator()->renderSatellite('ISS (ZARYA)', 25544, '1998-067A', 'PAYLOAD', 'LEO', 'US');
        $this->assertGreaterThan(2000, strlen($png), 'PNG should be more than a stub');
        $info = getimagesizefromstring($png);
        $this->assertNotFalse($info);
        $this->assertSame(OgImageGenerator::WIDTH,  $info[0]);
        $this->assertSame(OgImageGenerator::HEIGHT, $info[1]);
        $this->assertSame('image/png', $info['mime']);
    }

    public function testRenderLaunchEmitsValidPngWithMissingFieldsTolerated(): void
    {
        $png = $this->generator()->renderLaunch('Starship IFT-9', null, null, null, '2026-06-01T00:00:00Z');
        $info = getimagesizefromstring($png);
        $this->assertNotFalse($info);
        $this->assertSame(OgImageGenerator::WIDTH,  $info[0]);
        $this->assertSame(OgImageGenerator::HEIGHT, $info[1]);
    }

    public function testRenderEventsHandlesEmptyAndPopulatedRows(): void
    {
        $empty = $this->generator()->renderEvents('Top conjunctions today', '2026-05-16', []);
        $this->assertNotFalse(getimagesizefromstring($empty));

        $rows = [
            ['primary' => 25544, 'secondary' => 44713, 'miss_km' => 0.12, 'tca' => '2026-05-16T12:30:00Z'],
            ['primary' => 27424, 'secondary' => 51850, 'miss_km' => 0.45, 'tca' => '2026-05-16T18:11:00Z'],
        ];
        $full = $this->generator()->renderEvents('Top conjunctions today', '2026-05-16', $rows);
        $info = getimagesizefromstring($full);
        $this->assertNotFalse($info);
        $this->assertSame(OgImageGenerator::WIDTH, $info[0]);
    }

    public function testDeterministicOutputForSameInputs(): void
    {
        $g = $this->generator();
        $a = $g->renderSatellite('ISS', 25544, '1998-067A', 'PAYLOAD', 'LEO', 'US');
        $b = $g->renderSatellite('ISS', 25544, '1998-067A', 'PAYLOAD', 'LEO', 'US');
        $this->assertSame(md5($a), md5($b), 'OG cards must be deterministic for cache stability');
    }
}
