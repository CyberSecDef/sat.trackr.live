<?php

declare(strict_types=1);

namespace SatTrackr\Database;

use RuntimeException;

/**
 * Custom migration runner. Tracks applied migrations in a `migrations` table
 * keyed by filename. Discovers files in $migrationsDir and runs unapplied
 * ones in lexical order (matches the YYYY_MM_DD_NNNNNN_* naming).
 */
final class Migrator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $migrationsDir,
    ) {
    }

    public function ensureMigrationsTable(): void
    {
        $this->connection->pdo()->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS migrations (
              id          INTEGER PRIMARY KEY AUTOINCREMENT,
              migration   TEXT NOT NULL UNIQUE,
              batch       INTEGER NOT NULL,
              migrated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL);
    }

    /**
     * @return list<string>
     */
    public function applied(): array
    {
        $this->ensureMigrationsTable();
        $stmt = $this->connection->pdo()->query('SELECT migration FROM migrations ORDER BY id');
        if ($stmt === false) {
            return [];
        }
        $names = $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);
        return array_values(array_map('strval', $names ?: []));
    }

    /**
     * @return list<string>
     */
    public function discover(): array
    {
        $files = glob($this->migrationsDir . '/*.php') ?: [];
        $names = array_map(static fn (string $f): string => basename($f, '.php'), $files);
        sort($names);
        return $names;
    }

    /**
     * @return list<string>  filenames newly applied
     */
    public function migrate(): array
    {
        $applied = $this->applied();
        $discovered = $this->discover();
        $pending = array_values(array_diff($discovered, $applied));
        if (empty($pending)) {
            return [];
        }

        $batch = $this->nextBatch();
        $insert = $this->connection->pdo()->prepare(
            'INSERT INTO migrations (migration, batch) VALUES (:migration, :batch)'
        );
        if ($insert === false) {
            throw new RuntimeException('Could not prepare migrations insert.');
        }

        $newlyApplied = [];
        foreach ($pending as $name) {
            $migration = $this->load($name);
            $migration->up($this->connection);
            $insert->execute(['migration' => $name, 'batch' => $batch]);
            $newlyApplied[] = $name;
        }
        return $newlyApplied;
    }

    /**
     * Roll back the most recent batch.
     *
     * @return list<string>  filenames rolled back
     */
    public function rollback(): array
    {
        $applied = $this->applied();
        if (empty($applied)) {
            return [];
        }

        $maxRow = $this->connection->pdo()
            ->query('SELECT MAX(batch) FROM migrations')
            ?->fetchColumn();
        $lastBatch = $maxRow === false || $maxRow === null ? 0 : (int) $maxRow;
        if ($lastBatch === 0) {
            return [];
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT migration FROM migrations WHERE batch = :batch ORDER BY id DESC'
        );
        if ($stmt === false) {
            throw new RuntimeException('Could not prepare migrations select.');
        }
        $stmt->execute(['batch' => $lastBatch]);
        $names = $stmt->fetchAll(\PDO::FETCH_COLUMN, 0) ?: [];

        $delete = $this->connection->pdo()->prepare(
            'DELETE FROM migrations WHERE migration = :migration'
        );
        if ($delete === false) {
            throw new RuntimeException('Could not prepare migrations delete.');
        }

        $rolledBack = [];
        foreach ($names as $name) {
            $name = (string) $name;
            $migration = $this->load($name);
            $migration->down($this->connection);
            $delete->execute(['migration' => $name]);
            $rolledBack[] = $name;
        }
        return $rolledBack;
    }

    /**
     * @return array{applied: list<string>, pending: list<string>}
     */
    public function status(): array
    {
        $applied = $this->applied();
        $discovered = $this->discover();
        $pending = array_values(array_diff($discovered, $applied));
        return ['applied' => $applied, 'pending' => $pending];
    }

    private function nextBatch(): int
    {
        $row = $this->connection->pdo()
            ->query('SELECT MAX(batch) FROM migrations')
            ?->fetchColumn();
        return ($row === false || $row === null ? 0 : (int) $row) + 1;
    }

    private function load(string $name): Migration
    {
        $path = $this->migrationsDir . '/' . $name . '.php';
        if (!file_exists($path)) {
            throw new RuntimeException("Migration file not found: {$path}");
        }
        $migration = require $path;
        if (!$migration instanceof Migration) {
            throw new RuntimeException(
                "Migration file {$path} must `return new class extends Migration { ... };`"
            );
        }
        return $migration;
    }
}
