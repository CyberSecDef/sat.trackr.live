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
use SatTrackr\Cli\Commands\IngestSatnogsCommand;
use SatTrackr\Cli\Commands\IngestSocratesCommand;
use SatTrackr\Cli\Commands\IngestOvationCommand;
use SatTrackr\Cli\Commands\IngestSpaceTrackCommand;
use SatTrackr\Cli\Commands\IngestSwpcCommand;
use SatTrackr\Cli\Commands\PruneCacheCommand;
use SatTrackr\Cli\Commands\IngestSatCatCommand;
use SatTrackr\Cli\Commands\MakeMigrationCommand;
use SatTrackr\Cli\Commands\MigrateCommand;
use SatTrackr\Cli\Commands\MigrateStatusCommand;
use SatTrackr\Cli\Commands\OpenApiDumpCommand;
use SatTrackr\Cli\Commands\RollbackCommand;
use SatTrackr\Cli\Commands\SitemapBuildCommand;
use SatTrackr\Database\Connection;
use SatTrackr\Database\Migrator;
use SatTrackr\Http\Controllers\AutocompleteController;
use SatTrackr\Http\Controllers\OgImageController;
use SatTrackr\Http\Controllers\OpenApiController;
use SatTrackr\Http\Controllers\SwaggerUiController;
use SatTrackr\Http\Controllers\GroupDetailController;
use SatTrackr\Http\Controllers\GroupListController;
use SatTrackr\Http\Controllers\GroupTlesController;
use SatTrackr\Http\Controllers\LaunchDetailController;
use SatTrackr\Http\Controllers\LaunchSiteListController;
use SatTrackr\Http\Controllers\RecentLaunchesController;
use SatTrackr\Http\Controllers\SatelliteDetailController;
use SatTrackr\Http\Controllers\SatelliteListController;
use SatTrackr\Http\Controllers\SatelliteRadioController;
use SatTrackr\Http\Controllers\SatelliteTleController;
use SatTrackr\Http\Controllers\SearchController;
use SatTrackr\Http\Controllers\SpaShellController;
use SatTrackr\Http\Controllers\ConjunctionDetailController;
use SatTrackr\Http\Controllers\ConjunctionListController;
use SatTrackr\Http\Controllers\ReentryDetailController;
use SatTrackr\Http\Controllers\ReentryListController;
use SatTrackr\Http\Controllers\SatellitePassesController;
use SatTrackr\Http\Controllers\SpaceWeather24hController;
use SatTrackr\Http\Controllers\SpaceWeatherNowController;
use SatTrackr\Http\Controllers\StatsController;
use SatTrackr\Http\Controllers\UpcomingLaunchesController;
use SatTrackr\Http\Middleware\CorsMiddleware;
use SatTrackr\Http\Middleware\ErrorHandlerMiddleware;
use SatTrackr\Http\Middleware\ETagMiddleware;
use SatTrackr\Http\Middleware\JsonResponseMiddleware;
use SatTrackr\Services\AtomGenerator;
use SatTrackr\Services\EventsAggregator;
use SatTrackr\Services\FreshnessClassifier;
use SatTrackr\Services\N2YOClient;
use SatTrackr\Services\PassCache;
use SatTrackr\Services\PassCalculator;
use SatTrackr\Services\PassCalculatorInterface;
use SatTrackr\Services\PassMagnitudeEnricher;
use SatTrackr\Ingest\CelesTrakClient;
use SatTrackr\Ingest\CelesTrakIngester;
use SatTrackr\Ingest\LaunchLibraryClient;
use SatTrackr\Ingest\LaunchLibraryIngester;
use SatTrackr\Ingest\SpaceTrackClient;
use SatTrackr\Ingest\SatnogsClient;
use SatTrackr\Ingest\SatnogsIngester;
use SatTrackr\Ingest\SocratesClient;
use SatTrackr\Ingest\SocratesCsvParser;
use SatTrackr\Ingest\SocratesIngester;
use SatTrackr\Ingest\AuroraRasterGenerator;
use SatTrackr\Ingest\OvationClient;
use SatTrackr\Ingest\OvationIngester;
use SatTrackr\Ingest\SpaceTrackIngester;
use SatTrackr\Ingest\SwpcClient;
use SatTrackr\Ingest\SwpcIngester;
use SatTrackr\Ingest\SatCatClient;
use SatTrackr\Ingest\SatCatIngester;
use SatTrackr\Ingest\TleParser;
use SatTrackr\Http\Controllers\AtomEventsController;
use SatTrackr\Http\Controllers\Text\TextCatalogController;
use SatTrackr\Http\Controllers\Text\TextConjunctionListController;
use SatTrackr\Http\Controllers\Text\TextDecaysController;
use SatTrackr\Http\Controllers\Text\TextEventsController;
use SatTrackr\Http\Controllers\Text\TextSpaceWeatherController;
use SatTrackr\Http\Controllers\Text\TextGroupController;
use SatTrackr\Http\Controllers\Text\TextGroupsController;
use SatTrackr\Http\Controllers\Text\TextLaunchDetailController;
use SatTrackr\Http\Controllers\Text\TextLaunchListController;
use SatTrackr\Http\Controllers\Text\TextSatelliteController;
use SatTrackr\Http\Controllers\Text\TextSearchController;
use SatTrackr\Services\HttpClientFactory;
use SatTrackr\Services\OgImageGenerator;
use SatTrackr\Services\OpenApiGenerator;
use SatTrackr\Services\SitemapBuilder;
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
                    db: $c->get(Connection::class),
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
            // OpenAPI 3.1 generator + controllers (Phase 5 chunk 3)
            OpenApiGenerator::class    => static fn () => new OpenApiGenerator($rootDir),
            OpenApiController::class   => static fn (DIContainer $c) => new OpenApiController($c->get(OpenApiGenerator::class)),
            SwaggerUiController::class => static fn () => new SwaggerUiController(),
            // OG image cards (Phase 5 chunk 4)
            OgImageGenerator::class    => static fn () => new OgImageGenerator(),
            OgImageController::class   => static fn (DIContainer $c) => new OgImageController(
                $c->get(OgImageGenerator::class),
                $c->get(Connection::class),
                $rootDir . '/storage/cache/og',
            ),

            // Sitemap (Phase 5 chunk 5)
            SitemapBuilder::class      => static fn (DIContainer $c) => new SitemapBuilder(
                db:        $c->get(Connection::class),
                publicDir: $rootDir . '/public',
                baseUrl:   $c->get('app.url'),
            ),
            TextRenderer::class        => static fn (DIContainer $c) => new TextRenderer($rootDir, $c->get('app.url')),

            // Events feed (Phase 4 chunk 6)
            EventsAggregator::class    => static fn (DIContainer $c) => new EventsAggregator($c->get(Connection::class)),
            AtomGenerator::class       => static fn () => new AtomGenerator(),
            AtomEventsController::class => static fn (DIContainer $c) => new AtomEventsController(
                $c->get(EventsAggregator::class),
                $c->get(AtomGenerator::class),
            ),
            TextEventsController::class => static fn (DIContainer $c) => new TextEventsController(
                $c->get(EventsAggregator::class),
                $c->get(TextRenderer::class),
            ),

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
            SatelliteRadioController::class  => static fn (DIContainer $c) => new SatelliteRadioController($c->get(Connection::class)),
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

            // Reentry endpoints (Phase 2 chunk 4)
            ReentryListController::class      => static fn (DIContainer $c) => new ReentryListController($c->get(Connection::class)),
            ReentryDetailController::class    => static fn (DIContainer $c) => new ReentryDetailController($c->get(Connection::class)),

            // Conjunction endpoints (Phase 4 chunk 2)
            ConjunctionListController::class   => static fn (DIContainer $c) => new ConjunctionListController($c->get(Connection::class)),
            ConjunctionDetailController::class => static fn (DIContainer $c) => new ConjunctionDetailController($c->get(Connection::class)),

            // Space weather endpoints (Phase 4 chunk 3)
            SpaceWeatherNowController::class   => static fn (DIContainer $c) => new SpaceWeatherNowController($c->get(Connection::class)),
            SpaceWeather24hController::class   => static fn (DIContainer $c) => new SpaceWeather24hController($c->get(Connection::class)),

            // Stats dashboard (Phase 4 chunk 5)
            StatsController::class             => static fn (DIContainer $c) => new StatsController($c->get(Connection::class)),

            // Pass predictions (Phase 2 chunk 6 + Phase 4 chunk 7B magnitude enrichment)
            N2YOClient::class                => static fn (DIContainer $c) => new N2YOClient(
                http:          $c->get(GuzzleClient::class),
                apiKey:        EnvLoader::get('N2YO_API_KEY', '') ?? '',
                stateFilePath: $rootDir . '/storage/cache/n2yo-quota.json',
                logger:        $c->get(LoggerInterface::class),
            ),
            PassMagnitudeEnricher::class      => static fn (DIContainer $c) => new PassMagnitudeEnricher(
                n2yo:   $c->get(N2YOClient::class),
                logger: $c->get(LoggerInterface::class),
            ),
            SatellitePassesController::class  => static fn (DIContainer $c) => new SatellitePassesController(
                $c->get(Connection::class),
                $c->get(PassCalculatorInterface::class),
                $c->get(PassCache::class),
                $c->get(PassMagnitudeEnricher::class),
            ),

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

            // SOCRATES (Phase 4 chunk 1)
            SocratesClient::class    => static fn (DIContainer $c) => new SocratesClient($c->get(GuzzleClient::class)),
            SocratesCsvParser::class => static fn () => new SocratesCsvParser(),
            SocratesIngester::class  => static fn (DIContainer $c) => new SocratesIngester(
                client: $c->get(SocratesClient::class),
                parser: $c->get(SocratesCsvParser::class),
                db:     $c->get(Connection::class),
                logger: $c->get(LoggerInterface::class),
            ),

            // SatNOGS amateur-radio enrichment (Phase 5 chunk 1)
            SatnogsClient::class    => static fn (DIContainer $c) => new SatnogsClient($c->get(GuzzleClient::class)),
            SatnogsIngester::class  => static fn (DIContainer $c) => new SatnogsIngester(
                client: $c->get(SatnogsClient::class),
                db:     $c->get(Connection::class),
                logger: $c->get(LoggerInterface::class),
            ),

            // SWPC space weather (Phase 4 chunk 3)
            SwpcClient::class   => static fn (DIContainer $c) => new SwpcClient($c->get(GuzzleClient::class)),
            SwpcIngester::class => static fn (DIContainer $c) => new SwpcIngester(
                client: $c->get(SwpcClient::class),
                db:     $c->get(Connection::class),
                logger: $c->get(LoggerInterface::class),
            ),

            // OVATION aurora overlay (Phase 4 chunk 4)
            OvationClient::class          => static fn (DIContainer $c) => new OvationClient($c->get(GuzzleClient::class)),
            AuroraRasterGenerator::class  => static fn () => new AuroraRasterGenerator(),
            OvationIngester::class        => static fn (DIContainer $c) => new OvationIngester(
                client:         $c->get(OvationClient::class),
                generator:      $c->get(AuroraRasterGenerator::class),
                outputPng:      $rootDir . '/public/textures/aurora-latest.png',
                outputMetaJson: $rootDir . '/public/textures/aurora-latest.json',
                logger:         $c->get(LoggerInterface::class),
            ),

            // Pass prediction (Phase 2 chunk 6)
            PassCache::class      => static fn (DIContainer $c) => new PassCache($c->get(Connection::class)),
            PassCalculator::class => static fn (DIContainer $c) => new PassCalculator(
                scriptPath: $rootDir . '/bin/sgp4-passes.mjs',
                nodeBinary: EnvLoader::get('NODE_BINARY', 'node') ?? 'node',
                logger:     $c->get(LoggerInterface::class),
            ),
            PassCalculatorInterface::class => static fn (DIContainer $c) => $c->get(PassCalculator::class),

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
            IngestSocratesCommand::class  => static fn (DIContainer $c) => new IngestSocratesCommand(
                $c->get(SocratesIngester::class),
                $c->get(Connection::class),
            ),
            IngestSatnogsCommand::class   => static fn (DIContainer $c) => new IngestSatnogsCommand(
                $c->get(SatnogsIngester::class),
                $c->get(Connection::class),
            ),
            IngestOvationCommand::class   => static fn (DIContainer $c) => new IngestOvationCommand(
                $c->get(OvationIngester::class),
            ),
            IngestSwpcCommand::class      => static fn (DIContainer $c) => new IngestSwpcCommand(
                $c->get(SwpcIngester::class),
                $c->get(Connection::class),
            ),
            PruneCacheCommand::class      => static fn (DIContainer $c) => new PruneCacheCommand(
                $c->get(PassCache::class),
            ),
            HealthCommand::class          => static fn (DIContainer $c) => new HealthCommand(
                $c->get(Connection::class),
                $c->get(Migrator::class),
            ),
            OpenApiDumpCommand::class     => static fn (DIContainer $c) => new OpenApiDumpCommand(
                $c->get(OpenApiGenerator::class),
                $rootDir,
            ),
            SitemapBuildCommand::class    => static fn (DIContainer $c) => new SitemapBuildCommand(
                $c->get(SitemapBuilder::class),
            ),
        ]);

        return $builder->build();
    }
}
