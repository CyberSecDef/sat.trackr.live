<?php

declare(strict_types=1);

namespace SatTrackr\Cli\Commands;

use SatTrackr\Database\Connection;
use SatTrackr\Ingest\SatCatIngester;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'ingest:satcat', description: 'Pull SATCAT metadata from CelesTrak and enrich the satellites catalog')]
final class IngestSatCatCommand extends Command
{
    public function __construct(
        private readonly SatCatIngester $ingester,
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'group',
            'g',
            InputOption::VALUE_REQUIRED,
            'Ingest only this CelesTrak group slug. Default: all configured groups.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $group = $input->getOption('group');
        $groups = $group !== null ? [(string) $group] : [];

        $io->title($group !== null
            ? "SATCAT ingest — group '{$group}'"
            : 'SATCAT ingest — all configured groups');

        try {
            $report = $this->ingester->run(
                $groups,
                static function (string $g, int $touched, float $seconds) use ($io): void {
                    $io->writeln(sprintf(
                        '  <info>%-15s</info>  %5d updated in %5.2fs',
                        $g,
                        $touched,
                        $seconds
                    ));
                }
            );
        } catch (Throwable $e) {
            $io->error('SATCAT ingest aborted: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->newLine();
        $io->section('Summary');
        $io->table(
            ['Metric', 'Count'],
            [
                ['Groups processed',          (string) $report->groupsProcessed],
                ['Groups skipped (no update)', (string) $report->groupsSkippedNotModified],
                ['SATCAT records seen',       (string) $report->recordsSeen],
                ['Satellites updated',        (string) $report->satellitesUpdated],
                ['SATCAT NORADs not in catalog', (string) $report->satellitesUnknown],
                ['Purposes derived',          (string) $report->purposesDerived],
                ['Errors',                    (string) count($report->errors)],
                ['Duration (s)',              (string) round($report->durationSeconds(), 2)],
            ]
        );

        $io->newLine();
        $io->writeln('<info>Database after ingest:</info>');
        $io->writeln(sprintf('  satellites:           %s rows', number_format($this->countRows('satellites'))));
        $io->writeln(sprintf('  satellites enriched:  %s rows (status != UNKNOWN)', number_format($this->countEnriched())));
        $io->writeln(sprintf('  satellite_purposes:   %s rows', number_format($this->countRows('satellite_purposes'))));

        if (count($report->errors) > 0) {
            $io->warning(sprintf('%d group(s) failed:', count($report->errors)));
            foreach ($report->errors as $err) {
                $io->writeln("  <comment>{$err['group']}</comment>: {$err['error']}");
            }
        }

        return $report->groupsProcessed > 0 || $report->groupsSkippedNotModified > 0
            ? Command::SUCCESS
            : Command::FAILURE;
    }

    private function countRows(string $table): int
    {
        $stmt = $this->db->pdo()->query("SELECT COUNT(*) FROM {$table}");
        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    private function countEnriched(): int
    {
        $stmt = $this->db->pdo()->query("SELECT COUNT(*) FROM satellites WHERE status != 'UNKNOWN'");
        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }
}
