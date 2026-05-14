<?php

declare(strict_types=1);

namespace SatTrackr\App;

use SatTrackr\Http\Controllers\SpaShellController;
use SatTrackr\Http\Middleware\ErrorHandlerMiddleware;
use Slim\App;
use Slim\Factory\AppFactory;

final class Kernel
{
    public static function createApp(string $rootDir): App
    {
        EnvLoader::load($rootDir);

        $container = Container::build($rootDir);
        AppFactory::setContainer($container);

        $app = AppFactory::create();
        $app->addRoutingMiddleware();
        $app->add($container->get(ErrorHandlerMiddleware::class));

        self::registerRoutes($app);

        return $app;
    }

    private static function registerRoutes(App $app): void
    {
        // SPA routes — render the shell, client-side router takes over.
        $app->get('/', SpaShellController::class);
        $app->get('/satellite/{norad:[0-9]+}', SpaShellController::class);

        // /api/v1/* routes land in chunk 4 (API endpoints).
    }
}
