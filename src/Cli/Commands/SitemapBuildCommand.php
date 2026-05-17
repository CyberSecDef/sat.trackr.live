<?php

declare(strict_types=1);

namespace SatTrackr\Cli\Commands;

use SatTrackr\Services\SitemapBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'sitemap:build', description: 'Regenerate sitemap.xml + chunked sitemap-{n}.xml (Phase 5 chunk 5)')]
final class SitemapBuildCommand extends Command
{
    public function __construct(
        private readonly SitemapBuilder $builder,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Sitemap build');

        try {
            $report = $this->builder->build();
        } catch (Throwable $e) {
            $io->error('Build failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Wrote %s + %d chunk%s covering %s URL(s)',
            $report['index'],
            $report['chunks'],
            $report['chunks'] === 1 ? '' : 's',
            number_format($report['urls']),
        ));
        return Command::SUCCESS;
    }
}
