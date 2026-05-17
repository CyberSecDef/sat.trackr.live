<?php

declare(strict_types=1);

namespace SatTrackr\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SatTrackr\Database\Connection;
use SatTrackr\Database\Migrator;
use SatTrackr\Services\SitemapBuilder;

final class SitemapBuilderTest extends TestCase
{
    private string $tempDb = '';
    private string $tempPublic = '';
    private Connection $db;

    protected function setUp(): void
    {
        $this->tempDb = tempnam(sys_get_temp_dir(), 'sitemap-') . '.db';
        if (file_exists($this->tempDb)) unlink($this->tempDb);
        $this->tempPublic = sys_get_temp_dir() . '/sitemap-out-' . uniqid();
        $this->db = new Connection($this->tempDb);
        (new Migrator($this->db, dirname(__DIR__, 3) . '/migrations'))->migrate();
    }

    protected function tearDown(): void
    {
        unset($this->db);
        foreach ([$this->tempDb, $this->tempDb . '-wal', $this->tempDb . '-shm'] as $f) {
            if (file_exists($f)) @unlink($f);
        }
        if (is_dir($this->tempPublic)) {
            foreach (glob("{$this->tempPublic}/*") ?: [] as $f) @unlink($f);
            @rmdir($this->tempPublic);
        }
    }

    private function builder(string $baseUrl = 'https://sat.trackr.live'): SitemapBuilder
    {
        return new SitemapBuilder($this->db, $this->tempPublic, $baseUrl);
    }

    /** @param int $count */
    private function seedSatellites(int $count): void
    {
        $now = '2026-05-16T00:00:00Z';
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "INSERT INTO satellites (norad_id, intl_designator, name, created_at, updated_at)
             VALUES (:n, :i, :name, '{$now}', '{$now}')"
        );
        for ($i = 1; $i <= $count; $i++) {
            $stmt->execute(['n' => $i, 'i' => sprintf('20%02d-001A', $i % 99), 'name' => "Sat-{$i}"]);
        }
        $pdo->commit();
    }

    public function testBuildWithEmptyCatalogStillEmitsStaticRoutes(): void
    {
        $report = $this->builder()->build();
        $this->assertSame(1, $report['chunks']);
        $this->assertGreaterThanOrEqual(10, $report['urls']);
        $this->assertFileExists($this->tempPublic . '/sitemap.xml');
        $this->assertFileExists($this->tempPublic . '/sitemap-1.xml');
    }

    public function testIndexAndChunksUseProperNamespaceAndStructure(): void
    {
        $this->seedSatellites(50);
        $this->builder()->build();

        $idx = simplexml_load_file($this->tempPublic . '/sitemap.xml');
        $this->assertNotFalse($idx);
        $this->assertSame('sitemapindex', $idx->getName());
        $ns = $idx->getNamespaces();
        $this->assertSame(SitemapBuilder::NS, $ns['']);
        $this->assertGreaterThanOrEqual(1, count($idx->sitemap));

        $chunk = simplexml_load_file($this->tempPublic . '/sitemap-1.xml');
        $this->assertNotFalse($chunk);
        $this->assertSame('urlset', $chunk->getName());
        $this->assertGreaterThan(0, count($chunk->url));
        // First URL should be the homepage with priority 1.0
        $this->assertSame('https://sat.trackr.live/', (string) $chunk->url[0]->loc);
        $this->assertSame('1.0',                       (string) $chunk->url[0]->priority);
    }

    public function testChunkingKicksInAt10kUrls(): void
    {
        // Need >10K-static-routes (10) satellites to force a second chunk.
        $this->seedSatellites(SitemapBuilder::URLS_PER_CHUNK + 5);
        $report = $this->builder()->build();
        $this->assertGreaterThanOrEqual(2, $report['chunks']);
        $this->assertFileExists($this->tempPublic . '/sitemap-2.xml');

        $idx = simplexml_load_file($this->tempPublic . '/sitemap.xml');
        $this->assertNotFalse($idx);
        $this->assertSame($report['chunks'], count($idx->sitemap));
    }

    public function testSatellitesAndLaunchesAppearInOutput(): void
    {
        $this->seedSatellites(3);
        $now = '2026-05-16T00:00:00Z';
        $this->db->pdo()->exec("INSERT INTO launches (id, name, net, status, updated_at)
            VALUES ('abc-123', 'Starship IFT-9', '2026-06-01T00:00:00Z', 'TBD', '{$now}')");

        $this->builder()->build();
        $body = (string) file_get_contents($this->tempPublic . '/sitemap-1.xml');
        $this->assertStringContainsString('https://sat.trackr.live/text/satellite/1',      $body);
        $this->assertStringContainsString('https://sat.trackr.live/text/satellite/3',      $body);
        $this->assertStringContainsString('https://sat.trackr.live/text/launches/abc-123', $body);
    }

    public function testBaseUrlPathIsRespectedAndDoesNotDoubleSlash(): void
    {
        $report = (new SitemapBuilder($this->db, $this->tempPublic, 'https://example.com/'))->build();
        $body = (string) file_get_contents($this->tempPublic . '/sitemap-1.xml');
        $this->assertStringContainsString('https://example.com/text', $body);
        $this->assertStringNotContainsString('https://example.com//', $body);
        $this->assertGreaterThan(0, $report['urls']);
    }
}
