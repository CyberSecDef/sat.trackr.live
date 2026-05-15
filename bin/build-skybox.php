<?php

declare(strict_types=1);

/**
 * Phase 3 chunk 1A — BSC5 skybox cubemap generator.
 *
 * Fetches the Bright Star Catalog 5 (≈9100 stars to magnitude 6.5),
 * projects each star onto the appropriate face of an inertial-frame
 * cubemap, and emits 6 PNG faces at public/textures/skybox/{px,nx,py,ny,pz,nz}.png.
 * Cesium's SkyBox primitive consumes these at runtime; the result is
 * a real night sky behind the satellites instead of Cesium's default
 * generic starfield.
 *
 * Usage:  make build-skybox     (or: php bin/build-skybox.php)
 *
 * The output is committed to the repo so the runtime never needs to
 * fetch BSC5; this script only re-runs when refreshing the data
 * (~once per release at most — stellar positions don't change much
 * over a human lifetime).
 *
 * Magnitude → alpha curve: alpha = max(0.05, (6.7 - mag) / 6.7)^1.6
 * Mid-magnitude stars get a soft glow (1px alpha + 2px alpha/2 disk);
 * very bright stars (mag < 1) get a 3px disk.
 */

const BSC5_URL    = 'https://raw.githubusercontent.com/aduboisforge/Bright-Star-Catalog-JSON/master/BSC.json';
const FACE_PIXELS = 1024;
const REPO_ROOT   = __DIR__ . '/..';
const OUT_DIR     = REPO_ROOT . '/public/textures/skybox';
const CACHE_PATH  = REPO_ROOT . '/storage/cache/bsc5.json';

const FACES = [
    'positiveX' => 'px',
    'negativeX' => 'nx',
    'positiveY' => 'py',
    'negativeY' => 'ny',
    'positiveZ' => 'pz',
    'negativeZ' => 'nz',
];

main();

function main(): void
{
    if (!extension_loaded('gd')) {
        fwrite(STDERR, "ext-gd is required\n");
        exit(1);
    }

    $stars = loadStars();
    fwrite(STDOUT, sprintf("Loaded %d stars\n", count($stars)));

    if (!is_dir(OUT_DIR)) {
        mkdir(OUT_DIR, 0o755, true);
    }

    $images = [];
    foreach (FACES as $faceKey => $shortName) {
        $img = imagecreatetruecolor(FACE_PIXELS, FACE_PIXELS);
        imagealphablending($img, true);
        imagesavealpha($img, true);
        $bg = imagecolorallocatealpha($img, 0, 0, 0, 0);   // fully opaque black
        imagefilledrectangle($img, 0, 0, FACE_PIXELS - 1, FACE_PIXELS - 1, $bg);
        $images[$faceKey] = $img;
    }

    $painted = 0;
    foreach ($stars as $star) {
        [$x, $y, $z]    = raDecToVector($star['ra_rad'], $star['dec_rad']);
        [$face, $u, $v] = vectorToFace($x, $y, $z);
        $px = (int) (($u + 1) / 2 * (FACE_PIXELS - 1));
        $py = (int) ((1 - ($v + 1) / 2) * (FACE_PIXELS - 1));
        paintStar($images[$face], $px, $py, (float) $star['mag']);
        $painted++;
    }

    fwrite(STDOUT, sprintf("Painted %d stars across 6 faces\n", $painted));

    foreach ($images as $faceKey => $img) {
        $path = OUT_DIR . '/' . FACES[$faceKey] . '.png';
        imagepng($img, $path, 9);
        imagedestroy($img);
        fwrite(STDOUT, sprintf("  %s.png  (%s)\n", FACES[$faceKey], formatBytes(filesize($path) ?: 0)));
    }
}

/**
 * @return list<array{ra_rad: float, dec_rad: float, mag: float}>
 */
function loadStars(): array
{
    if (!is_file(CACHE_PATH)) {
        if (!is_dir(dirname(CACHE_PATH))) {
            mkdir(dirname(CACHE_PATH), 0o755, true);
        }
        fwrite(STDOUT, "Fetching BSC5 from " . BSC5_URL . " …\n");
        $body = file_get_contents(BSC5_URL);
        if ($body === false || strlen($body) < 1000) {
            fwrite(STDERR, "Failed to fetch BSC5 (got " . strlen((string) $body) . " bytes)\n");
            exit(1);
        }
        file_put_contents(CACHE_PATH, $body);
    } else {
        fwrite(STDOUT, "Using cached BSC5 at " . CACHE_PATH . "\n");
    }

    $raw = json_decode((string) file_get_contents(CACHE_PATH), true);
    if (!is_array($raw)) {
        fwrite(STDERR, "BSC5 JSON did not decode to an array\n");
        exit(1);
    }

    $stars = [];
    foreach ($raw as $row) {
        if (!is_array($row) || !isset($row['RA'], $row['DEC'], $row['MAG'])) {
            continue;
        }
        $mag = (float) $row['MAG'];
        if ($mag > 6.7) {
            continue;
        }
        $ra  = parseHMS((string) $row['RA']);
        $dec = parseDMS((string) $row['DEC']);
        if ($ra === null || $dec === null) {
            continue;
        }
        $stars[] = [
            'ra_rad'  => $ra,
            'dec_rad' => $dec,
            'mag'     => $mag,
        ];
    }
    return $stars;
}

