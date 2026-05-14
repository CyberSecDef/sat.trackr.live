<?php

declare(strict_types=1);

namespace SatTrackr\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Open CORS for the public API per req_spec §6 ("CORS open").
 * Handles OPTIONS preflight without dispatching to a controller.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            $response = (new ResponseFactory())->createResponse(204);
            return $this->withCors($response);
        }
        return $this->withCors($handler->handle($request));
    }

    private function withCors(Response $response): Response
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, OPTIONS, HEAD')
            ->withHeader('Access-Control-Allow-Headers', 'Accept, Content-Type, If-None-Match')
            ->withHeader('Access-Control-Expose-Headers', 'ETag, Cache-Control, Content-Length')
            ->withHeader('Access-Control-Max-Age', '86400');
    }
}
