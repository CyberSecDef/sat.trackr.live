<?php

declare(strict_types=1);

namespace SatTrackr\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use SatTrackr\App\EnvLoader;
use Slim\Exception\HttpException;
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
        } catch (HttpException $e) {
            // 4xx user errors (404 unknown route, 405 wrong method, etc.) —
            // clean response with the exception's own status code, no stack trace.
            return $this->renderHttpException($request, $e);
        } catch (Throwable $e) {
            // Unexpected (bug or 5xx) — log and render a dev stack trace or
            // prod-friendly placeholder.
            $this->logger->error($e->getMessage(), [
                'exception' => $e::class,
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'trace'     => $e->getTraceAsString(),
            ]);
            return $this->renderUnexpected($request, $e);
        }
    }

    private function renderHttpException(Request $request, HttpException $e): Response
    {
        $status = $e->getCode() ?: 500;
        $title = $e->getTitle() ?: 'Error';
        $message = $e->getMessage() ?: $e->getDescription() ?: $title;

        $response = (new ResponseFactory())->createResponse($status);

        if ($this->wantsJson($request)) {
            $body = json_encode([
                'error' => [
                    'code'    => self::statusSlug($status),
                    'message' => $message,
                    'status'  => $status,
                ],
            ], JSON_UNESCAPED_SLASHES) ?: '{}';
            $response->getBody()->write($body);
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $response->getBody()->write($this->renderHtmlError($status, $title, $message));
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function renderUnexpected(Request $request, Throwable $e): Response
    {
        $response = (new ResponseFactory())->createResponse(500);

        if ($this->wantsJson($request)) {
            $body = json_encode([
                'error' => [
                    'code'    => 'internal_error',
                    'message' => EnvLoader::isDev() ? $e->getMessage() : 'Internal Server Error',
                    'status'  => 500,
                ],
            ], JSON_UNESCAPED_SLASHES) ?: '{}';
            $response->getBody()->write($body);
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $body = EnvLoader::isDev()
            ? $this->renderDevError($e)
            : $this->renderHtmlError(500, '500 Internal Server Error', 'Something went wrong on our side.');
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function wantsJson(Request $request): bool
    {
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/api/')) {
            return true;
        }
        $accept = $request->getHeaderLine('Accept');
        return $accept !== '' && str_contains($accept, 'application/json');
    }

    private static function statusSlug(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthorized',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            410 => 'gone',
            418 => 'teapot',
            429 => 'too_many_requests',
            500 => 'internal_error',
            501 => 'not_implemented',
            502 => 'bad_gateway',
            503 => 'service_unavailable',
            default => 'http_' . $status,
        };
    }

    private function renderHtmlError(int $status, string $title, string $message): string
    {
        $title = htmlspecialchars($title, ENT_QUOTES);
        $message = htmlspecialchars($message, ENT_QUOTES);
        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
              <meta charset="utf-8">
              <title>{$title} — sat.trackr.live</title>
              <style>
                body { background: #0a0e27; color: #e0e6f0; font-family: 'Inter', system-ui, sans-serif; padding: 4rem 2rem; max-width: 640px; margin: 0 auto; line-height: 1.6; }
                h1 { color: #00d9ff; font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 1.5rem; margin: 0 0 0.5rem; }
                .status { color: #8a96b3; font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 0.85rem; }
                .message { margin-top: 1rem; }
                a { color: #00d9ff; }
              </style>
            </head>
            <body>
              <div class="status">HTTP {$status}</div>
              <h1>{$title}</h1>
              <p class="message">{$message}</p>
              <p><a href="/">← back to globe</a></p>
            </body>
            </html>
            HTML;
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
