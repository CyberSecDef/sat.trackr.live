<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use stdClass;

/**
 * Shared row → JSON serializer for the chunk-2 conjunction endpoints.
 * Both the list and detail endpoints join `conjunctions` against
 * `satellites` twice (once per object) so the SPA can render
 * object_type + country badges without a second round trip.
 */
final class ConjunctionSerializer
{
    /**
     * Slim shape for the list endpoint.
     * @return array<string, mixed>
     */
    public static function summary(stdClass $row): array
    {
        return [
            'id'                       => (int) $row->id,
            'tca'                      => $row->tca,
            'tca_range_km'             => (float) $row->tca_range_km,
            'tca_relative_speed_km_s'  => isset($row->tca_relative_speed_km_s)
                ? (float) $row->tca_relative_speed_km_s
                : null,
            'max_probability'          => isset($row->max_probability) ? (float) $row->max_probability : null,
            'dilution'                 => isset($row->dilution) ? (float) $row->dilution : null,
            'primary'                  => self::objectBlock(
                (int) $row->norad_id_primary,
                (string) $row->name_primary,
                isset($row->dse_primary) ? (float) $row->dse_primary : null,
                $row->primary_object_type ?? null,
                $row->primary_country ?? null,
            ),
            'secondary'                => self::objectBlock(
                (int) $row->norad_id_secondary,
                (string) $row->name_secondary,
                isset($row->dse_secondary) ? (float) $row->dse_secondary : null,
                $row->secondary_object_type ?? null,
                $row->secondary_country ?? null,
            ),
        ];
    }

    /**
     * Detail shape — same as summary plus source + updated_at.
     * @return array<string, mixed>
     */
    public static function detail(stdClass $row): array
    {
        return array_merge(self::summary($row), [
            'source'     => $row->source,
            'updated_at' => $row->updated_at,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function objectBlock(int $norad, string $name, ?float $dse, ?string $objectType, ?string $country): array
    {
        return [
            'norad_id'      => $norad,
            'name'          => $name,
            'dse_days'      => $dse,
            'object_type'   => $objectType,
            'country'       => $country,
        ];
    }
}
