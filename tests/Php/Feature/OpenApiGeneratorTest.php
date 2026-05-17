<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SatTrackr\Services\OpenApiGenerator;

final class OpenApiGeneratorTest extends TestCase
{
    private function generator(): OpenApiGenerator
    {
        return new OpenApiGenerator(dirname(__DIR__, 3));
    }

    public function testGenerateProducesValidOpenApi31Skeleton(): void
    {
        $spec = $this->generator()->generate();

        $this->assertSame('3.1.0', $spec['openapi'] ?? null);
        $this->assertSame('sat.trackr.live API', $spec['info']['title'] ?? null);
        $this->assertSame('AGPL-3.0-or-later', $spec['info']['license']['identifier'] ?? null);
        $this->assertNotEmpty($spec['servers'] ?? []);
    }

    public function testSharedSchemasAreRegistered(): void
    {
        $spec = $this->generator()->generate();
        $schemas = $spec['components']['schemas'] ?? [];
        foreach ([
            'SatelliteSummary', 'SatelliteDetail', 'TleCurrent',
            'PaginationMeta', 'PaginationLinks', 'ErrorResponse',
            'GroupSummary', 'Launch', 'Conjunction', 'Pass',
            'RadioTransmitter',
        ] as $name) {
            $this->assertArrayHasKey($name, $schemas, "Missing schema: {$name}");
        }
    }

    public function testGenerateJsonRoundTrips(): void
    {
        $json = $this->generator()->generateJson();
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame('3.1.0', $decoded['openapi']);
    }

    public function testAllExpectedRoutesAreDocumented(): void
    {
        $paths = $this->generator()->generate()['paths'] ?? [];
        foreach ([
            '/api/v1/satellites',
            '/api/v1/satellites/{norad}',
            '/api/v1/satellites/{norad}/tle',
            '/api/v1/satellites/{norad}/passes',
            '/api/v1/satellites/{norad}/radio',
            '/api/v1/groups',
            '/api/v1/groups/{slug}',
            '/api/v1/groups/{slug}/tles',
            '/api/v1/search',
            '/api/v1/autocomplete',
            '/api/v1/launches/upcoming',
            '/api/v1/launches/recent',
            '/api/v1/launches/{id}',
            '/api/v1/launch-sites',
            '/api/v1/reentries/upcoming',
            '/api/v1/reentries/{norad}',
            '/api/v1/conjunctions/upcoming',
            '/api/v1/conjunctions/{primary}/{secondary}',
            '/api/v1/space-weather/now',
            '/api/v1/space-weather/24h',
            '/api/v1/stats/{breakdown}',
        ] as $path) {
            $this->assertArrayHasKey($path, $paths, "Missing path in spec: {$path}");
        }
    }

    public function testTagsAreDefinedAndReferenced(): void
    {
        $spec = $this->generator()->generate();
        $tagNames = array_map(static fn (array $t): string => (string) $t['name'], $spec['tags'] ?? []);
        foreach (['Catalog', 'Conjunctions', 'Radio', 'Stats', 'Launches', 'Space weather'] as $name) {
            $this->assertContains($name, $tagNames, "Missing tag in spec: {$name}");
        }
    }
}
