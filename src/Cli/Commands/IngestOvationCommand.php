<?php

declare(strict_types=1);

namespace SatTrackr\Cli\Commands;

use SatTrackr\Ingest\OvationIngester;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'ingest:ovation', description: 'Refresh the OVATION aurora-forecast raster (Phase 4 chunk 4)')]
final class IngestOvationCommand extends Command
{
    public function __construct(
        private readonly OvationIngester $ingester,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('OVATION aurora-forecast ingest');

        try {
            $result = $this->ingester->run();
        } catch (Throwable $e) {
            $io->error('Ingest aborted: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->table(
            ['Field', 'Value'],
            [
                ['Observation time', $result['observation_time']],
                ['Forecast time',    $result['forecast_time']],
                ['Cells painted',    (string) $result['cells_painted']],
                ['Duration (s)',     (string) round($result['duration_seconds'], 2)],
                ['Output PNG',       'public/textures/aurora-latest.png'],
            ]
        );
        return Command::SUCCESS;
    }
}
