<?php

declare(strict_types=1);

namespace SatTrackr\Support;

use RuntimeException;

/**
 * Strict JSON encode/decode helpers. Throws on failure rather than
 * returning false (which is easy to miss).
 */
final class Json
{
    /**
     * @param  array<mixed>|object $data
     */
    public static function encode(array|object $data, int $extraFlags = 0): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | $extraFlags;
        $encoded = json_encode($data, $flags);
        if ($encoded === false) {
            throw new RuntimeException('JSON encode failed: ' . json_last_error_msg());
        }
        return $encoded;
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('JSON decode failed or did not produce an array.');
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
