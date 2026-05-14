<?php

declare(strict_types=1);

namespace SatTrackr\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Computes a weak ETag from the response body and emits 304 Not Modified
 * when the client's If-None-Match matches. Skips non-2xx responses and
 * mutating methods (POST/PUT/PATCH/DELETE).
 */
final class ETagMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $response = $handler->handle($request);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return $response;
        }

        $method = strtoupper($request->getMethod());
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return $response;
        }

        $body = (string) $response->getBody();
        $etag = 'W/"' . sha1($body) . '"';
        $response = $response->withHeader('ETag', $etag);

        $ifNoneMatch = $request->getHeaderLine('If-None-Match');
        if ($ifNoneMatch !== '' && $this->matches($ifNoneMatch, $etag)) {
            return (new ResponseFactory())->createResponse(304)
                ->withHeader('ETag', $etag);
        }

        return $response;
    }

    /**
     * Weak comparison per RFC 7232 §2.3.2: strip W/ prefix on both sides
     * before comparing. Supports comma-separated list of tags and "*".
     */
    private function matches(string $ifNoneMatch, string $etag): bool
    {
        $ifNoneMatch = trim($ifNoneMatch);
        if ($ifNoneMatch === '*') {
            return true;
        }

        $etagBare = (string) preg_replace('/^W\//', '', $etag);
        foreach (explode(',', $ifNoneMatch) as $tag) {
            $tag = trim($tag);
            $tagBare = (string) preg_replace('/^W\//', '', $tag);
            if ($tagBare === $etagBare) {
                return true;
            }
        }
        return false;
    }
}
