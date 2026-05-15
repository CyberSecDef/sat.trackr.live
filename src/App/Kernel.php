<?php

declare(strict_types=1);

namespace SatTrackr\App;

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
use SatTrackr\Http\Controllers\Text\TextCatalogController;
use SatTrackr\Http\Controllers\Text\TextGroupController;
use SatTrackr\Http\Controllers\Text\TextGroupsController;
use SatTrackr\Http\Controllers\Text\TextLaunchDetailController;
use SatTrackr\Http\Controllers\Text\TextLaunchListController;
use SatTrackr\Http\Controllers\Text\TextSatelliteController;
use SatTrackr\Http\Controllers\Text\TextSearchController;
use SatTrackr\Http\Middleware\CorsMiddleware;
use SatTrackr\Http\Middleware\ErrorHandlerMiddleware;
use SatTrackr\Http\Middleware\ETagMiddleware;
use SatTrackr\Http\Middleware\JsonResponseMiddleware;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;

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
        // CORS at app level (outside RoutingMiddleware) so OPTIONS preflight
        // doesn't get intercepted by Slim's "method not allowed" check before
        // we have a chance to respond.
        $app->add($container->get(CorsMiddleware::class));

        self::registerRoutes($app);

        return $app;
    }

    private static function registerRoutes(App $app): void
    {
        // SPA routes — render the shell, client-side router takes over.
        $app->get('/', SpaShellController::class);
        $app->get('/satellite/{norad:[0-9]+}', SpaShellController::class);

        // Text-only catalog (chunk 8) — server-rendered HTML, no JSON middleware.
        // Per req_spec §24, this is the WebGL/no-JS fallback path.
        $app->get('/text', TextCatalogController::class);
        $app->get('/text/', TextCatalogController::class);
        $app->get('/text/satellite/{norad:[0-9]+}', TextSatelliteController::class);
        $app->get('/text/groups', TextGroupsController::class);
        $app->get('/text/groups/{slug:[a-zA-Z0-9_\-]+}', TextGroupController::class);
        $app->get('/text/search', TextSearchController::class);
        // Launches text views (Phase 2 chunk 3D) — order matters: /recent
        // before {id} so the literal beats the regex.
        $app->get('/text/launches', TextLaunchListController::class);
        $app->get('/text/launches/recent', TextLaunchListController::class);
        $app->get('/text/launches/{id:[a-fA-F0-9-]+}', TextLaunchDetailController::class);

        // API routes — Slim binds the group closure to its CallableResolver,
        // which requires a non-static closure (it can't bind $this to a static).
        $app->group('/api/v1', function (RouteCollectorProxy $api): void {
            $api->get('/satellites', SatelliteListController::class);
            $api->get('/satellites/{norad:[0-9]+}', SatelliteDetailController::class);
            $api->get('/satellites/{norad:[0-9]+}/tle', SatelliteTleController::class);
            $api->get('/groups', GroupListController::class);
            $api->get('/groups/{slug:[a-zA-Z0-9_\-]+}', GroupDetailController::class);
            $api->get('/groups/{slug:[a-zA-Z0-9_\-]+}/tles', GroupTlesController::class);
            $api->get('/search', SearchController::class);
            $api->get('/autocomplete', AutocompleteController::class);
            // Launches (Phase 2 chunk 3) — order matters: more specific
            // before less specific so {id} doesn't swallow /upcoming etc.
            $api->get('/launches/upcoming', UpcomingLaunchesController::class);
            $api->get('/launches/recent', RecentLaunchesController::class);
            $api->get('/launches/{id:[a-zA-Z0-9_\-]+}', LaunchDetailController::class);
            $api->get('/launch-sites', LaunchSiteListController::class);
        })
            ->add(JsonResponseMiddleware::class)
            ->add(ETagMiddleware::class);
        // CORS lives at the app level (above) — see comment in createApp().
    }
}
