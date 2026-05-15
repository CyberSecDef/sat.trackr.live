<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use stdClass;

/**
 * Shared row → JSON serializer for the chunk-4 reentry endpoints. Both
 * the list and detail endpoints join `reentries` against `satellites`
 * so the UI has the object name + key SATCAT fields without a second
 * round trip.
 */
final class ReentrySerializer
{
    /**
     * Used by the list endpoint. Includes the joined satellite name +
     * type so the SPA card has enough to render without another call.
     *
     * @return array<string, mixed>
     */
    public static function summary(stdClass $row): array
    {
        return [
            'id'                       => (int) $row->id,
            'norad_id'                 => (int) $row->norad_id,
            'name'                     => $row->satellite_name ?? null,
            'object_type'              => $row->object_type ?? null,
            'predicted_decay'          => $row->predicted_decay,
            'confidence_window_hours'  => isset($row->confidence_window_hours)
                ? (float) $row->confidence_window_hours
                : null,
            'risk_score'               => isset($row->risk_score) ? (float) $row->risk_score : null,
            'source'                   => $row->source,
            'updated_at'               => $row->updated_at,
        ];
    }

    /**
     * Used by the detail endpoint. Adds raw_message + a nested
     * `satellite` block with the broader catalog fields.
     *
     * @return array<string, mixed>
     */
    public static function detail(stdClass $row): array
    {
        $raw = null;
        if (!empty($row->raw_message)) {
            $decoded = json_decode((string) $row->raw_message, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }

        return [
            'id'                       => (int) $row->id,
            'norad_id'                 => (int) $row->norad_id,
            'predicted_decay'          => $row->predicted_decay,
            'confidence_window_hours'  => isset($row->confidence_window_hours)
                ? (float) $row->confidence_window_hours
                : null,
            'risk_score'               => isset($row->risk_score) ? (float) $row->risk_score : null,
            'source'                   => $row->source,
            'raw_message'              => $raw,
            'created_at'               => $row->created_at,
            'updated_at'               => $row->updated_at,
            'satellite'                => self::satelliteBlock($row),
        ];
    }

    /**
     * @return array<string, mixed>|null  null when the join produced nothing
     */
    private static function satelliteBlock(stdClass $row): ?array
    {
        if (empty($row->satellite_name)) {
            return null;
        }
        return [
            'norad_id'        => (int) $row->norad_id,
            'name'            => $row->satellite_name,
            'intl_designator' => $row->intl_designator ?? null,
            'object_type'     => $row->object_type     ?? null,
            'country'         => $row->country         ?? null,
            'operator'        => $row->operator        ?? null,
            'launch_date'     => $row->launch_date     ?? null,
            'mass_kg'         => isset($row->mass_kg) ? (float) $row->mass_kg : null,
            'rcs_meters'      => isset($row->rcs_meters) ? (float) $row->rcs_meters : null,
            'status'          => $row->satellite_status ?? null,
        ];
    }
}
