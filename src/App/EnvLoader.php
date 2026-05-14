<?php

declare(strict_types=1);

namespace SatTrackr\App;

use Dotenv\Dotenv;
use RuntimeException;

/**
 * Layered .env loader.
 *
 * Loads .env first (shared defaults + APP_ENV marker), then overlays
 * .env.{APP_ENV}. The overlay file is required.
 */
final class EnvLoader
{
    public static function load(string $rootDir): void
    {
        Dotenv::createImmutable($rootDir, '.env')->safeLoad();

        $env = self::get('APP_ENV', 'dev') ?? 'dev';

        if (!in_array($env, ['dev', 'prod'], true)) {
            throw new RuntimeException(
                "Invalid APP_ENV='{$env}'. Expected 'dev' or 'prod'."
            );
        }

        $overlayFile = ".env.{$env}";
        if (!file_exists("{$rootDir}/{$overlayFile}")) {
            throw new RuntimeException(
                "Required env file '{$overlayFile}' is missing at '{$rootDir}'. "
                . "Copy '{$overlayFile}.example' to '{$overlayFile}' and fill in the values."
            );
        }

        Dotenv::createImmutable($rootDir, $overlayFile)->load();
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return (string) $value;
    }

    public static function isDev(): bool
    {
        return self::get('APP_ENV', 'dev') === 'dev';
    }

    public static function isProd(): bool
    {
        return self::get('APP_ENV', 'dev') === 'prod';
    }
}
