<?php

declare(strict_types=1);

namespace SatTrackr\Database;

/**
 * Base class for migration files. Each migration file in migrations/ should
 * `return new class extends Migration { ... };` with up() and down() methods
 * that operate on the provided Connection (raw SQL via $connection->pdo()
 * or builder via $connection->capsule()).
 */
abstract class Migration
{
    abstract public function up(Connection $connection): void;

    abstract public function down(Connection $connection): void;
}
