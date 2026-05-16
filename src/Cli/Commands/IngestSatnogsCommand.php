<?php

declare(strict_types=1);

namespace SatTrackr\Cli\Commands;

use SatTrackr\Database\Connection;
use SatTrackr\Ingest\SatnogsIngester;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'ingest:satnogs', description: 'Pull amateur-radio transmitter catalog from SatNOGS DB (Phase 5 chunk 1)')]
final class IngestSatnogsCommand extends Command
{
    public function __construct(
        private readonly SatnogsIngester $ingester,
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('SatNOGS amateur-radio ingest');

        try {
            $report = $this->ingester->run();
        } catch (Throwable $e) {
            $io->error('Ingest aborted: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->table(
            ['Metric', 'Count'],
            [
                ['Transmitters fetched',     (string) $report['fetched']],
                ['Upserted (matched NORAD)', (string) $report['upserted']],
                ['Skipped (orphan NORAD)',   (string) $report['skipped_orphan']],
                ['Skipped (malformed)',      (string) $report['skipped_malformed']],
            ]
        );

        $io->writeln(sprintf('<info>satellite_radio:</info> %s rows', number_format($this->countRows('satellite_radio'))));
        return Command::SUCCESS;
    }

    private function countRows(string $table): int
    {
        $stmt = $this->db->pdo()->query("SELECT COUNT(*) FROM {$table}");
        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }
}
