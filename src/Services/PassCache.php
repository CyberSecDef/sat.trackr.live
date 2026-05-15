<?php

declare(strict_types=1);

namespace SatTrackr\Services;

use SatTrackr\Database\Connection;

/**
 * Phase 2 chunk 6 read-through cache for pass predictions.
 *
 * The cache key bundles NORAD + observer rounded to 3 decimal places
 * (~110m at the equator) + ISO date.  The 6-hour TTL is per req_spec
 * §4.3 — long enough that interactive panel use almost always hits
 * cache, short enough that TLE drift doesn't poison results.
 *
 * `prune()` is invoked daily by `bin/console pass-cache:prune`.
 */
final class PassCache
{
    public const TTL_SECONDS = 6 * 3600;

    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public static function key(int $norad, float $lat, float $lon, string $day): string
    {
        return sprintf('%d:%.3f:%.3f:%s', $norad, $lat, $lon, $day);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $norad, float $lat, float $lon, string $day): ?array
    {
        $key = self::key($norad, $lat, $lon, $day);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        $row = $this->db->capsule()->table('pass_cache')
            ->where('cache_key', $key)
            ->where('expires_at', '>=', $now)
            ->first();
        if ($row === null) {
            return null;
        }
        $decoded = json_decode((string) $row->passes_json, true);
        if (!is_array($decoded)) {
            return null;
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $passes
     */
    public function put(int $norad, float $lat, float $lon, float $alt, string $day, array $passes): void
    {
        $key = self::key($norad, $lat, $lon, $day);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expires = $now->modify('+' . self::TTL_SECONDS . ' seconds');

        $payload = [
            'cache_key'    => $key,
            'norad_id'     => $norad,
            'observer_lat' => $lat,
            'observer_lon' => $lon,
            'observer_alt' => $alt,
            'day'          => $day,
            'passes_json'  => json_encode($passes, JSON_UNESCAPED_SLASHES),
            'computed_at'  => $now->format('Y-m-d\TH:i:s\Z'),
            'expires_at'   => $expires->format('Y-m-d\TH:i:s\Z'),
        ];

        $stmt = $this->db->pdo()->prepare(<<<'SQL'
            INSERT INTO pass_cache
              (cache_key, norad_id, observer_lat, observer_lon, observer_alt, day, passes_json, computed_at, expires_at)
            VALUES
              (:cache_key, :norad_id, :observer_lat, :observer_lon, :observer_alt, :day, :passes_json, :computed_at, :expires_at)
            ON CONFLICT(cache_key) DO UPDATE SET
              passes_json = excluded.passes_json,
              computed_at = excluded.computed_at,
              expires_at  = excluded.expires_at
            SQL);
        $stmt->execute($payload);
    }

    /** @return int rows pruned */
    public function prune(): int
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        return $this->db->capsule()->table('pass_cache')->where('expires_at', '<', $now)->delete();
    }
}
