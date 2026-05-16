<?php

declare(strict_types=1);

/**
 * Phase 5 chunk 2A — emit the PNG icons referenced by
 * `public/manifest.webmanifest`.  Re-rasterizes the favicon's
 * dark-circle + cyan-crosshair glyph at 192 / 512 (any-purpose) and
 * 512 maskable (with the 20% safe-zone padding maskable specs
 * require).  Idempotent: run any time, overwrites prior output.
 *
 *   php bin/build-pwa-icons.php
 *
 * Why a programmatic generator instead of static PNGs in the repo:
 *   - keeps the rendering source-of-truth in one file
 *   - re-generating after a brand change is trivial
 *   - PHP GD is already in our runtime; no extra dep
 */

if (!extension_loaded('gd')) {
    fwrite(STDERR, "PHP GD extension required.\n");
    exit(1);
}

$outDir = dirname(__DIR__) . '/public/icons';
if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Could not create {$outDir}\n");
    exit(1);
}

/**
 * Render the trackr.live glyph onto a $size×$size canvas.
 *   $padding = ratio of safe-zone empty space (0 = edge-to-edge,
 *              0.2 = maskable, so the icon survives a 20% crop).
 */
function renderIcon(int $size, float $padding): \GdImage
{
    $img = imagecreatetruecolor($size, $size);
    imagesavealpha($img, true);

    $bg     = imagecolorallocate($img, 0x0a, 0x0e, 0x27);  // --bg
    $accent = imagecolorallocate($img, 0x00, 0xd9, 0xff);  // --accent

    // Full-bleed background so the system can mask it.
    imagefilledrectangle($img, 0, 0, $size - 1, $size - 1, $bg);

    // Icon geometry inside the safe zone.
    $inset = (int) round($size * $padding);
    $cx = (int) round($size / 2);
    $cy = (int) round($size / 2);
    $radius = (int) round(($size / 2) - $inset);
    $stroke = max(2, (int) round($size * 0.03));

    // Stroked circle (drawn as a filled ring so antialiasing is cleaner).
    imagefilledellipse($img, $cx, $cy, $radius * 2, $radius * 2, $accent);
    imagefilledellipse($img, $cx, $cy, ($radius - $stroke) * 2, ($radius - $stroke) * 2, $bg);

    // Crosshair.
    imagesetthickness($img, $stroke);
    imageline($img, $cx - $radius, $cy, $cx + $radius, $cy, $accent);
    imageline($img, $cx, $cy - $radius, $cx, $cy + $radius, $accent);

    return $img;
}

$targets = [
    ['size' => 192, 'padding' => 0.05, 'filename' => 'icon-192.png'],
    ['size' => 512, 'padding' => 0.05, 'filename' => 'icon-512.png'],
    ['size' => 512, 'padding' => 0.20, 'filename' => 'icon-512-maskable.png'],
    // Apple touch icon — iOS Safari ignores the manifest for this and
    // looks specifically for /apple-touch-icon.png at 180×180.
    ['size' => 180, 'padding' => 0.05, 'filename' => '../apple-touch-icon.png'],
];

foreach ($targets as $t) {
    $img = renderIcon($t['size'], $t['padding']);
    $path = "{$outDir}/{$t['filename']}";
    imagepng($img, $path, 9);
    imagedestroy($img);
    echo " ✓ {$t['filename']} ({$t['size']}px)\n";
}
echo "Done.\n";
