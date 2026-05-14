<?php

declare(strict_types=1);

namespace SatTrackr\Cli\Commands;

use SatTrackr\Database\Connection;
use SatTrackr\Database\Migrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'health', description: 'Check application health (DB connectivity, tables, migrations)')]
final class HealthCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Migrator $migrator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('sat.trackr.live health check');

        $rows = [];

        // PHP version
        $rows[] = ['PHP version', PHP_VERSION, PHP_VERSION_ID >= 80400 ? '<info>ok</info>' : '<error>need 8.4+</error>'];

        // PDO sqlite available
        $hasSqlite = extension_loaded('pdo_sqlite');
        $rows[] = ['ext: pdo_sqlite', $hasSqlite ? 'loaded' : 'MISSING', $hasSqlite ? '<info>ok</info>' : '<error>fail</error>'];

        // DB connection
        try {
            $sqliteVersion = $this->connection->pdo()->query('SELECT sqlite_version()')?->fetchColumn();
            $rows[] = ['DB connection', "sqlite {$sqliteVersion}", '<info>ok</info>'];
        } catch (Throwable $e) {
            $rows[] = ['DB connection', $e->getMessage(), '<error>fail</error>'];
            $io->table(['Check', 'Value', 'Status'], $rows);
            return Command::FAILURE;
        }

        // Migrations applied
        $status = $this->migrator->status();
        $rows[] = [
            'Migrations',
            sprintf('%d applied, %d pending', count($status['applied']), count($status['pending'])),
            empty($status['pending']) ? '<info>up to date</info>' : '<comment>pending</comment>',
        ];

        // Table row counts (only after migrations exist)
        $tables = [
            'satellites', 'tle_current', 'tle_history', 'satellite_purposes',
            'group_membership', 'launch_sites', 'launches', 'reentries', 'pass_cache',
        ];
        foreach ($tables as $table) {
            try {
                $count = $this->connection->pdo()
                    ->query("SELECT COUNT(*) FROM {$table}")
                    ?->fetchColumn();
                if ($count !== false) {
                    $rows[] = ["table: {$table}", number_format((int) $count) . ' rows', '<info>ok</info>'];
                }
            } catch (Throwable) {
                $rows[] = ["table: {$table}", 'not created', '<comment>migrate first</comment>'];
            }
        }

        // SATCAT enrichment coverage (Phase 2 chunk 1+).
        try {
            $total = (int) $this->connection->pdo()->query('SELECT COUNT(*) FROM satellites')->fetchColumn();
            $enriched = (int) $this->connection->pdo()
                ->query("SELECT COUNT(*) FROM satellites WHERE status != 'UNKNOWN'")
                ->fetchColumn();
            $pct = $total > 0 ? (100.0 * $enriched / $total) : 0.0;
            $statusLabel = $total === 0
                ? '<comment>no catalog</comment>'
                : ($pct >= 90 ? '<info>healthy</info>' : '<comment>run ingest:satcat</comment>');
            $rows[] = [
                'SATCAT enriched',
                sprintf('%s / %s satellites (%.1f%%)', number_format($enriched), number_format($total), $pct),
                $statusLabel,
            ];
        } catch (Throwable) {
            // ignore — table may not exist yet
        }

        $io->table(['Check', 'Value', 'Status'], $rows);
        return Command::SUCCESS;
    }
}
