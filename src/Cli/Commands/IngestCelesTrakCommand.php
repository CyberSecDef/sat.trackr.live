<?php

declare(strict_types=1);

namespace SatTrackr\Cli\Commands;

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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'group',
            'g',
            InputOption::VALUE_REQUIRED,
            'Ingest only this CelesTrak group slug (e.g. "starlink"). Default: all configured groups.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $group = $input->getOption('group');
        $groups = $group !== null ? [(string) $group] : [];

        $io->title($group !== null
            ? "CelesTrak ingest — group '{$group}'"
            : 'CelesTrak ingest — all configured groups');

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
                }
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
                ['Groups processed',     (string) $report->groupsProcessed],
                ['Satellites upserted',  (string) $report->satellitesUpserted],
                ['TLE current upserted', (string) $report->tleCurrentUpserted],
                ['TLE history added',    (string) $report->tleHistoryAdded],
                ['TLE rejected',         (string) $report->tleRejected],
                ['Errors',               (string) count($report->errors)],
                ['Duration (s)',         (string) round($report->durationSeconds(), 2)],
            ]
        );

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
