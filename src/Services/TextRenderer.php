<?php

declare(strict_types=1);

namespace SatTrackr\Services;

/**
 * Renders the chunk-8 text catalog pages by extracting variables into the
 * scope of resources/views/text/layout.php. Same shape as SpaShellController's
 * approach — keeps templates as plain PHP files (no extra templating dep).
 */
final class TextRenderer
{
    public function __construct(
        private readonly string $rootDir,
    ) {
    }

    public function renderPage(
        string $title,
        string $body,
        string $activeNav = '',
        string $description = '',
    ): string {
        ob_start();
        require $this->rootDir . '/resources/views/text/layout.php';
        return (string) ob_get_clean();
    }

    /**
     * Render an inner template (e.g. text/list.php) with the given variables
     * extracted into its scope. Returns the captured HTML.
     *
     * @param array<string, mixed> $vars
     */
    public function renderInner(string $template, array $vars): string
    {
        $path = $this->rootDir . '/resources/views/text/' . $template;
        if (!is_file($path)) {
            throw new \RuntimeException("Template not found: {$template}");
        }
        // Extract vars into local scope before requiring.
        extract($vars, EXTR_SKIP);
        ob_start();
        require $path;
        return (string) ob_get_clean();
    }
}
