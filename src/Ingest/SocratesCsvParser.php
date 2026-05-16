<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

/**
 * Phase 4 chunk 1B — parses CelesTrak SOCRATES `sort-minRange.csv` rows
 * into structured arrays the {@see SocratesIngester} can hand directly
 * to the `conjunctions` table.
 *
 * Header row (locked at chunk 1 design time):
 *   NORAD_CAT_ID_1, OBJECT_NAME_1, DSE_1,
 *   NORAD_CAT_ID_2, OBJECT_NAME_2, DSE_2,
 *   TCA, TCA_RANGE, TCA_RELATIVE_SPEED, MAX_PROB, DILUTION
 *
 * Object names carry trailing flags: `[+]` payload, `[-]` debris,
 * `[P]` rocket body.  We keep them in the stored name string so the
 * UI can render badges without re-deriving status.
 */
final class SocratesCsvParser
{
    private const EXPECTED_HEADER = [
        'NORAD_CAT_ID_1',
        'OBJECT_NAME_1',
        'DSE_1',
        'NORAD_CAT_ID_2',
        'OBJECT_NAME_2',
        'DSE_2',
        'TCA',
        'TCA_RANGE',
        'TCA_RELATIVE_SPEED',
        'MAX_PROB',
        'DILUTION',
    ];

    /**
     * @return list<array{
     *   norad_primary: int,
     *   name_primary: string,
     *   dse_primary: ?float,
     *   norad_secondary: int,
     *   name_secondary: string,
     *   dse_secondary: ?float,
     *   tca: string,
     *   tca_range_km: float,
     *   tca_relative_speed_km_s: ?float,
     *   max_probability: ?float,
     *   dilution: ?float
     * }>
     */
    public function parse(string $csv): array
    {
        $rows = $this->splitRows($csv);
        if (count($rows) === 0) {
            return [];
        }

        $header = str_getcsv($rows[0], ',', '"', '');
        if ($header !== self::EXPECTED_HEADER) {
            throw new InvalidTleException(
                'SOCRATES header mismatch — got: ' . implode(',', $header)
            );
        }

        $out = [];
        for ($i = 1; $i < count($rows); $i++) {
            $line = $rows[$i];
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line, ',', '"', '');
            if (count($cols) !== 11) {
                continue;     // malformed row — skip silently
            }

            $norad1 = self::intOrNull($cols[0]);
            $norad2 = self::intOrNull($cols[3]);
            $tca    = trim($cols[6]);
            $range  = self::floatOrNull($cols[7]);
            if ($norad1 === null || $norad2 === null || $tca === '' || $range === null) {
                continue;     // any of these missing → can't represent the conjunction
            }

            $out[] = [
                'norad_primary'           => $norad1,
                'name_primary'            => trim($cols[1]),
                'dse_primary'             => self::floatOrNull($cols[2]),
                'norad_secondary'         => $norad2,
                'name_secondary'          => trim($cols[4]),
                'dse_secondary'           => self::floatOrNull($cols[5]),
                'tca'                     => self::normalizeTca($tca),
                'tca_range_km'            => $range,
                'tca_relative_speed_km_s' => self::floatOrNull($cols[8]),
                'max_probability'         => self::floatOrNull($cols[9]),
                'dilution'                => self::floatOrNull($cols[10]),
            ];
        }
        return $out;
    }

    /** @return list<string> */
    private function splitRows(string $csv): array
    {
        // CelesTrak emits CRLF; tolerate LF + mixed for robustness.
        $rows = preg_split('/\r\n|\r|\n/', $csv) ?: [];
        // Trim a trailing empty entry that always shows up when the
        // file ends with a newline.
        if (count($rows) > 0 && $rows[count($rows) - 1] === '') {
            array_pop($rows);
        }
        /** @var list<string> $rows */
        return $rows;
    }

    /** "2026-05-19 17:34:05.231" → "2026-05-19T17:34:05.231Z" (UTC). */
    private static function normalizeTca(string $tca): string
    {
        $tca = trim($tca);
        if (str_ends_with($tca, 'Z')) {
            return $tca;
        }
        return str_replace(' ', 'T', $tca) . 'Z';
    }

    private static function intOrNull(string $v): ?int
    {
        $v = trim($v);
        return $v === '' || !ctype_digit($v) ? null : (int) $v;
    }

    private static function floatOrNull(string $v): ?float
    {
        $v = trim($v);
        if ($v === '' || !is_numeric($v)) {
            return null;
        }
        return (float) $v;
    }
}
