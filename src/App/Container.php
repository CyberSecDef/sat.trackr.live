<?php

declare(strict_types=1);

namespace SatTrackr\App;

use DI\Container as DIContainer;
use DI\ContainerBuilder;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use SatTrackr\Cli\Commands\HealthCommand;
use SatTrackr\Cli\Commands\IngestCelesTrakCommand;
use SatTrackr\Cli\Commands\MakeMigrationCommand;
use SatTrackr\Cli\Commands\MigrateCommand;
use SatTrackr\Cli\Commands\MigrateStatusCommand;
use SatTrackr\Cli\Commands\RollbackCommand;
use SatTrackr\Database\Connection;
use SatTrackr\Database\Migrator;
use SatTrackr\Http\Controllers\SpaShellController;
use SatTrackr\Http\Middleware\ErrorHandlerMiddleware;
use SatTrackr\Ingest\CelesTrakClient;
use SatTrackr\Ingest\CelesTrakIngester;
use SatTrackr\Ingest\TleParser;
use SatTrackr\Services\HttpClientFactory;
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

            HttpClientFactory::class    => static fn (): HttpClientFactory => new HttpClientFactory(),
            GuzzleClient::class         => static fn (DIContainer $c): GuzzleClient => $c->get(HttpClientFactory::class)->create(),
            GuzzleClientInterface::class => static fn (DIContainer $c): GuzzleClient => $c->get(GuzzleClient::class),

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

            Connection::class => static function () use ($rootDir): Connection {
                $dbPath = EnvLoader::get('DB_PATH', 'data/sat.db') ?? 'data/sat.db';
                if (!str_starts_with($dbPath, '/') && $dbPath !== ':memory:') {
                    $dbPath = $rootDir . '/' . $dbPath;
                }
                return new Connection($dbPath);
            },

            Migrator::class => static function (DIContainer $c) use ($rootDir): Migrator {
                return new Migrator(
                    connection: $c->get(Connection::class),
                    migrationsDir: $rootDir . '/migrations',
                );
            },

            // Ingest
            TleParser::class       => static fn () => new TleParser(),
            CelesTrakClient::class => static fn (DIContainer $c) => new CelesTrakClient($c->get(GuzzleClient::class)),
            CelesTrakIngester::class => static fn (DIContainer $c) => new CelesTrakIngester(
                client: $c->get(CelesTrakClient::class),
                parser: $c->get(TleParser::class),
                db:     $c->get(Connection::class),
                logger: $c->get(LoggerInterface::class),
            ),

            // CLI commands
            MigrateCommand::class         => static fn (DIContainer $c) => new MigrateCommand($c->get(Migrator::class)),
            RollbackCommand::class        => static fn (DIContainer $c) => new RollbackCommand($c->get(Migrator::class)),
            MigrateStatusCommand::class   => static fn (DIContainer $c) => new MigrateStatusCommand($c->get(Migrator::class)),
            MakeMigrationCommand::class   => static fn () => new MakeMigrationCommand($rootDir . '/migrations'),
            IngestCelesTrakCommand::class => static fn (DIContainer $c) => new IngestCelesTrakCommand($c->get(CelesTrakIngester::class)),
            HealthCommand::class          => static fn (DIContainer $c) => new HealthCommand(
                $c->get(Connection::class),
                $c->get(Migrator::class),
            ),
        ]);

        return $builder->build();
    }
}
