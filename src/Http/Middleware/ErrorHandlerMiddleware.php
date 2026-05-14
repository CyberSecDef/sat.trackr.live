<?php

declare(strict_types=1);

namespace SatTrackr\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use SatTrackr\App\EnvLoader;
use Slim\Psr7\Factory\ResponseFactory;
use Throwable;

/**
 * Catches all uncaught throwables, logs them, and returns a clean
 * error page (HTML in dev with stack trace, plain text in prod).
 */
final class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), [
                'exception' => $e::class,
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'trace'     => $e->getTraceAsString(),
            ]);

            $response = (new ResponseFactory())->createResponse(500);
            $body = EnvLoader::isDev()
                ? $this->renderDevError($e)
                : 'Internal Server Error';
            $response->getBody()->write($body);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }
    }

    private function renderDevError(Throwable $e): string
    {
        $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES);
        $cls = htmlspecialchars($e::class, ENT_QUOTES);
        $file = htmlspecialchars($e->getFile(), ENT_QUOTES);
        $line = $e->getLine();
        $trace = htmlspecialchars($e->getTraceAsString(), ENT_QUOTES);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
              <meta charset="utf-8">
              <title>Error — sat.trackr.live</title>
              <style>
                body { background: #0a0e27; color: #e0e6f0; font-family: 'JetBrains Mono', ui-monospace, monospace; padding: 2rem; line-height: 1.5; }
                h1 { color: #ff3860; margin: 0 0 0.5rem; font-family: 'Inter', system-ui, sans-serif; }
                .where { color: #00d9ff; }
                pre { background: #1a1e3a; padding: 1rem; border-radius: 4px; overflow: auto; font-size: 0.85rem; }
              </style>
            </head>
            <body>
              <h1>{$cls}</h1>
              <p>{$msg}</p>
              <p>at <span class="where">{$file}:{$line}</span></p>
              <pre>{$trace}</pre>
            </body>
            </html>
            HTML;
    }
}
