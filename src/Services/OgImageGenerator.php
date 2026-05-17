<?php

declare(strict_types=1);

namespace SatTrackr\Services;

use RuntimeException;

/**
 * Phase 5 chunk 4 — renders 1200×630 Open Graph cards via PHP GD.
 *
 * Three card types, all sharing the trackr.live family aesthetic:
 *   • dark navy background          #0a0e27
 *   • accent cyan rule + glyph      #00d9ff
 *   • monospace title + body
 *   • brand glyph + wordmark top-left
 *   • thin "sat.trackr.live · {sub}" footer
 *
 * The deterministic output makes 6h disk-caching trivial in OgImageController.
 * Generation cost: ~30 ms per card on this hardware; not worth a queue.
 *
 * Font resolution: looks for DejaVu Sans Mono in the standard Linux
 * locations (Ubuntu, Debian, DreamHost VPS).  If none are found, falls
 * back to GD's built-in bitmap font — readable but ugly; surfaces a
 * warning at render-time so the operator notices.
 */
final class OgImageGenerator
{
    public const WIDTH  = 1200;
    public const HEIGHT = 630;

    private const FONT_CANDIDATES = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf',
        '/usr/share/fonts/TTF/DejaVuSansMono.ttf',
        '/Library/Fonts/Menlo.ttc',
    ];

    private const COLOR_BG     = [0x0a, 0x0e, 0x27];
    private const COLOR_PANEL  = [0x14, 0x1b, 0x3d];
    private const COLOR_ACCENT = [0x00, 0xd9, 0xff];
    private const COLOR_TEXT   = [0xe0, 0xe6, 0xf0];
    private const COLOR_MUTED  = [0x8a, 0x96, 0xb3];
    private const COLOR_DIM    = [0x5a, 0x64, 0x80];

    public function __construct(
        private readonly ?string $fontPath = null,
    ) {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('PHP GD is required for OG image generation');
        }
    }

    /** Render a card for a single satellite. */
    public function renderSatellite(string $name, int $norad, ?string $intlDesignator, ?string $objectType, ?string $orbitClass, ?string $country): string
    {
        $img = $this->blankCanvas();
        $this->drawChrome($img, 'Catalog');

        $accent = $this->color($img, self::COLOR_ACCENT);
        $text   = $this->color($img, self::COLOR_TEXT);
        $muted  = $this->color($img, self::COLOR_MUTED);

        $this->drawText($img, $name,    72,  220, 48, $text,   bold: true);
        $sub = "NORAD {$norad}" . ($intlDesignator !== null && $intlDesignator !== '' ? " · {$intlDesignator}" : '');
        $this->drawText($img, $sub,     72,  290, 26, $muted);

        $badges = array_values(array_filter([$objectType, $orbitClass, $country]));
        $this->drawBadges($img, $badges, 72, 360, $accent);

        return $this->emitPng($img);
    }

    /** Render a card for a launch. */
    public function renderLaunch(string $name, ?string $provider, ?string $rocket, ?string $padName, ?string $net): string
    {
        $img = $this->blankCanvas();
        $this->drawChrome($img, 'Launch');

        $text  = $this->color($img, self::COLOR_TEXT);
        $muted = $this->color($img, self::COLOR_MUTED);
        $dim   = $this->color($img, self::COLOR_DIM);

        $this->drawText($img, $name, 72, 220, 44, $text, bold: true);

        $y = 300;
        foreach ([
            ['Provider',  $provider ?? '—'],
            ['Rocket',    $rocket   ?? '—'],
            ['Pad',       $padName  ?? '—'],
            ['NET (UTC)', $net      ?? '—'],
        ] as [$label, $value]) {
            $this->drawText($img, $label, 72, $y, 22, $dim);
            $this->drawText($img, $value, 240, $y, 22, $muted);
            $y += 44;
        }

        return $this->emitPng($img);
    }

    /**
     * Render a "top close approaches" / events summary card.
     *
     * @param list<array{primary: int, secondary: int, miss_km: float, tca: string}> $rows
     */
    public function renderEvents(string $title, string $subtitle, array $rows): string
    {
        $img = $this->blankCanvas();
        $this->drawChrome($img, 'Conjunctions');

        $accent = $this->color($img, self::COLOR_ACCENT);
        $text   = $this->color($img, self::COLOR_TEXT);
        $muted  = $this->color($img, self::COLOR_MUTED);
        $dim    = $this->color($img, self::COLOR_DIM);

        $this->drawText($img, $title,     72, 220, 42, $text, bold: true);
        $this->drawText($img, $subtitle,  72, 260, 22, $muted);

        $rows = array_slice($rows, 0, 5);
        $colTca   = 72;
        $colPair  = 480;
        $colMiss  = 940;

        $headerY  = 320;
        $this->drawText($img, 'TCA (UTC)',           $colTca,  $headerY, 18, $dim);
        $this->drawText($img, 'Primary · Secondary', $colPair, $headerY, 18, $dim);
        $this->drawText($img, 'Miss',                $colMiss, $headerY, 18, $dim);

        // Rule sits below the header text (which is drawn at the BASELINE)
        // and well above the first data row's ascender.
        imagefilledrectangle($img, 72, $headerY + 12, self::WIDTH - 72, $headerY + 13, $accent);

        $y = $headerY + 56;
        foreach ($rows as $r) {
            $tca = str_replace('T', ' ', substr($r['tca'], 0, 16));
            $pair = sprintf('%d · %d', $r['primary'], $r['secondary']);
            $miss = sprintf('%.2f km', $r['miss_km']);
            $this->drawText($img, $tca,  $colTca,  $y, 22, $text);
            $this->drawText($img, $pair, $colPair, $y, 22, $text);
            $this->drawText($img, $miss, $colMiss, $y, 22, $text);
            $y += 42;
        }

        return $this->emitPng($img);
    }

    /** ---- Internals ---- */

    private function blankCanvas(): \GdImage
    {
        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if ($img === false) {
            throw new RuntimeException('Failed to allocate GD canvas');
        }
        imagealphablending($img, true);
        imagesavealpha($img, true);
        imagefilledrectangle($img, 0, 0, self::WIDTH, self::HEIGHT, $this->color($img, self::COLOR_BG));
        return $img;
    }

    private function drawChrome(\GdImage $img, string $kindLabel): void
    {
        $accent = $this->color($img, self::COLOR_ACCENT);
        $dim    = $this->color($img, self::COLOR_DIM);
        $muted  = $this->color($img, self::COLOR_MUTED);

        // Top hairline — same accent rule used on the trackr.live family.
        imagefilledrectangle($img, 0, 0, self::WIDTH, 4, $accent);

        // Brand glyph: stroked circle + crosshair, matching favicon.svg.
        $cx = 90;
        $cy = 105;
        $radius = 28;
        imagesetthickness($img, 3);
        imageellipse($img, $cx, $cy, $radius * 2, $radius * 2, $accent);
        imageline($img, $cx - $radius, $cy, $cx + $radius, $cy, $accent);
        imageline($img, $cx, $cy - $radius, $cx, $cy + $radius, $accent);

        $this->drawText($img, 'sat.trackr.live', 145, 115, 28, $muted, bold: true);
        $this->drawText($img, "§ {$kindLabel}",   145, 145, 18, $dim);

        // Footer
        $this->drawText($img, 'space situational awareness, legible', 72, self::HEIGHT - 32, 18, $dim);
    }

    /** @param array{0:int,1:int,2:int} $colors */
    private function drawBadges(\GdImage $img, array $badges, int $x, int $y, int $accentColor): void
    {
        $h = 44;
        $pad = 18;
        foreach ($badges as $label) {
            $bbox = $this->measure($label, 22);
            $w = $bbox + $pad * 2;
            imagerectangle($img, $x, $y, $x + $w, $y + $h, $accentColor);
            $this->drawText($img, $label, $x + $pad, $y + 30, 22, $accentColor, bold: true);
            $x += $w + 16;
        }
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private function color(\GdImage $img, array $rgb): int
    {
        $c = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        if ($c === false) {
            throw new RuntimeException('imagecolorallocate failed');
        }
        return $c;
    }

    private function font(bool $bold = false): ?string
    {
        if ($this->fontPath !== null && is_file($this->fontPath)) {
            return $this->fontPath;
        }
        $candidates = $bold
            ? array_merge([self::FONT_CANDIDATES[1]], self::FONT_CANDIDATES)
            : self::FONT_CANDIDATES;
        foreach ($candidates as $c) {
            if (is_file($c)) return $c;
        }
        return null;
    }

    private function drawText(\GdImage $img, string $text, int $x, int $y, int $size, int $color, bool $bold = false): void
    {
        $font = $this->font($bold);
        if ($font !== null) {
            imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
            return;
        }
        // GD bitmap fallback (font 5 is the largest built-in, ~9×15 px).
        imagestring($img, 5, $x, $y - 12, $text, $color);
    }

    private function measure(string $text, int $size): int
    {
        $font = $this->font(true);
        if ($font !== null) {
            $bbox = imagettfbbox($size, 0, $font, $text);
            return abs($bbox[2] - $bbox[0]);
        }
        return strlen($text) * 9;
    }

    private function emitPng(\GdImage $img): string
    {
        ob_start();
        imagepng($img, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($img);
        return $png;
    }
}
