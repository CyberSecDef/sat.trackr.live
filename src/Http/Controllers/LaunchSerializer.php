<?php

declare(strict_types=1);

namespace SatTrackr\Http\Controllers;

use SatTrackr\Support\Json;
use stdClass;

/**
 * Shared launch-row serializers for the launch JSON controllers.
 * Both the list endpoints (upcoming + recent) and the detail endpoint
 * consume rows joined against launch_sites; the difference is mostly
 * which fields they surface.
 */
final class LaunchSerializer
{
    /**
     * Slimmer shape for list endpoints — drops the long description
     * and image URL but keeps everything a card-style UI needs.
     *
     * @return array<string, mixed>
     */
    public static function summary(stdClass $row): array
    {
        return [
            'id'           => (string) $row->id,
            'name'         => $row->name,
            'net'          => $row->net,
            'status'       => $row->status,
            'provider'     => $row->provider,
            'vehicle'      => $row->vehicle,
            'mission_name' => $row->mission_name,
            'mission_type' => $row->mission_type,
            'orbit_target' => $row->orbit_target,
            'pad'          => self::padBlock($row),
            'webcast_url'  => $row->webcast_url,
        ];
    }

    /**
     * Full detail — adds description, image, customer, and the parsed
     * associated_norad_ids array.
     *
     * @return array<string, mixed>
     */
    public static function detail(stdClass $row): array
    {
        $associated = [];
        if (!empty($row->associated_norad_ids)) {
            try {
                $decoded = Json::decode((string) $row->associated_norad_ids);
                if (array_is_list($decoded)) {
                    $associated = array_map('intval', $decoded);
                }
            } catch (\Throwable) {
                // bad JSON in column — fall through with empty list
            }
        }

        return [
            'id'                   => (string) $row->id,
            'name'                 => $row->name,
            'net'                  => $row->net,
            'status'               => $row->status,
            'provider'             => $row->provider,
            'vehicle'              => $row->vehicle,
            'mission_name'         => $row->mission_name,
            'mission_type'         => $row->mission_type,
            'orbit_target'         => $row->orbit_target,
            'customer'             => $row->customer,
            'webcast_url'          => $row->webcast_url,
            'image_url'            => $row->image_url,
            'description'          => $row->description,
            'associated_norad_ids' => $associated,
            'pad'                  => self::padBlock($row),
            'updated_at'           => $row->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>|null  null when the launch has no pad joined
     */
    private static function padBlock(stdClass $row): ?array
    {
        if (empty($row->pad_id)) {
            return null;
        }
        $pad = [
            'id'        => (int) $row->pad_id,
            'name'      => $row->pad_name ?? null,
            'latitude'  => isset($row->pad_latitude)  ? (float) $row->pad_latitude  : null,
            'longitude' => isset($row->pad_longitude) ? (float) $row->pad_longitude : null,
            'country'   => $row->pad_country  ?? null,
            'operator'  => $row->pad_operator ?? null,
        ];
        if (isset($row->pad_url)) {
            $pad['url'] = $row->pad_url;
        }
        return $pad;
    }
}
