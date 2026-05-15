<?php

declare(strict_types=1);

namespace SatTrackr\Cli\Commands;

use SatTrackr\Database\Connection;
use SatTrackr\Ingest\SpaceTrackIngester;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'ingest:spacetrack', description: 'Pull TIP (decay prediction) messages from Space-Track')]
final class IngestSpaceTrackCommand extends Command
{
    public function __construct(
        private readonly SpaceTrackIngester $ingester,
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            'l',
            InputOption::VALUE_REQUIRED,
            'Max number of TIP records to pull (newest first)',
            '100'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $limit = max(1, (int) ($input->getOption('limit') ?? 100));

        $io->title("Space-Track ingest — TIP messages, limit {$limit}");

        try {
            $report = $this->ingester->run($limit);
        } catch (Throwable $e) {
            $io->error('Space-Track ingest aborted: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->section('Summary');
        $io->table(
            ['Metric', 'Count'],
            [
                ['TIP records fetched',     (string) $report->tipsFetched],
                ['Reentries upserted',      (string) $report->reentriesUpserted],
                ['Skipped (unknown NORAD)', (string) $report->skippedUnknownNorad],
                ['Skipped (malformed)',     (string) $report->skippedMalformed],
                ['Errors',                  (string) count($report->errors)],
                ['Duration (s)',            (string) round($report->durationSeconds(), 2)],
            ]
        );

        $io->newLine();
        $io->writeln('<info>Database after ingest:</info>');
        $io->writeln(sprintf('  reentries: %s rows', number_format($this->countRows('reentries'))));

        if (count($report->errors) > 0) {
            $io->warning(sprintf('%d error(s):', count($report->errors)));
            foreach ($report->errors as $err) {
                $io->writeln("  <comment>{$err['stage']}</comment>: {$err['error']}");
            }
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function countRows(string $table): int
    {
        $stmt = $this->db->pdo()->query("SELECT COUNT(*) FROM {$table}");
        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }
}
