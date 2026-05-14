<?php

declare(strict_types=1);

namespace SatTrackr\Cli\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'make:migration', description: 'Generate a new migration skeleton')]
final class MakeMigrationCommand extends Command
{
    public function __construct(
        private readonly string $migrationsDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Migration name (e.g. add_foo_to_satellites)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = (string) $input->getArgument('name');

        $slug = preg_replace('/[^a-z0-9_]/', '_', strtolower($name)) ?? $name;
        $timestamp = date('Y_m_d_His');
        $filename = "{$timestamp}_{$slug}.php";
        $path = $this->migrationsDir . '/' . $filename;

        if (!is_dir($this->migrationsDir)) {
            mkdir($this->migrationsDir, 0o755, true);
        }

        $template = <<<'PHP'
            <?php

            declare(strict_types=1);

            use SatTrackr\Database\Connection;
            use SatTrackr\Database\Migration;

            return new class extends Migration {
                public function up(Connection $connection): void
                {
                    $connection->pdo()->exec(<<<'SQL'
                        -- TODO: forward migration
                    SQL);
                }

                public function down(Connection $connection): void
                {
                    $connection->pdo()->exec(<<<'SQL'
                        -- TODO: reverse migration
                    SQL);
                }
            };
            PHP;

        file_put_contents($path, $template . "\n");
        $io->success("Created migration: {$filename}");
        $io->writeln("  <info>{$path}</info>");
        return Command::SUCCESS;
    }
}
