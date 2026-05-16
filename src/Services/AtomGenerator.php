<?php

declare(strict_types=1);

namespace SatTrackr\Services;

/**
 * Phase 4 chunk 6C — emits valid Atom 1.0 XML from the unified
 * event list produced by {@see EventsAggregator}.
 *
 * Spec: https://datatracker.ietf.org/doc/html/rfc4287
 *
 * Each `<entry>` carries:
 *   <id>                       stable URN built from the event's kind:id
 *   <title>                    plain-text headline
 *   <published>+<updated>      both set to the event's timestamp (no
 *                              edit log; updated = published is OK
 *                              for an aggregated feed)
 *   <summary>                  short body (no markup)
 *   <category term="kind">     one of launch / reentry / conjunction / storm
 *   <link rel="alternate" href="…">  deep-link back to the SPA / text view
 *
 * Feed-level metadata is set by the caller (title, base URL, self URL,
 * updated timestamp).  Output is intentionally not pretty-printed —
 * Atom readers don't care, and one-line entries are easier to
 * regression-test with a fixture diff.
 */
final class AtomGenerator
{
    /**
     * @param list<array{
     *   id: string, kind: string, timestamp: string,
     *   title: string, summary: string, link: string,
     * }> $events
     */
    public function build(array $events, string $feedTitle, string $baseUrl, string $selfUrl, string $updated): string
    {
        $feedId = self::urn('feed', $selfUrl);

        $xml  = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $xml .= '<feed xmlns="http://www.w3.org/2005/Atom">' . "\n";
        $xml .= '  <id>' . self::esc($feedId) . '</id>' . "\n";
        $xml .= '  <title>' . self::esc($feedTitle) . '</title>' . "\n";
        $xml .= '  <updated>' . self::esc($updated) . '</updated>' . "\n";
        $xml .= '  <link rel="self" href="' . self::esc($selfUrl) . '" type="application/atom+xml"/>' . "\n";
        $xml .= '  <link rel="alternate" href="' . self::esc($baseUrl) . '/text/events" type="text/html"/>' . "\n";
        $xml .= '  <generator uri="' . self::esc($baseUrl) . '">sat.trackr.live events aggregator</generator>' . "\n";

        foreach ($events as $e) {
            $entryUrl = self::abs($baseUrl, $e['link']);
            $entryId  = self::urn('event', $e['id']);

            $xml .= '  <entry>' . "\n";
            $xml .= '    <id>' . self::esc($entryId) . '</id>' . "\n";
            $xml .= '    <title>' . self::esc($e['title']) . '</title>' . "\n";
            $xml .= '    <link rel="alternate" href="' . self::esc($entryUrl) . '" type="text/html"/>' . "\n";
            $xml .= '    <published>' . self::esc($e['timestamp']) . '</published>' . "\n";
            $xml .= '    <updated>'   . self::esc($e['timestamp']) . '</updated>' . "\n";
            $xml .= '    <category term="' . self::esc($e['kind']) . '"/>' . "\n";
            if ($e['summary'] !== '') {
                $xml .= '    <summary type="text">' . self::esc($e['summary']) . '</summary>' . "\n";
            }
            $xml .= '  </entry>' . "\n";
        }

        $xml .= '</feed>' . "\n";
        return $xml;
    }

    private static function urn(string $kind, string $key): string
    {
        return 'urn:sat.trackr.live:' . $kind . ':' . $key;
    }

    private static function abs(string $baseUrl, string $relativeOrAbs): string
    {
        if (preg_match('@^[a-z]+://@i', $relativeOrAbs) === 1) {
            return $relativeOrAbs;
        }
        return rtrim($baseUrl, '/') . '/' . ltrim($relativeOrAbs, '/');
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