/** "00:05:09.90"  →  radians (RA degrees: 0 - 360). */
function parseHMS(string $s): ?float
{
    if (preg_match('/^(\d{1,2}):(\d{1,2}):(\d{1,2}(?:\.\d+)?)/', trim($s), $m) !== 1) {
        return null;
    }
    $h = (float) $m[1];
    $mi = (float) $m[2];
    $sec = (float) $m[3];
    $hours = $h + $mi / 60 + $sec / 3600;
    $deg = $hours * 15.0;       // 24h = 360°
    return $deg * M_PI / 180.0;
}

/** "+45:13:45.00" or "-05:42:27.00"  →  radians (Dec degrees: -90 to +90). */
function parseDMS(string $s): ?float
{
    $s = trim($s);
    if (preg_match('/^([+\-]?)(\d{1,2}):(\d{1,2}):(\d{1,2}(?:\.\d+)?)/', $s, $m) !== 1) {
        return null;
    }
    $sign = $m[1] === '-' ? -1.0 : 1.0;
    $d  = (float) $m[2];
    $mi = (float) $m[3];
    $sec = (float) $m[4];
    $deg = $sign * ($d + $mi / 60 + $sec / 3600);
    return $deg * M_PI / 180.0;
}

/** RA, Dec radians  →  unit vector in J2000-ish equatorial frame. */
function raDecToVector(float $ra, float $dec): array
{
    $cosDec = cos($dec);
    return [$cosDec * cos($ra), $cosDec * sin($ra), sin($dec)];
}

/**
 * Vector  →  cube face name + (u, v) ∈ [-1, 1].
 * Standard OpenGL cubemap convention.  Cesium's SkyBox accepts the
 * same axis orientations.
 *
 * @return array{0: string, 1: float, 2: float}
 */
function vectorToFace(float $x, float $y, float $z): array
{
    $absX = abs($x);
    $absY = abs($y);
    $absZ = abs($z);

    if ($absX >= $absY && $absX >= $absZ) {
        if ($x > 0) {
            return ['positiveX', -$z / $absX, -$y / $absX];
        }
        return ['negativeX',  $z / $absX, -$y / $absX];
    }
    if ($absY >= $absX && $absY >= $absZ) {
        if ($y > 0) {
            return ['positiveY',  $x / $absY,  $z / $absY];
        }
        return ['negativeY',  $x / $absY, -$z / $absY];
    }
    if ($z > 0) {
        return ['positiveZ',  $x / $absZ, -$y / $absZ];
    }
    return ['negativeZ', -$x / $absZ, -$y / $absZ];
}

/**
 * Paint one star at (px, py) with brightness derived from magnitude.
 * Bright stars (mag < 1) get a 3px disk; mid (1-3) get 2px; dim
 * (>3) get 1px.  Alpha is gamma-curved so the falloff is visually
 * graceful instead of all looking equally dim.
 */
function paintStar(\GdImage $img, int $px, int $py, float $mag): void
{
    // Clamp magnitude to [-1.5, 6.7] so the few sub-zero stars
    // (Sirius -1.46, Canopus -0.74) don't blow past the alpha range.
    $clampedMag = max(-1.5, min(6.7, $mag));
    $linear = (6.7 - $clampedMag) / (6.7 - (-1.5));
    $linear = max(0.0, min(1.0, $linear));
    $alpha  = $linear ** 1.6;
    if ($alpha < 0.02) {
        return;
    }

    $radius = $mag < 1.0 ? 3 : ($mag < 3.0 ? 2 : 1);

    // GD alpha is inverted: 0 = opaque, 127 = transparent.
    $gdAlpha = max(0, min(127, (int) round(127 * (1.0 - $alpha))));
    $color = imagecolorallocatealpha($img, 255, 255, 255, $gdAlpha);
    if ($color === false) {
        return;
    }
    imagefilledellipse($img, $px, $py, $radius * 2, $radius * 2, $color);

    // Soft glow halo for the brightest stars.
    if ($mag < 1.5) {
        $haloAlpha = max(0, min(127, (int) round(127 * (1.0 - $alpha * 0.4))));
        $halo = imagecolorallocatealpha($img, 255, 255, 255, $haloAlpha);
        if ($halo !== false) {
            imagefilledellipse($img, $px, $py, ($radius + 1) * 2, ($radius + 1) * 2, $halo);
        }
    }
}

function formatBytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . 'B';
    }
    if ($bytes < 1024 * 1024) {
        return sprintf('%.1fKB', $bytes / 1024);
    }
    return sprintf('%.2fMB', $bytes / (1024 * 1024));
}
