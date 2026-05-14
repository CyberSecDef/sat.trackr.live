<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Services\ViteAssetResolver;

/**
 * Renders the SPA shell HTML for any route the SPA owns (/, /satellite/{norad}, ...).
 * The actual UI is hydrated by Lit components on the client.
 */
final class SpaShellController
{
    public function __construct(
        private readonly ViteAssetResolver $vite,
        private readonly string $rootDir,
        private readonly string $appName,
        private readonly string $appUrl,
        private readonly string $cesiumIonToken,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $vite = $this->vite;
        $appName = $this->appName;
        $appUrl = $this->appUrl;
        $tagline = 'Space situational awareness, _legible_';
        $cesiumIonToken = $this->cesiumIonToken;
        $selectedNorad = $args['norad'] ?? null;

        ob_start();
        require $this->rootDir . '/resources/views/shell.php';
        $html = (string) ob_get_clean();

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
