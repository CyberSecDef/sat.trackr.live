<?php

declare(strict_types=1);

namespace SatTrackr\Services;

use SatTrackr\Database\Connection;

/**
 * Phase 5 chunk 5A — emits a sitemap-index.xml + N chunked sitemap-{n}.xml
 * files, 10K URLs each (the sitemaps.org spec cap is 50K, but 10K keeps
 * single chunks well under the 50 MB uncompressed file limit even with
 * future Alpha-5 NORAD growth).
 *
 * URL inventory (in priority order):
 *   - Static text routes (catalog landing, groups, events, etc.)
 *   - Every /text/satellite/{norad}      — from the satellites table
 *   - Every /text/launches/{id}          — from the launches table
 *
 * Generation is one-pass and streamy — we don't hold all 15K+ URLs in
 * memory simultaneously, but we do batch by chunk before flushing.
 *
 * Validates against the standard sitemaps.org schema: each chunk is a
 * <urlset xmlns="…0.9"> containing <url><loc>…</loc><lastmod>…</lastmod></url>
 * entries; the index is a <sitemapindex> with one <sitemap><loc>…</loc>
 * per chunk file.
 */
final class SitemapBuilder
{
    public const URLS_PER_CHUNK = 10_000;
    public const NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /** @var list<array{path: string, changefreq: string, priority: string}> */
    private const STATIC_ROUTES = [
        ['path' => '/',                       'changefreq' => 'daily',  'priority' => '1.0'],
        ['path' => '/text',                   'changefreq' => 'daily',  'priority' => '0.9'],
        ['path' => '/text/groups',            'changefreq' => 'weekly', 'priority' => '0.8'],
        ['path' => '/text/events',            'changefreq' => 'hourly', 'priority' => '0.8'],
        ['path' => '/text/conjunctions',      'changefreq' => 'hourly', 'priority' => '0.8'],
        ['path' => '/text/space-weather',     'changefreq' => 'hourly', 'priority' => '0.7'],
        ['path' => '/text/decays',            'changefreq' => 'daily',  'priority' => '0.7'],
        ['path' => '/text/launches',          'changefreq' => 'daily',  'priority' => '0.7'],
        ['path' => '/text/stats',             'changefreq' => 'daily',  'priority' => '0.6'],
        ['path' => '/api/v1/docs',            'changefreq' => 'monthly','priority' => '0.5'],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly string $publicDir,
        private readonly string $baseUrl,
    ) {
    }

    /** @return array{chunks: int, urls: int, index: string} */
    public function build(): array
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        $base  = rtrim($this->baseUrl, '/');

        // Buffer URLs and flush per chunk to stay under per-file caps.
        $buffer    = [];
        $chunks    = [];
        $chunkIdx  = 1;
        $totalUrls = 0;

        $flush = function () use (&$buffer, &$chunks, &$chunkIdx): void {
            if ($buffer === []) {
                return;
            }
            $filename = "sitemap-{$chunkIdx}.xml";
            $this->writeChunk($filename, $buffer);
            $chunks[] = $filename;
            $chunkIdx++;
            $buffer = [];
        };

        foreach (self::STATIC_ROUTES as $r) {
            $buffer[] = [
                'loc'        => $base . $r['path'],
                'lastmod'    => $today,
                'changefreq' => $r['changefreq'],
                'priority'   => $r['priority'],
            ];
            $totalUrls++;
            if (count($buffer) >= self::URLS_PER_CHUNK) $flush();
        }

        // Satellites — chunked SELECT to keep PDO memory bounded.
        $satStmt = $this->db->pdo()->query(
            "SELECT norad_id, COALESCE(updated_at, '{$today}') AS updated_at FROM satellites ORDER BY norad_id"
        );
        if ($satStmt !== false) {
            while ($row = $satStmt->fetch(\PDO::FETCH_ASSOC)) {
                $buffer[] = [
                    'loc'        => $base . '/text/satellite/' . (int) $row['norad_id'],
                    'lastmod'    => $this->dateOnly((string) $row['updated_at']),
                    'changefreq' => 'daily',
                    'priority'   => '0.6',
                ];
                $totalUrls++;
                if (count($buffer) >= self::URLS_PER_CHUNK) $flush();
            }
        }

        $lnStmt = $this->db->pdo()->query(
            "SELECT id, COALESCE(updated_at, '{$today}') AS updated_at FROM launches ORDER BY net DESC"
        );
        if ($lnStmt !== false) {
            while ($row = $lnStmt->fetch(\PDO::FETCH_ASSOC)) {
                $buffer[] = [
                    'loc'        => $base . '/text/launches/' . rawurlencode((string) $row['id']),
                    'lastmod'    => $this->dateOnly((string) $row['updated_at']),
                    'changefreq' => 'weekly',
                    'priority'   => '0.5',
                ];
                $totalUrls++;
                if (count($buffer) >= self::URLS_PER_CHUNK) $flush();
            }
        }

        $flush();

        $indexPath = $this->writeIndex($chunks, $today);

        return [
            'chunks' => count($chunks),
            'urls'   => $totalUrls,
            'index'  => $indexPath,
        ];
    }

    /** @param list<array{loc: string, lastmod: string, changefreq: string, priority: string}> $urls */
    private function writeChunk(string $filename, array $urls): void
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', self::NS);
        foreach ($urls as $u) {
            $xml->startElement('url');
            $xml->writeElement('loc',        $u['loc']);
            $xml->writeElement('lastmod',    $u['lastmod']);
            $xml->writeElement('changefreq', $u['changefreq']);
            $xml->writeElement('priority',   $u['priority']);
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endDocument();

        $this->ensurePublicDir();
        file_put_contents($this->publicDir . '/' . $filename, $xml->outputMemory());
    }

    /** @param list<string> $chunks */
    private function writeIndex(array $chunks, string $today): string
    {
        $base = rtrim($this->baseUrl, '/');
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('sitemapindex');
        $xml->writeAttribute('xmlns', self::NS);
        foreach ($chunks as $chunk) {
            $xml->startElement('sitemap');
            $xml->writeElement('loc',     $base . '/' . $chunk);
            $xml->writeElement('lastmod', $today);
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endDocument();

        $this->ensurePublicDir();
        $path = $this->publicDir . '/sitemap.xml';
        file_put_contents($path, $xml->outputMemory());
        return $path;
    }

    private function ensurePublicDir(): void
    {
        if (!is_dir($this->publicDir) && !@mkdir($this->publicDir, 0775, true) && !is_dir($this->publicDir)) {
            throw new \RuntimeException("Could not create sitemap output dir: {$this->publicDir}");
        }
    }

    /** Sitemaps.org accepts full ISO 8601, but yyyy-mm-dd is the conventional form. */
    private function dateOnly(string $iso): string
    {
        return substr($iso, 0, 10);
    }
}
