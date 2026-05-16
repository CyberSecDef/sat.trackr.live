<?php

declare(strict_types=1);

namespace SatTrackr\Cli\Commands;

use SatTrackr\Database\Connection;
use SatTrackr\Ingest\SwpcIngester;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'ingest:swpc', description: 'Snapshot NOAA SWPC space-weather indicators (Kp, X-ray, R/S/G)')]
final class IngestSwpcCommand extends Command
{
    public function __construct(
        private readonly SwpcIngester $ingester,
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('SWPC space-weather ingest');

        try {
            $sample = $this->ingester->run();
        } catch (Throwable $e) {
            $io->error('Ingest aborted: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->table(
            ['Indicator', 'Value'],
            [
                ['Sampled at',          (string) $sample['sampled_at']],
                ['Kp (planetary)',      $sample['kp'] !== null ? sprintf('%.2f', $sample['kp']) : '—'],
                ['X-ray flux (W/m²)',   $sample['x_ray_flux'] !== null ? sprintf('%.2E', $sample['x_ray_flux']) : '—'],
                ['X-ray class',         $sample['x_ray_class'] ?? '—'],
                ['R scale (radio)',     $sample['r_level'] !== null ? (string) $sample['r_level'] : '—'],
                ['S scale (radiation)', $sample['s_level'] !== null ? (string) $sample['s_level'] : '—'],
                ['G scale (geomag)',    $sample['g_level'] !== null ? (string) $sample['g_level'] : '—'],
            ]
        );

        $io->writeln(sprintf('<info>space_weather_samples:</info> %s rows', number_format($this->countRows('space_weather_samples'))));
        return Command::SUCCESS;
    }

    private function countRows(string $table): int
    {
        $stmt = $this->db->pdo()->query("SELECT COUNT(*) FROM {$table}");
        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }
}
