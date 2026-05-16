<?php

declare(strict_types=1);

namespace SatTrackr\Services;

use SatTrackr\Database\Connection;

/**
 * Phase 4 chunk 6A — pulls events from the four Phase-2/4 tables and
 * merges them into a unified time-ordered stream.  Used by:
 *
 *   /text/events    server-rendered list           (chunk 6B)
 *   /events.atom    syndicated feed for RSS readers (chunk 6C)
 *
 * Sources (all read-only, no new ingest):
 *   - launches.net               type=launch
 *   - reentries.predicted_decay  type=reentry
 *   - conjunctions               type=conjunction   (filtered: prob >= MIN_CONJUNCTION_PROB)
 *   - space_weather_samples      type=storm         (filtered: G>=2, S>=2, R>=2, or X-ray class >= M)
 *
 * Each `Event` is shaped consistently so the views don't have to
 * type-discriminate per kind beyond the optional badge.
 */
final class EventsAggregator
{
    /** Conjunctions below this collision probability don't make the feed. */
    public const MIN_CONJUNCTION_PROB = 1e-4;

    /**
     * Real production data has thousands of conjunctions above the 1e-4
     * threshold in a 7-day window (chunk-1 ingested 145k rows over 30 days).
     * Cap each kind so the Atom feed stays under ~50KB per the locked plan.
     */
    public const PER_KIND_LIMIT = 50;

    /** Storm thresholds — surface anything noteworthy on R/S/G or X-ray class. */
    private const NOTEWORTHY_STORM_LEVEL = 2;
    private const NOTEWORTHY_XRAY_CLASSES = ['M', 'X'];

    public function __construct(private readonly Connection $db) {}

    /**
     * Return all events whose timestamp lies in `[now - pastDays, now + futureDays]`,
     * sorted ascending by timestamp (oldest first; reverse at the caller for "newest").
     *
     * @return list<array{
     *   id: string,
     *   kind: 'launch'|'reentry'|'conjunction'|'storm',
     *   timestamp: string,
     *   title: string,
     *   summary: string,
     *   link: string,
     * }>
     */
    public function recent(int $pastDays = 7, int $futureDays = 7): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $from = $now->modify("-{$pastDays} days")->format('Y-m-d\TH:i:s\Z');
        $to   = $now->modify("+{$futureDays} days")->format('Y-m-d\TH:i:s\Z');

        $events = [
            ...$this->launches($from, $to),
            ...$this->reentries($from, $to),
            ...$this->conjunctions($from, $to),
            ...$this->storms($from, $to),
        ];

