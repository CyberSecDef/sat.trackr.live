<?php

declare(strict_types=1);

namespace SatTrackr\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Sets default Content-Type + Cache-Control headers for API responses
 * unless the controller already set them. Per req_spec §6:
 * "Cache-Control: public, s-maxage=N, stale-while-revalidate=N*2"
 * (60s default for live data; controllers override per-route as needed).
 */
final class JsonResponseMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $response = $handler->handle($request);

        if (!$response->hasHeader('Content-Type')) {
            $response = $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
        if (!$response->hasHeader('Cache-Control')) {
            $response = $response->withHeader(
                'Cache-Control',
                'public, max-age=60, stale-while-revalidate=120'
            );
        }
        return $response;
    }
}
