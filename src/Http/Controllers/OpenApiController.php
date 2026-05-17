<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SatTrackr\Services\OpenApiGenerator;

/**
 * GET /api/v1/openapi.json — serves the live OpenAPI 3.1 spec.
 *
 * Generated on-demand from controller attributes.  Cheap enough to
 * regenerate per-request in dev (~50 ms over 21 endpoints + 11 schemas);
 * the API middleware adds ETag + Cache-Control: public, max-age=300,
 * which is plenty to keep prod load trivial.
 */
final class OpenApiController
{
    public function __construct(
        private readonly OpenApiGenerator $generator,
    ) {
    }

    /** @param array<string, string> $args */
    public function __invoke(Request $request, Response $response, array $args = []): Response
    {
        $response->getBody()->write($this->generator->generateJson());
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
