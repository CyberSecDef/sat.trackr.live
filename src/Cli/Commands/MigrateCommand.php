<?php

declare(strict_types=1);

namespace SatTrackr\Cli\Commands;

use SatTrackr\Database\Migrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'migrate', description: 'Apply pending migrations')]
final class MigrateCommand extends Command
{
    public function __construct(
        private readonly Migrator $migrator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $applied = $this->migrator->migrate();
        } catch (Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if (empty($applied)) {
            $io->success('Nothing to migrate.');
            return Command::SUCCESS;
        }

        $io->success(sprintf('Applied %d migration(s):', count($applied)));
        foreach ($applied as $name) {
            $io->writeln("  <info>✓</info> {$name}");
        }
        return Command::SUCCESS;
    }
}
