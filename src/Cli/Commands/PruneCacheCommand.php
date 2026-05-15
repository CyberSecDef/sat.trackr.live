<?php

declare(strict_types=1);

namespace SatTrackr\Cli\Commands;

use SatTrackr\Services\PassCache;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'pass-cache:prune', description: 'Sweep expired rows from the pass_cache table (Phase 2 chunk 6)')]
final class PruneCacheCommand extends Command
{
    public function __construct(
        private readonly PassCache $cache,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $deleted = $this->cache->prune();
        $io->success("Pruned {$deleted} expired pass-cache row(s).");
        return Command::SUCCESS;
    }
}
