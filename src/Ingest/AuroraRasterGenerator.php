<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

use RuntimeException;

/**
 * Phase 4 chunk 4A — bakes a NOAA OVATION 1°×1° aurora grid into an
 * equirectangular PNG.
 *
 * Output is 720×360 px (2 px per 1° cell so the overlay isn't pixel-
 * art-blocky at typical zoom).  Color ramp: transparent below 5%
 * probability, green at low intensity → yellow at mid → red at high,
 * with alpha scaling so weak cells fade into the globe rather than
 * tinting it.
 *
 * Output rectangle for Cesium SingleTileImageryProvider:
 *   longitude  -180°  to  +180°   (x)
 *   latitude    -90°  to   +90°   (y, flipped: y=0 is north pole)
 */
final class AuroraRasterGenerator
{
    public const WIDTH  = 720;
    public const HEIGHT = 360;
    private const MIN_PROBABILITY = 5;   // hide everything below 5% as background noise

    /**
     * @param list<array{0: int, 1: int, 2: int}> $coordinates  [[lon, lat, prob], …]
     */
    public function generate(array $coordinates, string $outputPath): int
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('ext-gd required for OVATION raster generation');
        }

        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if ($img === false) {
            throw new RuntimeException('Failed to allocate raster image');
        }
        imagealphablending($img, false);
        imagesavealpha($img, true);

        // Fill transparent background.
        $bg = imagecolorallocatealpha($img, 0, 0, 0, 127);
        if ($bg === false) {
            throw new RuntimeException('Failed to allocate background');
        }
        imagefilledrectangle($img, 0, 0, self::WIDTH - 1, self::HEIGHT - 1, $bg);

        $painted = 0;
        foreach ($coordinates as $cell) {
            $lon = $cell[0];
            $lat = $cell[1];
            $prob = $cell[2];
            if ($prob < self::MIN_PROBABILITY) {
                continue;
            }

            // OVATION uses lon 0..360 with the prime meridian at 0.
            // Map to the equirectangular -180..+180 range.
            $lonNorm = $lon > 180 ? $lon - 360 : $lon;

            // 1° cell → 2×2 pixel block.
            $px = (int) (($lonNorm + 180) * 2);
            $py = (int) ((90 - $lat) * 2);
            if ($px < 0 || $px >= self::WIDTH - 1 || $py < 0 || $py >= self::HEIGHT - 1) {
                continue;
            }

            [$r, $g, $b] = self::auroraRamp($prob);
            $alpha = self::auroraAlpha($prob);
            $color = imagecolorallocatealpha($img, $r, $g, $b, $alpha);
            if ($color === false) {
                continue;
            }
            imagefilledrectangle($img, $px, $py, $px + 1, $py + 1, $color);
            $painted++;
        }

        $dir = dirname($outputPath);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create output dir {$dir}");
        }
        if (!imagepng($img, $outputPath, 9)) {
            throw new RuntimeException("Failed to write {$outputPath}");
        }
        imagedestroy($img);
        return $painted;
    }

    /**
     * 5..100 probability → RGB ramp.
     *   5-20:  faint green   (#5fffa0)
     *   20-50: yellow-green  (#bdf062)
     *   50-80: orange        (#f0a040)
     *   80+:   red-orange    (#ff4830)
     * @return array{0: int, 1: int, 2: int}
     */
    private static function auroraRamp(int $prob): array
    {
        if ($prob >= 80) return [255,  72,  48];
        if ($prob >= 50) return [240, 160,  64];
        if ($prob >= 20) return [189, 240,  98];
        return [ 95, 255, 160];
    }

    /**
     * GD alpha is inverted (0 opaque, 127 transparent).  Faint cells
     * fade into background; strong cells punch through.
     */
    private static function auroraAlpha(int $prob): int
    {
        // Linear map from prob ∈ [5, 100] to alpha ∈ [110, 30]
        // (i.e. faint → very transparent, strong → mostly opaque).
        $alpha = (int) round(110 - ($prob - 5) * (80 / 95));
        return max(20, min(120, $alpha));
    }
}
