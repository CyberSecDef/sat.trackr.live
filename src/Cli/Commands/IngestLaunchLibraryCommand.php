<?php

declare(strict_types=1);

namespace SatTrackr\Cli\Commands;

use SatTrackr\Database\Connection;
use SatTrackr\Ingest\LaunchLibraryIngester;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'ingest:ll2', description: 'Pull upcoming + previous launches from Launch Library 2')]
final class IngestLaunchLibraryCommand extends Command
{
    public function __construct(
        private readonly LaunchLibraryIngester $ingester,
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'mode',
            'm',
            InputOption::VALUE_REQUIRED,
            'Which list to refresh: upcoming | previous | both',
            'both'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $mode = (string) ($input->getOption('mode') ?? 'both');
        if (!in_array($mode, ['upcoming', 'previous', 'both'], true)) {
            $io->error("Invalid mode '{$mode}'. Use upcoming, previous, or both.");
            return Command::FAILURE;
        }

        $io->title("Launch Library 2 ingest — mode: {$mode}");

        try {
            $report = $this->ingester->run($mode);
        } catch (Throwable $e) {
            $io->error('LL2 ingest aborted: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->section('Summary');
        $io->table(
            ['Metric', 'Count'],
            [
                ['Upcoming launches fetched', (string) $report->upcomingFetched],
                ['Previous launches fetched', (string) $report->previousFetched],
                ['Launches upserted',         (string) $report->launchesUpserted],
                ['Pads upserted',             (string) $report->padsUpserted],
                ['Launches rejected',         (string) $report->launchesRejected],
                ['Errors',                    (string) count($report->errors)],
                ['Duration (s)',              (string) round($report->durationSeconds(), 2)],
            ]
        );

        $io->newLine();
        $io->writeln('<info>Database after ingest:</info>');
        $io->writeln(sprintf('  launches:     %s rows', number_format($this->countRows('launches'))));
        $io->writeln(sprintf('  launch_sites: %s rows', number_format($this->countRows('launch_sites'))));

        if (count($report->errors) > 0) {
            $io->warning(sprintf('%d ingest mode(s) failed:', count($report->errors)));
            foreach ($report->errors as $err) {
                $io->writeln("  <comment>{$err['mode']}</comment>: {$err['error']}");
            }
        }

        return $report->launchesUpserted > 0 || count($report->errors) === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function countRows(string $table): int
    {
        $stmt = $this->db->pdo()->query("SELECT COUNT(*) FROM {$table}");
        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }
}
