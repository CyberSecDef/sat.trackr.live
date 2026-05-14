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

#[AsCommand(name: 'migrate:rollback', description: 'Roll back the most recent migration batch')]
final class RollbackCommand extends Command
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
            $rolledBack = $this->migrator->rollback();
        } catch (Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if (empty($rolledBack)) {
            $io->success('Nothing to roll back.');
            return Command::SUCCESS;
        }

        $io->success(sprintf('Rolled back %d migration(s):', count($rolledBack)));
        foreach ($rolledBack as $name) {
            $io->writeln("  <comment>↶</comment> {$name}");
        }
        return Command::SUCCESS;
    }
}
