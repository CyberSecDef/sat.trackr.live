<?php

declare(strict_types=1);

namespace SatTrackr\Cli\Commands;

use SatTrackr\Database\Connection;
use SatTrackr\Ingest\CelesTrakIngester;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'ingest:celestrak', description: 'Pull TLEs from CelesTrak and upsert into the catalog')]
final class IngestCelesTrakCommand extends Command
{
    public function __construct(
        private readonly CelesTrakIngester $ingester,
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    private function countRows(string $table): int
    {
        $stmt = $this->db->pdo()->query("SELECT COUNT(*) FROM {$table}");
        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    protected function configure(): void
    {
        $this->addOption(
            'group',
            'g',
            InputOption::VALUE_REQUIRED,
            'Ingest only this CelesTrak group slug (e.g. "starlink"). Default: all configured groups.'
        );
        $this->addOption(
            'format',
            'f',
            InputOption::VALUE_REQUIRED,
            'Source format: tle (default, legacy) or json (OMM, forward-compatible past 6-digit NORAD transition).',
            CelesTrakIngester::FORMAT_TLE,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $group = $input->getOption('group');
        $groups = $group !== null ? [(string) $group] : [];
        $format = strtolower((string) ($input->getOption('format') ?? CelesTrakIngester::FORMAT_TLE));
        if (!in_array($format, [CelesTrakIngester::FORMAT_TLE, CelesTrakIngester::FORMAT_JSON], true)) {
            $io->error("Invalid --format '{$format}'. Use tle or json.");
            return Command::FAILURE;
        }

        $io->title($group !== null
            ? "CelesTrak ingest — group '{$group}' (FORMAT={$format})"
            : "CelesTrak ingest — all configured groups (FORMAT={$format})");

        try {
            $report = $this->ingester->run(
                $groups,
                static function (string $g, int $records, float $seconds) use ($io): void {
                    $io->writeln(sprintf(
                        '  <info>%-15s</info>  %5d records in %5.2fs',
                        $g,
                        $records,
                        $seconds
                    ));
                },
                $format,
            );
        } catch (Throwable $e) {
            $io->error('Ingest aborted: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->newLine();
        $io->section('Summary');
        $io->table(
            ['Metric', 'Count'],
            [
                ['Groups processed',          (string) $report->groupsProcessed],
                ['Groups skipped (no update)', (string) $report->groupsSkippedNotModified],
                ['TLE upsert ops',            (string) $report->satellitesUpserted],
                ['TLE history rows added',    (string) $report->tleHistoryAdded],
                ['TLE records rejected',      (string) $report->tleRejected],
                ['Errors',                    (string) count($report->errors)],
                ['Duration (s)',              (string) round($report->durationSeconds(), 2)],
            ]
        );

        // Show actual table sizes too — distinct from the upsert ops metric above.
        $io->newLine();
        $io->writeln('<info>Database after ingest:</info>');
        $io->writeln(sprintf('  satellites:   %s rows', number_format($this->countRows('satellites'))));
        $io->writeln(sprintf('  tle_current:  %s rows', number_format($this->countRows('tle_current'))));
        $io->writeln(sprintf('  tle_history:  %s rows', number_format($this->countRows('tle_history'))));

        if (count($report->errors) > 0) {
            $io->warning(sprintf('%d group(s) failed:', count($report->errors)));
            foreach ($report->errors as $err) {
                $io->writeln("  <comment>{$err['group']}</comment>: {$err['error']}");
            }
        }

        if ($report->tleRejected > 0) {
            $io->writeln('');
            $io->writeln(sprintf('<comment>%d record(s) rejected during parse.</comment> First 5:', $report->tleRejected));
            foreach (array_slice($report->rejects, 0, 5) as $rej) {
                $io->writeln(sprintf(
                    '  [%s] norad=%s — %s',
                    $rej['group'],
                    $rej['norad'] ?? '?',
                    $rej['reason']
                ));
            }
        }

        return $report->groupsProcessed > 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
