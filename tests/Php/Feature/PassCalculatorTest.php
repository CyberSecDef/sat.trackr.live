<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Feature;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SatTrackr\Services\PassCalculator;

/**
 * Exercises the real Node subprocess against a known TLE.  Skipped if
 * `node` isn't on PATH (CI sometimes installs it later than PHP).
 */
final class PassCalculatorTest extends TestCase
{
    private string $scriptPath;

    private const ISS_LINE_1 = '1 25544U 98067A   26134.20272636  .00027410  00000-0  47815-3 0  9994';
    private const ISS_LINE_2 = '2 25544  51.6358 207.8530 0001859  44.6849 315.4254 15.50907195502493';

    protected function setUp(): void
    {
        $this->scriptPath = dirname(__DIR__, 3) . '/bin/sgp4-passes.mjs';
        if (!file_exists($this->scriptPath)) {
            $this->fail("sgp4-passes.mjs not found at {$this->scriptPath}");
        }
        if ((int) shell_exec('command -v node >/dev/null 2>&1 && echo 1 || echo 0') !== 1) {
            $this->markTestSkipped('node binary not available on PATH');
        }
    }

    public function testComputeReturnsRealisticIssPasses(): void
    {
        $calc = new PassCalculator($this->scriptPath);
        $startMs = (int) (strtotime('2026-05-15T00:00:00Z') * 1000);
        $result = $calc->compute(
            tle: ['line1' => self::ISS_LINE_1, 'line2' => self::ISS_LINE_2],
            observer: ['latitude' => 51.5072, 'longitude' => -0.1276, 'altitudeMeters' => 30.0],
            startMs: $startMs,
            days: 3,
        );

        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('passes', $result);
        $this->assertGreaterThanOrEqual(4, $result['count']);
        $this->assertLessThanOrEqual(40, $result['count']);

        foreach ($result['passes'] as $p) {
            $this->assertNotEmpty($p['rise_at']);
            $this->assertNotEmpty($p['set_at']);
            $this->assertGreaterThanOrEqual(10, $p['max_elevation_deg']);
            $this->assertLessThanOrEqual(90, $p['max_elevation_deg']);
            $this->assertGreaterThan(0, $p['duration_seconds']);
        }
    }

    public function testInvalidJobThrowsRuntimeExceptionWithStderrSnippet(): void
    {
        $calc = new PassCalculator($this->scriptPath);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing tle/');

        $calc->compute(
            tle: ['line1' => '', 'line2' => ''],          // CLI will reject
            observer: ['latitude' => 0.0, 'longitude' => 0.0, 'altitudeMeters' => 0.0],
            startMs: 0,
            days: 1,
        );
    }
}
