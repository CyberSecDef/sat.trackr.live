<?php

declare(strict_types=1);

namespace SatTrackr\Cli\Commands;

use SatTrackr\Database\Connection;
use SatTrackr\Ingest\SocratesIngester;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'ingest:socrates', description: 'Pull close-approach predictions from CelesTrak SOCRATES Plus')]
final class IngestSocratesCommand extends Command
{
    public function __construct(
        private readonly SocratesIngester $ingester,
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'max-tca-hours',
            't',
            InputOption::VALUE_REQUIRED,
            'Only keep conjunctions with TCA within the next N hours.  Default 168 (7 days); CelesTrak SOCRATES carries ~30 days.',
            '168'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $maxHours = max(1, (int) ($input->getOption('max-tca-hours') ?? 168));

        $io->title("SOCRATES ingest — TCA window {$maxHours}h");

        try {
            $report = $this->ingester->run($maxHours);
        } catch (Throwable $e) {
            $io->error('Ingest aborted: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->section('Summary');
        $io->table(
            ['Metric', 'Count'],
            [
                ['CSV rows fetched',  (string) $report->rowsFetched],
                ['Rows parsed',       (string) $report->rowsParsed],
                ['Conjunctions upserted (in window)', (string) $report->upserted],
                ['Skipped malformed', (string) $report->skippedMalformed],
                ['Errors',            (string) count($report->errors)],
                ['Duration (s)',      (string) round($report->durationSeconds(), 2)],
            ]
        );

        $io->newLine();
        $io->writeln('<info>Database after ingest:</info>');
        $io->writeln(sprintf('  conjunctions: %s rows', number_format($this->countRows('conjunctions'))));

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
