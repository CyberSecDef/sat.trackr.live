<?php

declare(strict_types=1);

namespace SatTrackr\Cli;

use DI\Container as DIContainer;
use SatTrackr\Cli\Commands\HealthCommand;
use SatTrackr\Cli\Commands\IngestCelesTrakCommand;
use SatTrackr\Cli\Commands\IngestLaunchLibraryCommand;
use SatTrackr\Cli\Commands\IngestSatCatCommand;
use SatTrackr\Cli\Commands\IngestSatnogsCommand;
use SatTrackr\Cli\Commands\IngestSocratesCommand;
use SatTrackr\Cli\Commands\IngestSpaceTrackCommand;
use SatTrackr\Cli\Commands\IngestOvationCommand;
use SatTrackr\Cli\Commands\IngestSwpcCommand;
use SatTrackr\Cli\Commands\PruneCacheCommand;
use SatTrackr\Cli\Commands\MakeMigrationCommand;
use SatTrackr\Cli\Commands\MigrateCommand;
use SatTrackr\Cli\Commands\MigrateStatusCommand;
use SatTrackr\Cli\Commands\RollbackCommand;
use Symfony\Component\Console\Application;

final class ConsoleKernel
{
    public static function create(DIContainer $container): Application
    {
        $app = new Application('sat.trackr.live console', '0.1.0');

        $app->add($container->get(MigrateCommand::class));
        $app->add($container->get(RollbackCommand::class));
        $app->add($container->get(MigrateStatusCommand::class));
        $app->add($container->get(MakeMigrationCommand::class));
        $app->add($container->get(IngestCelesTrakCommand::class));
        $app->add($container->get(IngestSatCatCommand::class));
        $app->add($container->get(IngestLaunchLibraryCommand::class));
        $app->add($container->get(IngestSpaceTrackCommand::class));
        $app->add($container->get(IngestSocratesCommand::class));
        $app->add($container->get(IngestSatnogsCommand::class));
        $app->add($container->get(IngestSwpcCommand::class));
        $app->add($container->get(IngestOvationCommand::class));
        $app->add($container->get(PruneCacheCommand::class));
        $app->add($container->get(HealthCommand::class));

        return $app;
    }
}
