<?php

declare(strict_types=1);

namespace SatTrackr\Services;

use RuntimeException;

/**
 * Resolves Vite asset URLs for the SPA shell template.
 *
 * In dev mode (Vite dev server is running, sentinel hot-file present),
 * emits dev-server URLs with HMR client. In prod mode, reads the built
 * manifest.json and emits hashed asset paths.
 */
final class ViteAssetResolver
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $manifest = null;
    private ?bool $hotMode = null;

    public function __construct(
        private readonly string $rootDir,
        private readonly string $devOrigin,
    ) {
    }

    public function isHot(): bool
    {
        if ($this->hotMode === null) {
            $this->hotMode = file_exists($this->rootDir . '/public/build/.vite-hot');
        }
        return $this->hotMode;
    }

    /**
     * Generate the <script>/<link> tags for an entry point.
     */
    public function tagsForEntry(string $entry): string
    {
        return $this->isHot() ? $this->devTags($entry) : $this->prodTags($entry);
    }

    private function devTags(string $entry): string
    {
        $base = rtrim($this->devOrigin, '/');
        return implode("\n  ", [
            '<script type="module" src="' . $base . '/@vite/client"></script>',
            '<script type="module" src="' . $base . '/' . $entry . '"></script>',
        ]);
    }

    private function prodTags(string $entry): string
    {
        $manifest = $this->getManifest();
        if (!isset($manifest[$entry])) {
            throw new RuntimeException("Vite manifest missing entry '{$entry}'. Run `make build`.");
        }

        $entryData = $manifest[$entry];
        $tags = [];

        foreach ($entryData['css'] ?? [] as $cssFile) {
            $tags[] = '<link rel="stylesheet" href="/build/' . htmlspecialchars((string) $cssFile, ENT_QUOTES) . '">';
        }
        $tags[] = '<script type="module" src="/build/' . htmlspecialchars((string) $entryData['file'], ENT_QUOTES) . '"></script>';

        return implode("\n  ", $tags);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getManifest(): array
    {
        if ($this->manifest === null) {
            // Vite 5+ writes manifest at .vite/manifest.json; older at manifest.json.
            $candidates = [
                $this->rootDir . '/public/build/.vite/manifest.json',
                $this->rootDir . '/public/build/manifest.json',
            ];

            $manifestPath = null;
            foreach ($candidates as $candidate) {
                if (file_exists($candidate)) {
                    $manifestPath = $candidate;
                    break;
                }
            }

            if ($manifestPath === null) {
                throw new RuntimeException('Vite manifest not found. Run `make build` first.');
            }

            $contents = file_get_contents($manifestPath);
            if ($contents === false) {
                throw new RuntimeException('Could not read Vite manifest.');
            }
            $decoded = json_decode($contents, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Vite manifest is not valid JSON.');
            }
            /** @var array<string, array<string, mixed>> $decoded */
            $this->manifest = $decoded;
        }
        return $this->manifest;
    }
}
