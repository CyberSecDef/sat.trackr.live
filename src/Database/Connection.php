<?php

declare(strict_types=1);

namespace SatTrackr\Database;

use Illuminate\Database\Capsule\Manager as Capsule;
use PDO;
use RuntimeException;

/**
 * Wraps Eloquent's Capsule manager. Single SQLite connection with the Phase 1
 * §V pragma settings applied at open time.
 */
final class Connection
{
    private Capsule $capsule;

    public function __construct(string $dbPath)
    {
        // Resolve relative paths against the current working dir so a value
        // like "data/sat.db" works whether invoked from repo root or a
        // subdirectory.
        if ($dbPath !== ':memory:' && !str_starts_with($dbPath, '/')) {
            $dbPath = getcwd() . '/' . $dbPath;
        }

        $this->ensureDirectoryExists($dbPath);

        $this->capsule = new Capsule();
        $this->capsule->addConnection([
            'driver'                  => 'sqlite',
            'database'                => $dbPath,
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ]);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        // Phase 1 §V pragma settings
        $pdo = $this->pdo();
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA cache_size = -64000');
        $pdo->exec('PRAGMA temp_store = MEMORY');
        $pdo->exec('PRAGMA mmap_size = 268435456');
    }

    public function capsule(): Capsule
    {
        return $this->capsule;
    }

    public function pdo(): PDO
    {
        $pdo = $this->capsule->getConnection()->getPdo();
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('Expected PDO instance from Eloquent connection.');
        }
        return $pdo;
    }

    private function ensureDirectoryExists(string $dbPath): void
    {
        if ($dbPath === ':memory:') {
            return;
        }
        $dir = dirname($dbPath);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create database directory: {$dir}");
        }
    }
}
