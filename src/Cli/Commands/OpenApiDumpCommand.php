<?php

declare(strict_types=1);

namespace SatTrackr\Cli\Commands;

use SatTrackr\Services\OpenApiGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'openapi:dump', description: 'Emit the live OpenAPI 3.1 spec as JSON (Phase 5 chunk 3)')]
final class OpenApiDumpCommand extends Command
{
    public function __construct(
        private readonly OpenApiGenerator $generator,
        private readonly string $rootDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('out', InputArgument::OPTIONAL, 'Output path. Default: public/openapi.json', 'public/openapi.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $out = (string) $input->getArgument('out');
        if (!str_starts_with($out, '/')) {
            $out = $this->rootDir . '/' . $out;
        }

        $json = $this->generator->generateJson();
        $bytes = (int) file_put_contents($out, $json);
        if ($bytes <= 0) {
            $io->error("Failed to write {$out}");
            return Command::FAILURE;
        }

        $spec  = json_decode($json, true);
        $paths = is_array($spec) ? count($spec['paths'] ?? []) : 0;
        $io->success(sprintf('Wrote %s (%s bytes, %d paths)', $out, number_format($bytes), $paths));
        return Command::SUCCESS;
    }
}