        usort($events, static fn ($a, $b) => $a['timestamp'] <=> $b['timestamp']);
        return $events;
    }

    /** @return list<array<string, string>> */
    private function launches(string $from, string $to): array
    {
        $rows = $this->db->capsule()->table('launches')
            ->where('net', '>=', $from)
            ->where('net', '<=', $to)
            ->orderBy('net', 'asc')
            ->limit(self::PER_KIND_LIMIT)
            ->select('id', 'name', 'net', 'status', 'mission_name', 'provider', 'vehicle')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $mission = $r->mission_name !== null && $r->mission_name !== '' ? $r->mission_name : $r->name;
            $parts = array_filter([$r->provider, $r->vehicle, $r->status]);
            $summary = implode(' · ', $parts);
            $out[] = [
                'id'        => 'launch:' . $r->id,
                'kind'      => 'launch',
                'timestamp' => (string) $r->net,
                'title'     => (string) $mission,
                'summary'   => $summary !== '' ? $summary : 'launch',
                'link'      => '/text/launches/' . $r->id,
            ];
        }
        return $out;
    }

    /** @return list<array<string, string>> */
    private function reentries(string $from, string $to): array
    {
        $rows = $this->db->capsule()->table('reentries as r')
            ->leftJoin('satellites as s', 's.norad_id', '=', 'r.norad_id')
            ->where('r.predicted_decay', '>=', $from)
            ->where('r.predicted_decay', '<=', $to)
            ->orderBy('r.predicted_decay', 'asc')
            ->limit(self::PER_KIND_LIMIT)
            ->select(
                'r.id', 'r.norad_id', 'r.predicted_decay', 'r.confidence_window_hours',
                'r.source',
                's.name as sat_name',
            )
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $name = $r->sat_name ?? "NORAD {$r->norad_id}";
            $window = $r->confidence_window_hours !== null
                ? sprintf('±%.1fh window', (float) $r->confidence_window_hours)
                : 'window unknown';
            $out[] = [
                'id'        => 'reentry:' . $r->id,
                'kind'      => 'reentry',
                'timestamp' => (string) $r->predicted_decay,
                'title'     => "Predicted reentry — {$name}",
                'summary'   => "Source: " . strtolower(str_replace('_', ' ', (string) $r->source)) . " · {$window}",
                'link'      => '/text/satellite/' . $r->norad_id,
            ];
        }
        return $out;
    }

    /** @return list<array<string, string>> */
    private function conjunctions(string $from, string $to): array
    {
        // Production data has thousands of conjunctions above the prob
        // threshold over a 7-day window.  Order by probability DESC so the
        // top-N cap surfaces the *most-risky* close approaches, not just
        // the soonest.  Caller then merges into the unified chronological
        // stream alongside the other kinds.
        $rows = $this->db->capsule()->table('conjunctions')
            ->where('tca', '>=', $from)
            ->where('tca', '<=', $to)
            ->where('max_probability', '>=', self::MIN_CONJUNCTION_PROB)
            ->orderBy('max_probability', 'desc')
            ->limit(self::PER_KIND_LIMIT)
            ->select(
                'id', 'tca',
                'norad_id_primary', 'name_primary',
                'norad_id_secondary', 'name_secondary',
                'tca_range_km', 'max_probability',
            )
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'        => 'conjunction:' . $r->id,
                'kind'      => 'conjunction',
                'timestamp' => (string) $r->tca,
                'title'     => "Close approach — {$r->name_primary} × {$r->name_secondary}",
                'summary'   => sprintf(
                    'Miss %.3f km · max prob %.2E',
                    (float) $r->tca_range_km,
                    (float) $r->max_probability,
                ),
                'link'      => "/api/v1/conjunctions/{$r->norad_id_primary}/{$r->norad_id_secondary}",
            ];
        }
        return $out;
    }

    /** @return list<array<string, string>> */
    private function storms(string $from, string $to): array
    {
        $rows = $this->db->capsule()->table('space_weather_samples')
            ->where('sampled_at', '>=', $from)
            ->where('sampled_at', '<=', $to)
            ->where(function ($q): void {
                $q->where('g_level', '>=', self::NOTEWORTHY_STORM_LEVEL)
                  ->orWhere('s_level', '>=', self::NOTEWORTHY_STORM_LEVEL)
                  ->orWhere('r_level', '>=', self::NOTEWORTHY_STORM_LEVEL)
                  ->orWhereIn('x_ray_class', self::NOTEWORTHY_XRAY_CLASSES);
            })
            ->orderBy('sampled_at', 'asc')
            ->limit(self::PER_KIND_LIMIT)
            ->select('id', 'sampled_at', 'kp', 'x_ray_class', 'r_level', 's_level', 'g_level')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $headline = [];
            if ((int) $r->g_level >= 2) $headline[] = "G{$r->g_level} geomagnetic storm";
            if ((int) $r->s_level >= 2) $headline[] = "S{$r->s_level} radiation storm";
            if ((int) $r->r_level >= 2) $headline[] = "R{$r->r_level} radio blackout";
            if (in_array($r->x_ray_class, self::NOTEWORTHY_XRAY_CLASSES, true)) {
                $headline[] = "{$r->x_ray_class}-class X-ray flare";
            }
            $title = $headline === [] ? 'Space-weather event' : implode(' · ', $headline);
            $out[] = [
                'id'        => 'storm:' . $r->id,
                'kind'      => 'storm',
                'timestamp' => (string) $r->sampled_at,
                'title'     => $title,
                'summary'   => sprintf(
                    'Kp %.2f · X-ray %s · R%d/S%d/G%d',
                    (float) ($r->kp ?? 0),
                    (string) ($r->x_ray_class ?? '—'),
                    (int) ($r->r_level ?? 0),
                    (int) ($r->s_level ?? 0),
                    (int) ($r->g_level ?? 0),
                ),
                'link'      => '/text/space-weather',
            ];
        }
        return $out;
    }
}
