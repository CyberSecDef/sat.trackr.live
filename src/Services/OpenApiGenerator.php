<?php

declare(strict_types=1);

namespace SatTrackr\Services;

use OpenApi\Generator;

/**
 * Phase 5 chunk 3 — runs swagger-php's reflection scan over the
 * `src/Http/` tree (controllers + Docs/) and serializes the merged
 * OpenAPI 3.1 spec.  Centralized so the runtime endpoint and the CLI
 * dumper share one codepath.
 */
final class OpenApiGenerator
{
    public function __construct(
        private readonly string $rootDir,
    ) {
    }

    /** @return array<string, mixed> */
    public function generate(): array
    {
        $spec = (new Generator())->generate([
            $this->rootDir . '/src/Http',
        ]);
        if ($spec === null) {
            throw new \RuntimeException('OpenAPI generation produced no spec — no #[OA\OpenApi] root found');
        }
        $json = $spec->toJson();
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return $decoded;
    }

    public function generateJson(int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES): string
    {
        $encoded = json_encode($this->generate(), $flags | JSON_THROW_ON_ERROR);
        return $encoded . "\n";
    }
}
