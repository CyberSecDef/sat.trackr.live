<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/v1/docs — renders Swagger UI against /api/v1/openapi.json.
 *
 * Mounted via jsDelivr (locked to a major version) so we don't have to
 * vendor ~1 MB of Swagger UI dist into the repo.  If the CDN is ever
 * unreachable, the page still renders the URL of the spec as a fallback
 * — clients can paste it into any other OpenAPI viewer.
 */
final class SwaggerUiController
{
    /** @param array<string, string> $args */
    public function __invoke(Request $request, Response $response, array $args = []): Response
    {
        $html = <<<'HTML'
        <!DOCTYPE html>
        <html lang="en">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <meta name="theme-color" content="#0a0e27">
          <title>sat.trackr.live — API docs</title>
          <link rel="icon" type="image/svg+xml" href="/favicon.svg">
          <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
          <style>
            body { margin: 0; background: #0a0e27; }
            .topbar { background: #0a0e27; padding: 0.5rem 1rem; border-bottom: 1px solid #1f2950; }
            .topbar a { color: #00d9ff; font-family: 'JetBrains Mono', ui-monospace, monospace; text-decoration: none; }
            #swagger-ui { background: white; }
            #fallback { display: none; padding: 2rem; color: #e0e6f0; font-family: system-ui, sans-serif; }
            #fallback a { color: #00d9ff; }
            .swagger-ui-loading-failed #swagger-ui { display: none; }
            .swagger-ui-loading-failed #fallback { display: block; }
          </style>
        </head>
        <body>
          <div class="topbar">
            <a href="/">← sat.trackr.live</a>
            &middot; <a href="/api/v1/openapi.json" target="_blank">/api/v1/openapi.json</a>
          </div>
          <div id="swagger-ui"></div>
          <div id="fallback">
            <h1>Swagger UI failed to load</h1>
            <p>The CDN-hosted Swagger UI script didn't load. The spec itself is still available at
              <a href="/api/v1/openapi.json">/api/v1/openapi.json</a> — paste that URL into any
              other OpenAPI viewer.</p>
          </div>
          <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"
                  onerror="document.body.classList.add('swagger-ui-loading-failed');"></script>
          <script>
            window.addEventListener('load', function () {
              if (typeof SwaggerUIBundle === 'undefined') {
                document.body.classList.add('swagger-ui-loading-failed');
                return;
              }
              SwaggerUIBundle({
                url: '/api/v1/openapi.json',
                dom_id: '#swagger-ui',
                deepLinking: true,
                docExpansion: 'list',
                defaultModelsExpandDepth: 0,
                tryItOutEnabled: true,
              });
            });
          </script>
        </body>
        </html>
        HTML;

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
