<?php

declare(strict_types=1);

namespace SatTrackr\App;

use DI\Container as DIContainer;
use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use SatTrackr\Http\Controllers\SpaShellController;
use SatTrackr\Http\Middleware\ErrorHandlerMiddleware;
use SatTrackr\Services\ViteAssetResolver;

final class Container
{
    public static function build(string $rootDir): DIContainer
    {
        $builder = new ContainerBuilder();

        $builder->addDefinitions([
            'app.root_dir' => $rootDir,
            'app.public_dir' => "{$rootDir}/public",
            'app.env'  => static fn (): string => EnvLoader::get('APP_ENV', 'dev') ?? 'dev',
            'app.name' => static fn (): string => EnvLoader::get('APP_NAME', 'sat.trackr.live') ?? 'sat.trackr.live',
            'app.url'  => static fn (): string => EnvLoader::get('APP_URL', 'http://localhost:8000') ?? 'http://localhost:8000',

            LoggerInterface::class => static function () use ($rootDir): LoggerInterface {
                $logger = new Logger('app');
                $level = match (EnvLoader::get('LOG_LEVEL', 'info')) {
                    'debug'    => Level::Debug,
                    'info'     => Level::Info,
                    'notice'   => Level::Notice,
                    'warning'  => Level::Warning,
                    'error'    => Level::Error,
                    'critical' => Level::Critical,
                    default    => Level::Info,
                };
                $logger->pushHandler(new StreamHandler("{$rootDir}/storage/logs/app.log", $level));
                return $logger;
            },

            ViteAssetResolver::class => static function () use ($rootDir): ViteAssetResolver {
                return new ViteAssetResolver(
                    rootDir: $rootDir,
                    devOrigin: EnvLoader::get('VITE_DEV_ORIGIN', 'http://localhost:5173') ?? 'http://localhost:5173',
                );
            },

            SpaShellController::class => static function (DIContainer $c) use ($rootDir): SpaShellController {
                return new SpaShellController(
                    vite: $c->get(ViteAssetResolver::class),
                    rootDir: $rootDir,
                    appName: $c->get('app.name'),
                    appUrl: $c->get('app.url'),
                    cesiumIonToken: EnvLoader::get('CESIUM_ION_TOKEN', '') ?? '',
                );
            },

            ErrorHandlerMiddleware::class => static function (DIContainer $c): ErrorHandlerMiddleware {
                return new ErrorHandlerMiddleware(
                    logger: $c->get(LoggerInterface::class),
                );
            },
        ]);

        return $builder->build();
    }
}
