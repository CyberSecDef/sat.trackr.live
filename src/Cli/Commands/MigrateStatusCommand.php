<?php

declare(strict_types=1);

namespace SatTrackr\Cli\Commands;

use SatTrackr\Database\Migrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'migrate:status', description: 'Show applied and pending migrations')]
final class MigrateStatusCommand extends Command
{
    public function __construct(
        private readonly Migrator $migrator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $status = $this->migrator->status();

        $rows = [];
        foreach ($status['applied'] as $name) {
            $rows[] = [$name, '<info>applied</info>'];
        }
        foreach ($status['pending'] as $name) {
            $rows[] = [$name, '<comment>pending</comment>'];
        }

        if (empty($rows)) {
            $io->writeln('No migrations found.');
            return Command::SUCCESS;
        }

        $io->table(['Migration', 'Status'], $rows);
        $io->writeln(sprintf(
            '<info>%d applied</info>, <comment>%d pending</comment>',
            count($status['applied']),
            count($status['pending']),
        ));

        return Command::SUCCESS;
    }
}
