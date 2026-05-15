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
use SatTrackr\Cli\Commands\IngestLaunchLibraryCommand;
use SatTrackr\Cli\Commands\IngestSpaceTrackCommand;
use SatTrackr\Cli\Commands\IngestSatCatCommand;
use SatTrackr\Cli\Commands\MakeMigrationCommand;
use SatTrackr\Cli\Commands\MigrateCommand;
use SatTrackr\Cli\Commands\MigrateStatusCommand;
use SatTrackr\Cli\Commands\RollbackCommand;
use SatTrackr\Database\Connection;
use SatTrackr\Database\Migrator;
use SatTrackr\Http\Controllers\AutocompleteController;
use SatTrackr\Http\Controllers\GroupDetailController;
use SatTrackr\Http\Controllers\GroupListController;
use SatTrackr\Http\Controllers\GroupTlesController;
use SatTrackr\Http\Controllers\LaunchDetailController;
use SatTrackr\Http\Controllers\LaunchSiteListController;
use SatTrackr\Http\Controllers\RecentLaunchesController;
use SatTrackr\Http\Controllers\SatelliteDetailController;
use SatTrackr\Http\Controllers\SatelliteListController;
use SatTrackr\Http\Controllers\SatelliteTleController;
use SatTrackr\Http\Controllers\SearchController;
use SatTrackr\Http\Controllers\SpaShellController;
use SatTrackr\Http\Controllers\UpcomingLaunchesController;
use SatTrackr\Http\Middleware\CorsMiddleware;
use SatTrackr\Http\Middleware\ErrorHandlerMiddleware;
use SatTrackr\Http\Middleware\ETagMiddleware;
use SatTrackr\Http\Middleware\JsonResponseMiddleware;
use SatTrackr\Services\FreshnessClassifier;
use SatTrackr\Ingest\CelesTrakClient;
use SatTrackr\Ingest\CelesTrakIngester;
use SatTrackr\Ingest\LaunchLibraryClient;
use SatTrackr\Ingest\LaunchLibraryIngester;
use SatTrackr\Ingest\SpaceTrackClient;
use SatTrackr\Ingest\SpaceTrackIngester;
use SatTrackr\Ingest\SatCatClient;
use SatTrackr\Ingest\SatCatIngester;
use SatTrackr\Ingest\TleParser;
use SatTrackr\Http\Controllers\Text\TextCatalogController;
use SatTrackr\Http\Controllers\Text\TextGroupController;
use SatTrackr\Http\Controllers\Text\TextGroupsController;
use SatTrackr\Http\Controllers\Text\TextLaunchDetailController;
use SatTrackr\Http\Controllers\Text\TextLaunchListController;
use SatTrackr\Http\Controllers\Text\TextSatelliteController;
use SatTrackr\Http\Controllers\Text\TextSearchController;
use SatTrackr\Services\HttpClientFactory;
use SatTrackr\Services\TextRenderer;
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

            CorsMiddleware::class         => static fn () => new CorsMiddleware(),
            ETagMiddleware::class         => static fn () => new ETagMiddleware(),
            JsonResponseMiddleware::class => static fn () => new JsonResponseMiddleware(),

            FreshnessClassifier::class => static fn () => new FreshnessClassifier(),
            TextRenderer::class        => static fn () => new TextRenderer($rootDir),

            // Text-only catalog controllers (chunk 8)
            TextCatalogController::class => static fn (DIContainer $c) => new TextCatalogController(
                $c->get(Connection::class),
                $c->get(TextRenderer::class),
            ),
            TextSatelliteController::class => static fn (DIContainer $c) => new TextSatelliteController(
                $c->get(Connection::class),
                $c->get(TextRenderer::class),
            ),
            TextGroupsController::class => static fn (DIContainer $c) => new TextGroupsController(
                $c->get(Connection::class),
                $c->get(TextRenderer::class),
            ),
            TextGroupController::class => static fn (DIContainer $c) => new TextGroupController(
                $c->get(Connection::class),
                $c->get(TextRenderer::class),
            ),
            TextSearchController::class => static fn (DIContainer $c) => new TextSearchController(
                $c->get(Connection::class),
                $c->get(TextRenderer::class),
            ),

            // API controllers
            SatelliteListController::class   => static fn (DIContainer $c) => new SatelliteListController($c->get(Connection::class)),
            SatelliteDetailController::class => static fn (DIContainer $c) => new SatelliteDetailController(
                $c->get(Connection::class),
                $c->get(FreshnessClassifier::class),
            ),
            SatelliteTleController::class    => static fn (DIContainer $c) => new SatelliteTleController(
                $c->get(Connection::class),
                $c->get(FreshnessClassifier::class),
            ),
            GroupListController::class       => static fn (DIContainer $c) => new GroupListController($c->get(Connection::class)),
            GroupDetailController::class     => static fn (DIContainer $c) => new GroupDetailController($c->get(Connection::class)),
            GroupTlesController::class       => static fn (DIContainer $c) => new GroupTlesController($c->get(Connection::class)),
            SearchController::class          => static fn (DIContainer $c) => new SearchController($c->get(Connection::class)),
            AutocompleteController::class    => static fn (DIContainer $c) => new AutocompleteController($c->get(Connection::class)),

            // Launch endpoints (Phase 2 chunk 3)
            UpcomingLaunchesController::class => static fn (DIContainer $c) => new UpcomingLaunchesController($c->get(Connection::class)),
            RecentLaunchesController::class   => static fn (DIContainer $c) => new RecentLaunchesController($c->get(Connection::class)),
            LaunchDetailController::class     => static fn (DIContainer $c) => new LaunchDetailController($c->get(Connection::class)),
            LaunchSiteListController::class   => static fn (DIContainer $c) => new LaunchSiteListController($c->get(Connection::class)),

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
            SatCatClient::class    => static fn (DIContainer $c) => new SatCatClient($c->get(GuzzleClient::class)),
            SatCatIngester::class  => static fn (DIContainer $c) => new SatCatIngester(
                client: $c->get(SatCatClient::class),
                db:     $c->get(Connection::class),
                logger: $c->get(LoggerInterface::class),
            ),
            LaunchLibraryClient::class => static fn (DIContainer $c) => new LaunchLibraryClient(
                $c->get(GuzzleClient::class),
                EnvLoader::get('LL2_API_TOKEN', '') ?? '',
            ),
            LaunchLibraryIngester::class => static fn (DIContainer $c) => new LaunchLibraryIngester(
                client: $c->get(LaunchLibraryClient::class),
                db:     $c->get(Connection::class),
                logger: $c->get(LoggerInterface::class),
            ),
            SpaceTrackClient::class => static fn (DIContainer $c) => new SpaceTrackClient(
                $c->get(GuzzleClient::class),
                EnvLoader::get('SPACE_TRACK_USER', '') ?? '',
                EnvLoader::get('SPACE_TRACK_PASS', '') ?? '',
            ),
            SpaceTrackIngester::class => static fn (DIContainer $c) => new SpaceTrackIngester(
                client: $c->get(SpaceTrackClient::class),
                db:     $c->get(Connection::class),
                logger: $c->get(LoggerInterface::class),
            ),

            // CLI commands
            MigrateCommand::class         => static fn (DIContainer $c) => new MigrateCommand($c->get(Migrator::class)),
            RollbackCommand::class        => static fn (DIContainer $c) => new RollbackCommand($c->get(Migrator::class)),
            MigrateStatusCommand::class   => static fn (DIContainer $c) => new MigrateStatusCommand($c->get(Migrator::class)),
            MakeMigrationCommand::class   => static fn () => new MakeMigrationCommand($rootDir . '/migrations'),
            IngestCelesTrakCommand::class => static fn (DIContainer $c) => new IngestCelesTrakCommand(
                $c->get(CelesTrakIngester::class),
                $c->get(Connection::class),
            ),
            IngestSatCatCommand::class => static fn (DIContainer $c) => new IngestSatCatCommand(
                $c->get(SatCatIngester::class),
                $c->get(Connection::class),
            ),
            IngestLaunchLibraryCommand::class => static fn (DIContainer $c) => new IngestLaunchLibraryCommand(
                $c->get(LaunchLibraryIngester::class),
                $c->get(Connection::class),
            ),
            IngestSpaceTrackCommand::class => static fn (DIContainer $c) => new IngestSpaceTrackCommand(
                $c->get(SpaceTrackIngester::class),
                $c->get(Connection::class),
            ),
            HealthCommand::class          => static fn (DIContainer $c) => new HealthCommand(
                $c->get(Connection::class),
                $c->get(Migrator::class),
            ),
        ]);

        return $builder->build();
    }
}
